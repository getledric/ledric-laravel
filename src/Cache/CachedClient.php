<?php

namespace Ledric\Laravel\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Ledric\Laravel\Client;
use Ledric\Laravel\Exceptions\LedricUnavailableException;
use Ledric\Laravel\Inline;

/**
 * Decorates `Client` with Laravel-cache-backed reads and write-through
 * invalidation.
 *
 * Reads use a two-window strategy:
 *
 *   - `ttl` is the *fresh* window. Within it, the cached value is
 *     served without touching ledric.
 *   - `stale_ttl` is the *outer* window. Past `ttl` we attempt a fetch;
 *     on `LedricUnavailableException` we fall back to the cached value
 *     if it's still inside `stale_ttl`. This is the "ledric is down,
 *     keep the site up" lever.
 *
 * Writes invalidate by tag where the cache store supports it (redis,
 * memcached). For file/database stores we fall back to a per-type
 * version stamp that's mixed into every cache key — bumping it makes
 * prior keys unreachable without us having to walk them.
 */
class CachedClient
{
    /** @var Client */
    protected $client;

    /** @var CacheRepository */
    protected $cache;

    /** @var array<string, mixed> */
    protected $config;

    /**
     * @var bool|null  When set, overrides the per-request preview-cookie
     *                 detection. `Ledric::preview()->find(...)` /
     *                 `Ledric::published()->find(...)` clone the instance
     *                 with this flag set so a single render can mix modes.
     */
    protected $forcedPreview;

    /**
     * @param  array<string, mixed>  $config  // ttl, stale_ttl, prefix, health_ttl
     */
    public function __construct(Client $client, CacheRepository $cache, array $config)
    {
        $this->client = $client;
        $this->cache  = $cache;
        $this->config = $config;
        $this->forcedPreview = null;
    }

    public function client(): Client
    {
        return $this->client;
    }

    // ─────────────────────────── preview mode ───────────────────────────

    /**
     * Force preview-mode reads on a cloned instance. Drops the
     * `published=true` filter so ledric returns the current (possibly
     * unpublished) version of each entry.
     *
     *   Ledric::preview()->find(['type' => 'post']);
     */
    public function preview(): self
    {
        $clone = clone $this;
        $clone->forcedPreview = true;
        return $clone;
    }

    /**
     * Force published-only reads on a cloned instance. Useful inside
     * an admin session — e.g. rendering the public sitemap while
     * the admin user is in preview mode for the rest of the page.
     */
    public function published(): self
    {
        $clone = clone $this;
        $clone->forcedPreview = false;
        return $clone;
    }

    public function isPreview(): bool
    {
        if ($this->forcedPreview !== null) {
            return $this->forcedPreview;
        }
        return Inline::previewActive();
    }

    // ─────────────────────────── reads ───────────────────────────

    public function read(string $type, string $slug, array $opts = []): ?array
    {
        $opts = $this->applyPublishedFilter($opts);
        return $this->getOrFetch(
            $this->key('read', $type, [$slug, $opts]),
            function () use ($type, $slug, $opts) {
                return $this->client->read($type, $slug, $opts);
            }
        );
    }

    public function find(array $args): array
    {
        $args = $this->applyPublishedFilter($args);
        $type = isset($args['type']) ? (string) $args['type'] : '_any';
        return $this->getOrFetch(
            $this->key('find', $type, $args),
            function () use ($args) {
                return $this->client->find($args);
            }
        );
    }

    public function searchEntries(array $args): array
    {
        $args = $this->applyPublishedFilter($args);
        $type = isset($args['type']) ? (string) $args['type'] : '_any';
        return $this->getOrFetch(
            $this->key('search', $type, $args),
            function () use ($args) {
                return $this->client->searchEntries($args);
            }
        );
    }

    public function listTypes(): array
    {
        return $this->getOrFetch(
            $this->key('list_types', '_meta', []),
            function () {
                return $this->client->listTypes();
            }
        );
    }

    public function describeModel(): array
    {
        return $this->getOrFetch(
            $this->key('describe_model', '_meta', []),
            function () {
                return $this->client->describeModel();
            }
        );
    }

    public function getAsset(string $id): ?array
    {
        return $this->getOrFetch(
            $this->key('asset_meta', '_asset', [$id]),
            function () use ($id) {
                return $this->client->getAsset($id);
            }
        );
    }

    // ─────────────────────────── writes ───────────────────────────

    public function draftEntry(string $type, array $fields, ?string $slug = null, array $opts = []): array
    {
        $r = $this->client->draftEntry($type, $fields, $slug, $opts);
        $this->invalidateType($type);
        return $r;
    }

    public function publishEntry(string $type, string $slug, ?int $version = null): array
    {
        $r = $this->client->publishEntry($type, $slug, $version);
        $this->invalidateType($type);
        return $r;
    }

    public function renameEntry(string $type, string $slug, string $newSlug, ?string $locale = null): array
    {
        $r = $this->client->renameEntry($type, $slug, $newSlug, $locale);
        $this->invalidateType($type);
        return $r;
    }

    public function deleteEntry(string $type, string $slug): array
    {
        $r = $this->client->deleteEntry($type, $slug);
        $this->invalidateType($type);
        return $r;
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function addEntryTags(string $type, string $slug, array $tags): array
    {
        $r = $this->client->addEntryTags($type, $slug, $tags);
        $this->invalidateType($type);
        return $r;
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function removeEntryTags(string $type, string $slug, array $tags): array
    {
        $r = $this->client->removeEntryTags($type, $slug, $tags);
        $this->invalidateType($type);
        return $r;
    }

    public function alterType(array $args): array
    {
        $r = $this->client->alterType($args);
        $this->invalidateType($args['name'] ?? ($args['type'] ?? null));
        $this->invalidateMeta();
        return $r;
    }

    /**
     * Manual invalidator. Useful when something outside Laravel mutated
     * ledric (another instance, a CLI run) and the operator wants to
     * refresh the cache without restarting.
     */
    public function flush(?string $type = null): void
    {
        if ($type === null) {
            $this->cache->flush();
            return;
        }
        $this->invalidateType($type);
    }

    // ─────────────────────────── health ───────────────────────────

    public function isHealthy(): bool
    {
        $key = $this->config['prefix'] . ':health';
        $ttl = (int) ($this->config['health_ttl'] ?? 5);

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return (bool) $cached;
        }
        $ok = $this->client->ping();
        $this->cache->put($key, $ok, $ttl);
        return $ok;
    }

    // ─────────────────────────── internals ───────────────────────────

    /**
     * @return mixed
     */
    protected function getOrFetch(string $key, callable $fetch)
    {
        $now   = time();
        $entry = $this->cache->get($key);

        if (is_array($entry) && isset($entry['fresh_until']) && $now < $entry['fresh_until']) {
            return $entry['data'] ?? null;
        }

        try {
            $data = $fetch();
        } catch (LedricUnavailableException $e) {
            // Stale-while-error: serve the prior value if we have one.
            if (is_array($entry) && array_key_exists('data', $entry)) {
                return $entry['data'];
            }
            throw $e;
        }

        $ttl      = (int) ($this->config['ttl'] ?? 300);
        $staleTtl = max((int) ($this->config['stale_ttl'] ?? 0), $ttl);

        $this->cache->put($key, [
            'data'        => $data,
            'fresh_until' => $now + $ttl,
        ], $staleTtl);

        return $data;
    }

    protected function key(string $tool, string $type, $args): string
    {
        // Mix the per-type version stamp into the key so an
        // invalidateType() bump effectively invalidates everything keyed
        // under that type without us walking the cache.
        $version = $this->versionFor($type);

        // Preview state is part of the key — a draft view of the same
        // args must not pollute the published cache entry, and vice
        // versa. Cheap bit added at the end so cached entries naturally
        // partition between the two modes.
        $previewBit = $this->isPreview() ? 'p1' : 'p0';

        return implode(':', [
            (string) $this->config['prefix'],
            $this->client->env(),
            $tool,
            $type,
            (string) $version,
            $previewBit,
            md5(json_encode($args)),
        ]);
    }

    /**
     * Default reads to `published=true` unless preview mode is active
     * or the caller has been explicit. ledric's default (no flag) is
     * "current version" which can include drafts — we want public
     * renders to be predictable.
     */
    protected function applyPublishedFilter(array $args): array
    {
        if (array_key_exists('published', $args)) {
            return $args; // caller has been explicit
        }
        if ($this->isPreview()) {
            return $args; // omit; ledric returns current/draft version
        }
        $args['published'] = true;
        return $args;
    }

    protected function invalidateType(?string $type): void
    {
        if ($type === null) {
            $this->invalidateMeta();
            return;
        }
        // Bumping the version stamp causes every key built via key() for
        // this type to miss on next lookup. Old entries occupy storage
        // until natural expiry but become unreachable.
        $this->cache->forget($this->versionKey($type));
        // Some queries (find without explicit type, list_types,
        // describe_model) live under '_meta' / '_any' — bump those too.
        $this->cache->forget($this->versionKey('_meta'));
        $this->cache->forget($this->versionKey('_any'));
    }

    protected function invalidateMeta(): void
    {
        $this->cache->forget($this->versionKey('_meta'));
    }

    protected function versionFor(string $type): int
    {
        $key = $this->versionKey($type);
        $v   = $this->cache->get($key);
        if ($v === null) {
            // Random so successive invalidations produce a different
            // stamp even within the same millisecond — `microtime(true)`
            // collides when invalidate-then-read happens fast enough,
            // and the resulting cache-key reuse defeats invalidation.
            $v = random_int(1, PHP_INT_MAX);
            $this->cache->forever($key, $v);
        }
        return (int) $v;
    }

    protected function versionKey(string $type): string
    {
        return $this->config['prefix'] . ':v:type:' . $type;
    }
}

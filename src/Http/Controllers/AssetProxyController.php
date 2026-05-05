<?php

namespace Ledric\Laravel\Http\Controllers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ledric\Laravel\Client;
use Ledric\Laravel\Exceptions\LedricUnavailableException;

/**
 * Public-facing asset endpoint. Browser → (CDN) → Laravel → ledric.
 *
 * The Laravel-side cache is keyed by `<refKey>?<query>`; ledric's
 * ref_keys rotate on every byte replacement, so cached entries are
 * implicitly immutable and safe to keep effectively forever.
 *
 * On a miss we fetch from ledric and store the bytes + content-type
 * (and a couple of other relayable headers) in one cache entry. We
 * deliberately buffer the body for caching — streaming-and-caching
 * simultaneously is doable but every Laravel cache store has its own
 * constraints (file = PHP-serialised; redis = MULTI safety; memcached
 * = 1MB item cap), and cache write paths don't accept streams. v1 caps
 * the in-flight buffer; large-asset streaming-without-caching is a
 * follow-up.
 */
class AssetProxyController extends Controller
{
    /** @var Client */
    protected $client;

    /** @var CacheRepository */
    protected $cache;

    /** @var ResponseFactory */
    protected $rf;

    /** @var array<string, mixed> */
    protected $config;

    /**
     * @param  array<string, mixed>  $config  // browser_max_age, cache_ttl, prefix
     */
    public function __construct(Client $client, CacheRepository $cache, ResponseFactory $rf, array $config)
    {
        $this->client = $client;
        $this->cache  = $cache;
        $this->rf     = $rf;
        $this->config = $config;
    }

    public function show(Request $request, string $refKey)
    {
        $cacheKey = $this->buildCacheKey($refKey, $request->query());
        $hit = $this->cache->get($cacheKey);

        if (is_array($hit) && isset($hit['bytes'])) {
            return $this->respond($hit['bytes'], $hit['content_type'] ?? 'application/octet-stream');
        }

        try {
            $upstream = $this->client->fetchAssetBytes($refKey, $request->query());
        } catch (LedricUnavailableException $e) {
            // No cache and no upstream — nothing we can serve. 503 lets
            // upstream layers (CDN, browser) decide whether to retry.
            return $this->rf->make(
                'ledric unavailable and no cached copy for this asset',
                503,
                ['Content-Type' => 'text/plain; charset=utf-8']
            );
        }

        $status = $upstream->getStatusCode();
        if ($status >= 400) {
            // Don't cache errors; relay status. Common case: missing asset
            // (404) or auth misconfig (401/403).
            return $this->rf->make((string) $upstream->getBody(), $status);
        }

        $bytes       = (string) $upstream->getBody();
        $contentType = $upstream->getHeaderLine('Content-Type') ?: 'application/octet-stream';

        $ttl = (int) ($this->config['cache_ttl'] ?? 31536000);
        $this->cache->put($cacheKey, [
            'bytes'        => $bytes,
            'content_type' => $contentType,
        ], $ttl);

        return $this->respond($bytes, $contentType);
    }

    protected function respond(string $bytes, string $contentType)
    {
        $maxAge = (int) ($this->config['browser_max_age'] ?? 31536000);
        return $this->rf->make($bytes, 200, [
            'Content-Type'   => $contentType,
            'Content-Length' => (string) strlen($bytes),
            // ref_keys are immutable — the bytes for a given key never
            // change, so we can let browsers and CDNs cache aggressively.
            'Cache-Control'  => sprintf('public, max-age=%d, immutable', $maxAge),
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function buildCacheKey(string $refKey, array $query): string
    {
        $prefix = (string) ($this->config['prefix'] ?? 'ledric');

        if (empty($query)) {
            return $prefix . ':asset:' . $refKey;
        }

        // Sort the query so different parameter orderings hash identically.
        ksort($query);
        return $prefix . ':asset:' . $refKey . ':' . md5(json_encode($query));
    }
}

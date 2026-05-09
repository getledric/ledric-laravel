<?php

namespace Ledric\Laravel;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Ledric\Laravel\Exceptions\LedricException;
use Ledric\Laravel\Exceptions\LedricUnavailableException;

/**
 * Thin Guzzle-backed wrapper around ledric's HTTP surface.
 *
 * Every read/write tool from the MCP catalogue is exposed through
 * `POST /rpc { tool, args }` — see ledric's `READ_RPC_TOOLS` set for
 * which tools accept reader keys. Asset bytes are streamed via
 * `GET /assets/<ref_key>` so we never buffer entire files in memory.
 *
 * Connect/timeout errors are turned into `LedricUnavailableException`
 * so the cache layer can fall back to stale-but-good values. 5xx is
 * also treated as unavailable for the same reason. 4xx is a real error
 * (bad request, auth) and surfaces as `LedricException`.
 */
class Client
{
    /** @var Guzzle */
    protected $http;

    /** @var string */
    protected $adminKey;

    /** @var string|null */
    protected $readerKey;

    /** @var string */
    protected $env;

    public function __construct(Guzzle $http, string $adminKey, ?string $readerKey, string $env)
    {
        $this->http = $http;
        $this->adminKey = $adminKey;
        $this->readerKey = $readerKey;
        $this->env = $env;
    }

    public function env(): string
    {
        return $this->env;
    }

    // ─────────────────────────── reads ───────────────────────────

    public function read(string $type, string $slug, array $opts = []): ?array
    {
        $entry = $this->call('read', array_merge(['ref' => ['type' => $type, 'slug' => $slug]], $opts), false);
        return is_array($entry) ? $this->normalizeEntry($entry) : $entry;
    }

    /**
     * Some ledric server versions emit content under `content`, others under
     * `fields`. The MCP and REST surfaces both standardise on `fields`, so
     * we project to that shape here. Idempotent; safe to call on either.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function normalizeEntry(array $entry): array
    {
        if (!isset($entry['fields']) && isset($entry['content']) && is_array($entry['content'])) {
            $entry['fields'] = $entry['content'];
            unset($entry['content']);
        }
        return $entry;
    }

    /**
     * Apply `normalizeEntry` to every entry inside a `{results, total}`
     * payload. find / search_entries on older ledric versions return
     * each result with `content` rather than `fields`; same defensive
     * rename as the single-entry path. Idempotent.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeEntryList(array $payload): array
    {
        if (!isset($payload['results']) || !is_array($payload['results'])) {
            return $payload;
        }
        $payload['results'] = array_map(function ($entry) {
            return is_array($entry) ? $this->normalizeEntry($entry) : $entry;
        }, $payload['results']);
        return $payload;
    }

    public function find(array $args): array
    {
        $r = $this->call('find', $args, false) ?? ['results' => [], 'total' => 0];
        return $this->normalizeEntryList($r);
    }

    /**
     * Full-text search. Convenience wrapper around `find`'s `q` arg —
     * ledric doesn't have a separate `search_entries` tool. Throws if
     * `q` is missing or empty so the caller doesn't accidentally fall
     * back to an unscoped find().
     */
    public function searchEntries(array $args): array
    {
        if (!isset($args['q']) || trim((string) $args['q']) === '') {
            throw new \InvalidArgumentException(
                'searchEntries() requires a non-empty "q" — use find() for unscoped queries'
            );
        }
        return $this->find($args);
    }

    public function listTypes(): array
    {
        // ledric has no `list_types` RPC tool — type metadata lives
        // inside `describe_model`'s response. We surface it as its own
        // method for ergonomic parity with the rest of the API.
        $model = $this->describeModel();
        if (isset($model['types']) && is_array($model['types'])) {
            return $model['types'];
        }
        return [];
    }

    public function describeModel(): array
    {
        return $this->call('describe_model', [], false) ?? [];
    }

    public function getAsset(string $id): ?array
    {
        return $this->call('get_asset', ['id' => $id], false);
    }

    // ─────────────────────────── writes ───────────────────────────

    /**
     * Draft a new entry or update an existing one.
     *
     * Schema (`draft` tool):
     *   - type: string (required)
     *   - fields: array (required)
     *   - ref: { type, slug } — optional; presence means "update" not "create"
     *   - parent_version: int — optional, optimistic-concurrency token
     *   - author: string — optional
     *
     * Convenience: callers may pass a flat `slug` (translated to `ref`)
     * and `content` (translated to `fields`) for legacy compatibility.
     */
    public function draftEntry(array $args): array
    {
        $args = $this->wrapRef($args);
        if (isset($args['content']) && !isset($args['fields'])) {
            $args['fields'] = $args['content'];
            unset($args['content']);
        }
        // `schema_version` was a TS-SDK input that the RPC schema rejects.
        unset($args['schema_version']);

        $r = $this->call('draft', $args, true);
        return is_array($r) ? $this->normalizeEntry($r) : ($r ?? []);
    }

    /**
     * Publish the current (or a specific) version of an entry.
     *
     * Schema (`publish` tool):
     *   - ref: { type, slug } (required)
     *   - version: int — optional, defaults to the current draft version
     */
    public function publishEntry(array $args): array
    {
        $args = $this->wrapRef($args);
        // After wrapRef, `type`/`slug` are absorbed into `ref`. Strip
        // any other keys the strict RPC schema would reject.
        $args = array_intersect_key($args, ['ref' => null, 'version' => null]);

        $r = $this->call('publish', $args, true);
        return is_array($r) ? $this->normalizeEntry($r) : ($r ?? []);
    }

    /**
     * Rename an entry's slug.
     *
     * Schema (`rename_entry` tool):
     *   - ref: { type, slug } (required)
     *   - new_slug: string (required)
     *   - locale: string (optional)
     */
    public function renameEntry(array $args): array
    {
        $args = $this->wrapRef($args);
        $r = $this->call('rename_entry', $args, true);
        return is_array($r) ? $this->normalizeEntry($r) : ($r ?? []);
    }

    public function deleteEntry(array $args): array
    {
        return $this->call('delete_entry', $this->wrapRef($args), true) ?? [];
    }

    public function addEntryTags(array $args): array
    {
        return $this->call('add_entry_tags', $this->wrapRef($args), true) ?? [];
    }

    public function removeEntryTags(array $args): array
    {
        return $this->call('remove_entry_tags', $this->wrapRef($args), true) ?? [];
    }

    public function alterType(array $args): array
    {
        return $this->call('alter_type', $args, true) ?? [];
    }

    /**
     * Translate `{type, slug, ...}` into `{ref: {type, slug}, ...}` —
     * the shape every entry-mutation tool's strict schema actually
     * accepts. No-op when `ref` is already present.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function wrapRef(array $args): array
    {
        if (isset($args['ref'])) {
            return $args;
        }
        if (isset($args['type'], $args['slug'])) {
            $args['ref'] = ['type' => $args['type'], 'slug' => $args['slug']];
            unset($args['slug']);
            // For tools where `type` is a sibling of `ref` (draft), keep
            // it. For ones where it's not (publish, rename, tags), the
            // method's own filter steps strip it.
        }
        return $args;
    }

    /**
     * Generic escape hatch for tools we haven't typed yet. Returns whatever
     * the server returns; caller is responsible for the args shape.
     *
     * @return mixed
     */
    public function rpc(string $tool, array $args, bool $isWrite = false)
    {
        return $this->call($tool, $args, $isWrite);
    }

    // ─────────────────────────── assets ───────────────────────────

    /**
     * Stream raw asset bytes. Returns the PSR-7 response so the caller can
     * pipe directly into Laravel's StreamedResponse without buffering.
     */
    public function fetchAssetBytes(string $refKey, array $query = [], array $extraHeaders = []): ResponseInterface
    {
        $headers = $extraHeaders;
        $headers['Authorization'] = 'Bearer ' . ($this->readerKey ?? $this->adminKey);

        try {
            return $this->http->request('GET', 'assets/' . rawurlencode($refKey), [
                'query'       => $query,
                'headers'     => $headers,
                'stream'      => true,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new LedricUnavailableException('ledric unreachable while fetching asset', 0, $e);
        } catch (GuzzleException $e) {
            throw new LedricException('ledric request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    // ─────────────────────────── admin proxy ───────────────────────────

    /**
     * Forward an arbitrary admin-API request. Used by AdminProxyController
     * so we don't have to model every internal endpoint individually.
     *
     * Inbound Authorization and cookies are stripped; admin Bearer is
     * injected. `X-Forwarded-*` headers tell ledric the URL prefix the
     * browser sees so the GUI's injected `<base href>` and
     * `window.LEDRIC_BASE_URL` resolve correctly. The PSR-7 response is
     * returned as-is for streaming.
     *
     * @param  array<string, array<int, string>>  $headers
     * @param  string|resource|null  $body
     */
    public function forwardAdmin(
        string $method,
        string $path,
        array $headers,
        $body,
        array $forwarded = []
    ): ResponseInterface {
        $clean = $this->scrubInboundHeaders($headers);
        $clean['Authorization'] = 'Bearer ' . $this->adminKey;

        if (isset($forwarded['prefix']) && $forwarded['prefix'] !== '') {
            $clean['X-Forwarded-Prefix'] = (string) $forwarded['prefix'];
        }
        if (isset($forwarded['host']) && $forwarded['host'] !== '') {
            $clean['X-Forwarded-Host'] = (string) $forwarded['host'];
        }
        if (isset($forwarded['proto']) && $forwarded['proto'] !== '') {
            $clean['X-Forwarded-Proto'] = (string) $forwarded['proto'];
        }

        try {
            return $this->http->request($method, ltrim($path, '/'), [
                'headers'     => $clean,
                'body'        => $body,
                'stream'      => true,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new LedricUnavailableException('ledric unreachable on admin proxy', 0, $e);
        } catch (GuzzleException $e) {
            throw new LedricException('ledric request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    // ─────────────────────────── health ───────────────────────────

    /**
     * Cheap "is the process up" check. Used by isHealthy() in the
     * cached client; not authenticated so it returns true as long as
     * the HTTP server answers.
     */
    public function ping(): bool
    {
        try {
            $resp = $this->http->request('GET', '/', [
                'connect_timeout' => 1,
                'timeout'         => 2,
                'http_errors'     => false,
            ]);
            return $resp->getStatusCode() < 500;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────── internals ───────────────────────────

    /**
     * @return mixed
     */
    protected function call(string $tool, array $args, bool $isWrite)
    {
        if ($isWrite) {
            if ($this->adminKey === '') {
                throw new LedricException(sprintf(
                    'ledric write %s blocked: LEDRIC_ADMIN_KEY is not configured (reader-only mode)',
                    $tool
                ));
            }
            $key = $this->adminKey;
        } else {
            $key = $this->readerKey ?? ($this->adminKey !== '' ? $this->adminKey : null);
            if ($key === null) {
                throw new LedricException(sprintf(
                    'ledric read %s blocked: no LEDRIC_READER_KEY or LEDRIC_ADMIN_KEY configured',
                    $tool
                ));
            }
        }

        try {
            $resp = $this->http->request('POST', 'rpc', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json'        => ['tool' => $tool, 'args' => (object) $args],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new LedricUnavailableException(sprintf('ledric unreachable on %s', $tool), 0, $e);
        } catch (GuzzleException $e) {
            throw new LedricException(sprintf('ledric request failed on %s: %s', $tool, $e->getMessage()), 0, $e);
        }

        $status = $resp->getStatusCode();
        $body   = (string) $resp->getBody();
        $data   = $body === '' ? null : json_decode($body, true);

        if ($status >= 500) {
            // Treat 5xx as "unavailable" — caller can fall back to cache.
            // Distinguishing "down" from "broken" doesn't matter at this layer.
            throw new LedricUnavailableException(sprintf('ledric %d on %s: %s', $status, $tool, substr($body, 0, 200)));
        }

        if ($status >= 400) {
            $msg = $this->extractErrorMessage($data, $body);
            throw new LedricException(sprintf('ledric %d on %s: %s', $status, $tool, $msg));
        }

        // Successful RPC responses are wrapped as { result: <payload> }.
        // Unwrap so callers see the same shape they'd get from MCP. A
        // result of null is meaningful (e.g. read of a missing slug) so
        // we don't fall back to $data in that case.
        if (is_array($data) && array_key_exists('result', $data)) {
            return $data['result'];
        }

        return $data;
    }

    /**
     * Pull a printable error string out of whatever shape ledric returned.
     * RPC errors come back as { code, message, ... }; some tools nest under
     * `error` (string or object). Fall back to a body excerpt so we never
     * lose information.
     *
     * @param  mixed   $data  json_decode'd response (array | scalar | null)
     * @param  string  $body  raw response body for fallback
     */
    protected function extractErrorMessage($data, string $body): string
    {
        if (is_array($data)) {
            if (isset($data['message']) && is_string($data['message'])) {
                return isset($data['code']) && is_string($data['code'])
                    ? $data['code'] . ': ' . $data['message']
                    : $data['message'];
            }
            if (isset($data['error'])) {
                $err = $data['error'];
                if (is_string($err)) {
                    return $err;
                }
                if (is_array($err) && isset($err['message']) && is_string($err['message'])) {
                    return isset($err['code']) && is_string($err['code'])
                        ? $err['code'] . ': ' . $err['message']
                        : $err['message'];
                }
                $encoded = json_encode($err);
                if ($encoded !== false) {
                    return substr($encoded, 0, 200);
                }
            }
        }
        if (is_string($data)) {
            return $data;
        }
        return substr($body, 0, 200);
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    protected function scrubInboundHeaders(array $headers): array
    {
        $blocked = ['authorization', 'cookie', 'host', 'content-length'];
        $out = [];
        foreach ($headers as $name => $values) {
            if (in_array(strtolower($name), $blocked, true)) {
                continue;
            }
            $out[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }
        return $out;
    }
}

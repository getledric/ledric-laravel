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
        return $this->call('read', array_merge(['type' => $type, 'slug' => $slug], $opts), false);
    }

    public function find(array $args): array
    {
        return $this->call('find', $args, false) ?? ['results' => [], 'total' => 0];
    }

    public function searchEntries(array $args): array
    {
        return $this->call('search_entries', $args, false) ?? ['results' => [], 'total' => 0];
    }

    public function listTypes(): array
    {
        return $this->call('list_types', [], false) ?? [];
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

    public function draftEntry(array $args): array
    {
        return $this->call('draft_entry', $args, true);
    }

    public function publishEntry(array $args): array
    {
        return $this->call('publish_entry', $args, true);
    }

    public function addEntryTags(array $args): array
    {
        return $this->call('add_entry_tags', $args, true);
    }

    public function removeEntryTags(array $args): array
    {
        return $this->call('remove_entry_tags', $args, true);
    }

    public function alterType(array $args): array
    {
        return $this->call('alter_type', $args, true);
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
        $key = $isWrite ? $this->adminKey : ($this->readerKey ?? $this->adminKey);

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
            $msg = is_array($data) && isset($data['error']) ? (string) $data['error'] : substr($body, 0, 200);
            throw new LedricException(sprintf('ledric %d on %s: %s', $status, $tool, $msg));
        }

        return $data;
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

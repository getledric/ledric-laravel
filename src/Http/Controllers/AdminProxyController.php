<?php

namespace Ledric\Laravel\Http\Controllers;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ledric\Laravel\Cache\CachedClient;
use Ledric\Laravel\Client;
use Ledric\Laravel\Exceptions\LedricUnavailableException;

/**
 * Forwards every request under the admin route prefix to the local
 * ledric process. The Laravel app stays the only public face; ledric
 * is invisible from the WWW.
 *
 * Two configurable prefixes:
 *   - `ledric.admin.route_prefix` (default `ledric-admin`) — what the
 *     browser sees. We pass it as `X-Forwarded-Prefix` so ledric
 *     rewrites `<base href>` and `window.LEDRIC_BASE_URL`.
 *   - `ledric.admin.upstream_prefix` (default `admin`) — where ledric
 *     actually mounts the GUI. ledric's CLI defaults to `/admin`; we
 *     prepend that to non-API outbound paths.
 *
 * Bodies are buffered, not streamed: PSR-7 `Stream::read()` over a
 * curl-backed Guzzle body throws `Unable to read from stream` because
 * `fread()` returns `false` at EOF before `eof()` flips.
 *
 * Multipart requests (e.g. asset uploads) are reconstructed from the
 * parsed Laravel request — PHP consumes `php://input` for multipart
 * POSTs and leaves `$request->getContent()` empty, so we have to
 * re-emit the parts via Guzzle's multipart support.
 *
 * Successful writes through `/rpc` invalidate the matching type in
 * `CachedClient` so a publish doesn't leave stale reads on the
 * consumer site for a full cache-TTL window.
 */
class AdminProxyController extends Controller
{
    /** @var Client */
    protected $client;

    /** @var CachedClient */
    protected $cached;

    /** @var ResponseFactory */
    protected $rf;

    /** Tools that require post-call cache invalidation. */
    private const WRITE_TOOLS = [
        'draft',
        'publish',
        'rename_entry',
        'delete_entry',
        'add_entry_tags',
        'remove_entry_tags',
        'alter_type',
        'create_type',
        'delete_type',
        'migrate_entries',
    ];

    public function __construct(Client $client, CachedClient $cached, ResponseFactory $rf)
    {
        $this->client = $client;
        $this->cached = $cached;
        $this->rf     = $rf;
    }

    public function handle(Request $request, string $path = '')
    {
        $externalPrefix = '/' . trim((string) config('ledric.admin.route_prefix', 'ledric-admin'), '/');
        $upstreamPrefix = trim((string) config('ledric.admin.upstream_prefix', 'admin'), '/');
        $rootPaths      = (array) config('ledric.admin.upstream_root_paths', []);

        $upstreamPath = $this->resolveUpstreamPath($path, $upstreamPrefix, $rootPaths);

        $isMultipart = stripos((string) $request->header('Content-Type', ''), 'multipart/') === 0;
        $multipart   = $isMultipart ? $this->buildMultipart($request) : null;
        $rawBody     = $isMultipart ? null : ($request->getContent() !== '' ? $request->getContent() : null);

        try {
            $upstream = $this->client->forwardAdmin(
                $request->method(),
                $upstreamPath,
                $request->headers->all(),
                $rawBody,
                [
                    'prefix' => $externalPrefix,
                    'host'   => $request->getHost(),
                    'proto'  => $request->getScheme(),
                ],
                $multipart
            );
        } catch (LedricUnavailableException $e) {
            return $this->rf->make(
                'ledric process unreachable — admin GUI requires the local ledric process to be running',
                503,
                ['Content-Type' => 'text/plain; charset=utf-8']
            );
        }

        $body   = (string) $upstream->getBody();
        $status = $upstream->getStatusCode();

        if ($status < 400 && $upstreamPath === 'rpc' && $rawBody !== null) {
            $this->invalidateAfterRpc($rawBody);
        }

        return $this->rf->make(
            $body,
            $status,
            $this->relayHeaders($upstream->getHeaders())
        );
    }

    /**
     * Build the multipart array Guzzle expects from a parsed Laravel
     * request. PHP populates `$_POST`/`$_FILES` for multipart bodies
     * and leaves `php://input` empty, so the raw body isn't available
     * to forward verbatim.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildMultipart(Request $request): array
    {
        $parts = [];

        foreach ($request->allFiles() as $name => $file) {
            $files = is_array($file) ? $file : [$file];
            $hasMany = is_array($file);
            foreach ($files as $f) {
                $partName = $hasMany ? $name . '[]' : $name;
                $parts[] = [
                    'name'     => $partName,
                    'contents' => fopen($f->getRealPath(), 'r'),
                    'filename' => $f->getClientOriginalName(),
                    'headers'  => ['Content-Type' => $f->getMimeType() ?: 'application/octet-stream'],
                ];
            }
        }

        foreach ($request->post() as $name => $value) {
            if ($request->hasFile($name)) {
                continue; // already emitted above
            }
            $parts[] = [
                'name'     => $name,
                'contents' => is_array($value) ? json_encode($value) : (string) $value,
            ];
        }

        return $parts;
    }

    /**
     * Inspect a successful POST /rpc body and invalidate the matching
     * type in CachedClient. The proxy bypasses the CachedClient's own
     * write methods, so without this an inline-editor publish leaves
     * the consumer site serving stale reads for up to `cache.ttl`.
     */
    protected function invalidateAfterRpc(string $rawBody): void
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) return;

        $tool = $payload['tool'] ?? null;
        $args = $payload['args'] ?? [];
        if (!is_string($tool) || !is_array($args)) return;

        if (!in_array($tool, self::WRITE_TOOLS, true)) return;

        $type = $this->extractType($tool, $args);
        if ($type === null) {
            // Unknown shape — flush meta to be safe (cheap).
            $this->cached->flush();
            return;
        }
        $this->cached->flush($type);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function extractType(string $tool, array $args): ?string
    {
        if (isset($args['ref']) && is_array($args['ref']) && isset($args['ref']['type'])) {
            return (string) $args['ref']['type'];
        }
        if ($tool === 'alter_type' || $tool === 'create_type' || $tool === 'delete_type') {
            if (isset($args['name'])) return (string) $args['name'];
        }
        if (isset($args['type'])) {
            return (string) $args['type'];
        }
        return null;
    }

    /**
     * Demux GUI vs API paths. ledric's API endpoints (`/types`, `/rpc`,
     * `/entries/...`, `/assets/...`, `/tags`, `/auth/...`) live at the
     * upstream root, but the GUI is under `/admin/*`.
     *
     * @param  array<int, string>  $rootPaths
     */
    protected function resolveUpstreamPath(string $path, string $upstreamPrefix, array $rootPaths): string
    {
        $clean = ltrim($path, '/');
        $first = $clean === '' ? '' : (explode('/', $clean, 2)[0] ?? '');

        if ($first !== '' && in_array($first, $rootPaths, true)) {
            return $clean;
        }

        if ($upstreamPrefix === '') {
            return $clean;
        }

        return $clean === '' ? $upstreamPrefix : $upstreamPrefix . '/' . $clean;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    protected function relayHeaders(array $headers): array
    {
        // Hop-by-hop headers and PHP-managed encoding mustn't be relayed.
        $blocked = ['transfer-encoding', 'connection', 'keep-alive', 'content-encoding', 'content-length'];
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

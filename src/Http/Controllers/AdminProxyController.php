<?php

namespace Ledric\Laravel\Http\Controllers;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
 *     prepend that to outbound paths so e.g. `/ledric-admin/inline.js`
 *     reaches `/admin/inline.js` upstream rather than `/inline.js`.
 *
 * Bodies are buffered, not streamed: PSR-7 `Stream::read()` over a
 * curl-backed Guzzle body throws `Unable to read from stream` because
 * `fread()` returns `false` at EOF before `eof()` flips. Streaming
 * defensively against that quirk isn't worth the trade-off for admin
 * GUI traffic, which is bounded HTML/JS/CSS — large binary responses
 * have their own controller (AssetProxyController).
 */
class AdminProxyController extends Controller
{
    /** @var Client */
    protected $client;

    /** @var ResponseFactory */
    protected $rf;

    public function __construct(Client $client, ResponseFactory $rf)
    {
        $this->client = $client;
        $this->rf     = $rf;
    }

    public function handle(Request $request, string $path = '')
    {
        $externalPrefix = '/' . trim((string) config('ledric.admin.route_prefix', 'ledric-admin'), '/');
        $upstreamPrefix = trim((string) config('ledric.admin.upstream_prefix', 'admin'), '/');
        $rootPaths      = (array) config('ledric.admin.upstream_root_paths', []);

        $upstreamPath = $this->resolveUpstreamPath($path, $upstreamPrefix, $rootPaths);

        try {
            $upstream = $this->client->forwardAdmin(
                $request->method(),
                $upstreamPath,
                $request->headers->all(),
                $request->getContent() !== '' ? $request->getContent() : null,
                [
                    'prefix' => $externalPrefix,
                    'host'   => $request->getHost(),
                    'proto'  => $request->getScheme(),
                ]
            );
        } catch (LedricUnavailableException $e) {
            return $this->rf->make(
                'ledric process unreachable — admin GUI requires the local ledric process to be running',
                503,
                ['Content-Type' => 'text/plain; charset=utf-8']
            );
        }

        $body = (string) $upstream->getBody();

        return $this->rf->make(
            $body,
            $upstream->getStatusCode(),
            $this->relayHeaders($upstream->getHeaders())
        );
    }

    /**
     * Demux GUI vs API paths. ledric's API endpoints (`/types`, `/rpc`,
     * `/entries/...`, `/assets/...`, `/tags`, `/auth/...`) live at the
     * upstream root, but the GUI is under `/admin/*`. The browser
     * calls everything through `/ledric-admin/*` because the GUI's
     * api.js builds absolute paths from `window.LEDRIC_BASE_URL`.
     * This routes the API segments to root and prefixes everything
     * else with the upstream GUI mount.
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
        // Content-Encoding goes too: Guzzle's default `decode_content: true`
        // already inflated the body before we cast to string, so the
        // bytes are plain by the time we hand them to the browser.
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

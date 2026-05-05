<?php

namespace Ledric\Laravel\Http\Controllers;

use GuzzleHttp\Psr7\Utils as Psr7Utils;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ledric\Laravel\Client;
use Ledric\Laravel\Exceptions\LedricUnavailableException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Forwards every request under the admin route prefix to the local
 * ledric process. The Laravel app stays the only public face; ledric
 * is invisible from the WWW.
 *
 * Inbound auth headers are stripped (the AdminGate middleware has
 * already authed the user); ledric's admin Bearer is injected by
 * Client::forwardAdmin().
 *
 * NOTE on inline GUI base path: ledric serves its own GUI at root
 * (`/`), but here it's reached at e.g. `/ledric-admin/...`. If the
 * GUI emits absolute API paths (`/api/...`) those will miss this
 * prefix. For v1 we just forward everything; if/when the GUI grows
 * absolute-path callers, we can either rewrite the response HTML or
 * teach the GUI a base-path config.
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
        $prefix = '/' . trim((string) config('ledric.admin.route_prefix', 'ledric-admin'), '/');

        try {
            $upstream = $this->client->forwardAdmin(
                $request->method(),
                $path,
                $request->headers->all(),
                $request->getContent() !== '' ? $request->getContent() : null,
                [
                    'prefix' => $prefix,
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

        $body = $upstream->getBody();

        $response = new StreamedResponse(function () use ($body) {
            // 8 KiB chunks — same default as Symfony's BinaryFileResponse.
            // Larger chunks waste memory for small responses; smaller
            // burns syscalls on big asset bodies (we use this controller
            // for both API JSON and any binary surface ledric exposes
            // under the admin prefix).
            while (!$body->eof()) {
                echo $body->read(8192);
                @ob_flush();
                @flush();
            }
        }, $upstream->getStatusCode(), $this->relayHeaders($upstream->getHeaders()));

        return $response;
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

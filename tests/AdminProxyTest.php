<?php

namespace Ledric\Laravel\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Ledric\Laravel\Http\Controllers\AdminProxyController;

/**
 * Regression coverage for two AdminProxy bugs that shipped in
 * c14b026:
 *
 *   1. Body-streaming via PSR-7 Stream::read threw `Unable to read
 *      from stream` at EOF on curl-backed Guzzle bodies. Fix: buffer.
 *   2. Outgoing path didn't include ledric's GUI mount prefix
 *      (default `/admin`), so `/ledric-admin/foo` reached `<ledric>/foo`
 *      instead of `<ledric>/admin/foo` — `inline.js` 404'd, root
 *      returned API metadata instead of the GUI HTML. Fix:
 *      `ledric.admin.upstream_prefix` config key, default `admin`.
 */
class AdminProxyTest extends TestCase
{
    public function test_handle_returns_buffered_upstream_body(): void
    {
        // Body large enough to exercise the multi-chunk path the old
        // streaming code was meant for. Pure text is enough to repro
        // because the throw was on EOF, not on size.
        $payload = str_repeat('lorem ipsum dolor sit amet ', 500);
        $this->mockHandler->append(new Response(200, ['Content-Type' => 'text/html'], $payload));

        $controller = $this->app->make(AdminProxyController::class);
        $response   = $controller->handle(Request::create('/ledric-admin/'), '');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($payload, $response->getContent());
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
    }

    public function test_handle_prepends_upstream_prefix_to_outgoing_path(): void
    {
        $this->mockHandler->append(new Response(200, [], 'ok'));

        $this->app->make(AdminProxyController::class)
            ->handle(Request::create('/ledric-admin/inline.js'), 'inline.js');

        $req = $this->history[0]['request'];
        $this->assertSame('/admin/inline.js', $req->getUri()->getPath());
    }

    public function test_handle_uses_upstream_prefix_alone_when_path_is_empty(): void
    {
        // GET /ledric-admin (no trailing path) must reach <ledric>/admin
        // — that's what serves the GUI HTML. If we only sent /admin/
        // with a slash difference the upstream may 308; keep it bare.
        $this->mockHandler->append(new Response(200, [], '<html>'));

        $this->app->make(AdminProxyController::class)
            ->handle(Request::create('/ledric-admin'), '');

        $req = $this->history[0]['request'];
        $this->assertSame('/admin', $req->getUri()->getPath());
    }

    public function test_handle_respects_empty_upstream_prefix(): void
    {
        $this->app['config']->set('ledric.admin.upstream_prefix', '');
        $this->mockHandler->append(new Response(200, [], 'ok'));

        $this->app->make(AdminProxyController::class)
            ->handle(Request::create('/ledric-admin/foo'), 'foo');

        $req = $this->history[0]['request'];
        $this->assertSame('/foo', $req->getUri()->getPath());
    }

    public function test_handle_forwards_x_forwarded_prefix_to_upstream(): void
    {
        $this->mockHandler->append(new Response(200, [], '<html>'));

        $this->app->make(AdminProxyController::class)
            ->handle(Request::create('https://example.com/ledric-admin/'), '');

        $req = $this->history[0]['request'];
        $this->assertSame('/ledric-admin', $req->getHeaderLine('X-Forwarded-Prefix'));
        $this->assertSame('example.com', $req->getHeaderLine('X-Forwarded-Host'));
        $this->assertSame('https', $req->getHeaderLine('X-Forwarded-Proto'));
    }

    public function test_handle_returns_503_when_ledric_unreachable(): void
    {
        $this->mockHandler->append(new ConnectException(
            'connection refused',
            new Psr7Request('GET', 'http://127.0.0.1:3030/admin')
        ));

        $response = $this->app->make(AdminProxyController::class)
            ->handle(Request::create('/ledric-admin/'), '');

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('unreachable', $response->getContent());
    }

    public function test_api_path_types_forwards_to_root_not_admin_prefix(): void
    {
        // ledric's /types is at root. The GUI's api.types() calls
        // /ledric-admin/types which the proxy must NOT prefix with
        // /admin/ (that would 404 and surface as "Unknown type X" in
        // the inline editor).
        $this->mockHandler->append(new Response(200, [], '{"types":[]}'));

        $this->app->make(AdminProxyController::class)
            ->handle(Request::create('/ledric-admin/types'), 'types');

        $this->assertSame('/types', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_api_path_auth_status_forwards_to_root(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"required":false}'));

        $this->app->make(AdminProxyController::class)
            ->handle(Request::create('/ledric-admin/auth/status'), 'auth/status');

        $this->assertSame('/auth/status', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_api_path_rpc_forwards_to_root(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"result":null}'));

        $this->app->make(AdminProxyController::class)
            ->handle(Request::create('/ledric-admin/rpc', 'POST'), 'rpc');

        $this->assertSame('/rpc', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_gui_path_inline_js_keeps_admin_prefix(): void
    {
        // inline.js lives at <ledric>/admin/inline.js — must not be
        // demux'd to root.
        $this->mockHandler->append(new Response(200, [], 'console.log()'));

        $this->app->make(AdminProxyController::class)
            ->handle(Request::create('/ledric-admin/inline.js'), 'inline.js');

        $this->assertSame('/admin/inline.js', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_gui_path_spa_deep_route_keeps_admin_prefix(): void
    {
        // SPA routes like /inline/page/about are unknown to the demux
        // and fall into the admin-prefix branch. ledric's setNotFound-
        // Handler serves the SPA HTML for any HTML request under the
        // mount.
        $this->mockHandler->append(new Response(200, [], '<html>'));

        $this->app->make(AdminProxyController::class)
            ->handle(Request::create('/ledric-admin/inline/page/about-summit'), 'inline/page/about-summit');

        $this->assertSame('/admin/inline/page/about-summit', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_handle_strips_inbound_authorization_and_cookies(): void
    {
        $this->mockHandler->append(new Response(200, [], 'ok'));

        $req = Request::create('/ledric-admin/foo');
        $req->headers->set('Authorization', 'Bearer user-cookie-not-mine');
        $req->cookies->set('laravel_session', 'abc');

        $this->app->make(AdminProxyController::class)->handle($req, 'foo');

        // Outbound Authorization is the admin key, not the inbound value.
        $auth = $this->history[0]['request']->getHeaderLine('Authorization');
        $this->assertSame('Bearer lka_admin_test', $auth);
    }
}

<?php

namespace Ledric\Laravel\Http\Controllers;

use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ledric\Laravel\Inline;

/**
 * Toggles the `ledric_preview` cookie. Wired by the inline editor's
 * preview-mode button — POST flips the bit, then the GUI reloads the
 * page so the renderer picks up draft content.
 *
 * The route is gated by AdminGate so only allow-listed users can
 * activate preview. The cookie itself is httpOnly — the inline editor
 * gets the active state from `window.LEDRIC_PREVIEW` injected by
 * `Inline::loaderHtml()`, not from reading the cookie itself.
 */
class PreviewController extends Controller
{
    public function toggle(Request $request, CookieJar $cookies)
    {
        $current = $request->cookie(Inline::PREVIEW_COOKIE) === '1';

        $response = response('', 204);

        if ($current) {
            $cookies->queue($cookies->forget(Inline::PREVIEW_COOKIE));
        } else {
            // 8h lifetime — long enough for an editing session, short
            // enough that a forgotten preview eventually reverts.
            $minutes  = 60 * 8;
            $secure   = $request->isSecure();
            $sameSite = 'lax';
            $cookies->queue($cookies->make(
                Inline::PREVIEW_COOKIE,
                '1',
                $minutes,
                '/',     // path
                null,    // domain
                $secure,
                true,    // httpOnly
                false,   // raw
                $sameSite
            ));
        }

        return $response;
    }
}

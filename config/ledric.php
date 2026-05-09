<?php

return [

    // Where the local ledric process is reachable. Should always be a
    // localhost URL — the whole point of this package is that ledric never
    // touches the public internet directly.
    'base_url' => env('LEDRIC_URL', 'http://127.0.0.1:3030'),

    'admin_key'  => env('LEDRIC_ADMIN_KEY'),
    'reader_key' => env('LEDRIC_READER_KEY'),

    'env' => env('LEDRIC_ENV', 'main'),

    // Anything past these is treated as "ledric unavailable" and falls
    // back to cache (or 503 for assets / admin proxy with no cached value).
    'connect_timeout' => (float) env('LEDRIC_CONNECT_TIMEOUT', 2),
    'request_timeout' => (float) env('LEDRIC_REQUEST_TIMEOUT', 5),

    'cache' => [
        // null = use the app's default cache store. Set to 'redis' or
        // 'memcached' to enable tagged invalidation; file/database stores
        // fall back to a per-type version stamp.
        'store'  => env('LEDRIC_CACHE_STORE'),
        'prefix' => env('LEDRIC_CACHE_PREFIX', 'ledric'),

        // Fresh window — within this, served without hitting ledric.
        'ttl' => (int) env('LEDRIC_CACHE_TTL', 300),

        // Stale window — when ledric is unreachable, serve cache up to
        // this age. Set to 0 to disable stale-while-error.
        'stale_ttl' => (int) env('LEDRIC_CACHE_STALE_TTL', 86400),

        // Cache for the isHealthy() ping result so consumer code can
        // branch on availability without pounding ledric.
        'health_ttl' => 5,
    ],

    'admin' => [
        // Laravel user IDs (auth()->user()->getKey()) allowed to use the
        // inline admin GUI. Empty = nobody. Comma-separated in env.
        'user_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LEDRIC_ADMIN_USER_IDS', ''))
        ))),

        'route_prefix' => env('LEDRIC_ADMIN_PREFIX', 'ledric-admin'),

        // Path prefix on the *upstream* ledric process where the GUI is
        // mounted. ledric's CLI defaults `--gui-mount` to `/admin`, so
        // we prepend `admin/` to non-API outbound paths. Set to '' if
        // you've started ledric with `--gui-mount /`.
        'upstream_prefix' => env('LEDRIC_ADMIN_UPSTREAM_PREFIX', 'admin'),

        // Path segments that bypass `upstream_prefix` and forward to
        // the upstream root. ledric's API endpoints live at root
        // (/types, /rpc, …) while the GUI lives under the mount; the
        // GUI's api.js calls absolute paths, so the proxy demuxes
        // here. Match is on the FIRST path segment only.
        'upstream_root_paths' => [
            'types',
            'entries',
            'rpc',
            'assets',
            'tags',
            'auth',
            'mcp',
            '.well-known',
        ],

        // Middleware applied before AdminGate (which checks user_ids).
        //
        // Default is the standard `web` group MINUS VerifyCsrfToken.
        // The GUI's api.js builds plain `fetch()` requests with no
        // CSRF token, so VerifyCsrfToken would 419 every write. The
        // proxy is a same-origin admin surface gated by AdminGate's
        // user-ID allow-list, and Laravel's default SameSite=Lax on
        // the session cookie already blocks cross-site CSRF. We don't
        // use the literal 'web' alias because it'd pull in the user's
        // App\Http\Middleware\VerifyCsrfToken, which `withoutMiddleware`
        // can't reliably exclude across versions.
        //
        // Override to ['web', 'auth'] (or your own stack) if you want
        // CSRF on top — you'll then need to add this prefix to your
        // App\Http\Middleware\VerifyCsrfToken::$except array OR inject
        // the token into the GUI yourself.
        'middleware' => [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            'auth',
        ],
    ],

    'assets' => [
        'route_prefix' => env('LEDRIC_ASSET_PREFIX', 'ledric-assets'),

        // Browser cache header on asset responses. ref_keys are immutable
        // (rotate on every byte replacement) so a year is safe.
        'browser_max_age' => (int) env('LEDRIC_ASSET_BROWSER_MAX_AGE', 31536000),

        // Laravel-side cache TTL for asset bytes. ref_keys never change
        // their bytes, so longer is fine. Capped at int max.
        'cache_ttl' => (int) env('LEDRIC_ASSET_CACHE_TTL', 31536000),
    ],
];

<?php

namespace Ledric\Laravel;

use GuzzleHttp\Client as Guzzle;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Ledric\Laravel\Cache\CachedClient;
use Ledric\Laravel\Http\Controllers\AssetProxyController;

class LedricServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ledric.php', 'ledric');

        $this->app->singleton(Guzzle::class, function ($app) {
            $cfg = $app['config']->get('ledric');

            return new Guzzle([
                'base_uri'        => rtrim((string) $cfg['base_url'], '/') . '/',
                'connect_timeout' => (float) $cfg['connect_timeout'],
                'timeout'         => (float) $cfg['request_timeout'],
                'http_errors'     => false,
                'headers'         => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'ledric-laravel/0.1',
                ],
            ]);
        });

        $this->app->singleton(Client::class, function ($app) {
            $cfg = $app['config']->get('ledric');

            $adminKey  = (string) ($cfg['admin_key']  ?? '');
            $readerKey = (string) ($cfg['reader_key'] ?? '');

            if ($adminKey === '' && $readerKey === '') {
                // At least one key is required. Reader-only is a valid
                // posture for public consumer sites; writes will throw
                // at call time with a clear message.
                throw new \RuntimeException(
                    'ledric: set LEDRIC_READER_KEY (read-only) and/or LEDRIC_ADMIN_KEY (writes) in .env'
                );
            }

            return new Client(
                $app->make(Guzzle::class),
                $adminKey,
                $readerKey !== '' ? $readerKey : null,
                (string) $cfg['env']
            );
        });

        $this->app->singleton(CachedClient::class, function ($app) {
            $cfg   = $app['config']->get('ledric.cache');
            $store = $cfg['store'] !== null && $cfg['store'] !== '' ? (string) $cfg['store'] : null;

            /** @var CacheFactory $factory */
            $factory = $app->make('cache');
            $repo    = $store !== null ? $factory->store($store) : $factory->store();

            return new CachedClient($app->make(Client::class), $repo, $cfg);
        });

        // The asset proxy controller takes a few constructor args we
        // can't autowire — pull them out here so route registration just
        // does `[AssetProxyController::class, 'show']`.
        $this->app->singleton(AssetProxyController::class, function ($app) {
            $cfg     = $app['config']->get('ledric');
            $store   = $cfg['cache']['store'] !== null && $cfg['cache']['store'] !== ''
                ? (string) $cfg['cache']['store']
                : null;

            /** @var CacheFactory $factory */
            $factory = $app->make('cache');
            $repo    = $store !== null ? $factory->store($store) : $factory->store();

            return new AssetProxyController(
                $app->make(Client::class),
                $repo,
                $app->make(ResponseFactory::class),
                array_merge(
                    $cfg['assets'],
                    ['prefix' => $cfg['cache']['prefix']]
                )
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/ledric.php' => config_path('ledric.php'),
        ], 'ledric-config');

        $this->registerWebSansCsrfGroup();

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        // Blade directives for the inline editor surface.
        //
        //   @ledricInline                — emits the loader script (admin-only)
        //   @ledricRefAttrs($entry, 'title')  — emits data-ledric-* attrs
        //
        // Both are no-ops for non-admin users, so they're safe to drop
        // into shared layouts.
        Blade::directive('ledricInline', function ($expression) {
            return '<?php echo \\Ledric\\Laravel\\Inline::loaderHtml(' . $expression . '); ?>';
        });
        Blade::directive('ledricRefAttrs', function ($expression) {
            return '<?php echo \\Ledric\\Laravel\\Inline::refAttrsHtml(' . $expression . '); ?>';
        });
    }

    /**
     * Register the `ledric.web_no_csrf` middleware group: a copy of the
     * consumer's `web` group with VerifyCsrfToken (and any user
     * subclass thereof) filtered out.
     *
     * Why this exists, given the previous fix in e3dd1fc hardcoded the
     * framework's web-stack classes:
     *
     * The admin proxy needs the consumer's session/cookie middleware
     * so authenticated users stay logged in across `/ledric-admin/*`.
     * Most apps customize `App\Http\Middleware\EncryptCookies` to
     * override `$serialize`, `$except`, or `decryptCookie()`. Hardcoding
     * `\Illuminate\Cookie\Middleware\EncryptCookies` directly bypasses
     * those overrides — the proxy and the host app then disagree about
     * how cookies are encoded, and the session cookie is silently
     * dropped on every proxy request (looks like a logout).
     *
     * `withoutMiddleware('VerifyCsrfToken')` doesn't help: it matches
     * by exact class name and the `web` group registers the
     * consumer's subclass, not the base. Filtering by inheritance
     * (`is_subclass_of`) catches both.
     */
    protected function registerWebSansCsrfGroup(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];

        // Force-resolve the HTTP Kernel so its constructor runs and
        // pushes the consumer's middleware groups (`web`, `api`, …)
        // into the router. Service-provider `boot()` normally runs
        // BEFORE the kernel is instantiated, so without this the
        // router has no `web` group to copy. Skip during console
        // requests where the HTTP Kernel binding may not exist —
        // the proxy routes are HTTP-only anyway.
        if (!$this->app->runningInConsole() || $this->app->bound(\Illuminate\Contracts\Http\Kernel::class)) {
            try {
                $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
            } catch (\Throwable $e) {
                // Couldn't resolve — proceed with whatever's registered.
            }
        }

        $groups = $router->getMiddlewareGroups();
        $web    = $groups['web'] ?? null;

        if ($web === null || $web === []) {
            // No `web` group registered (e.g. a console-only app, or a
            // heavily stripped kernel). Fall back to a minimal
            // session-capable stack so the proxy at least gets cookies.
            $filtered = [
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ];
        } else {
            $csrf = \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class;

            $filtered = array_values(array_filter($web, function ($mw) use ($csrf) {
                if (!is_string($mw)) {
                    return true;        // closures, arrays — keep as-is
                }
                if (!class_exists($mw)) {
                    return true;        // aliases / unresolvable strings — keep
                }
                if ($mw === $csrf) {
                    return false;
                }
                return !is_subclass_of($mw, $csrf);
            }));
        }

        $router->middlewareGroup('ledric.web_no_csrf', $filtered);
    }
}

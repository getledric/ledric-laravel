<?php

use Illuminate\Support\Facades\Route;
use Ledric\Laravel\Http\Controllers\AdminProxyController;
use Ledric\Laravel\Http\Controllers\AssetProxyController;
use Ledric\Laravel\Http\Controllers\PreviewController;
use Ledric\Laravel\Http\Middleware\AdminGate;

// Routes are loaded by LedricServiceProvider with prefixes pulled from
// config(). Route prefixes are configurable so consumer apps can mount
// the proxies under whatever URL space fits their existing app.

Route::prefix(config('ledric.assets.route_prefix'))
    ->middleware('web')
    ->group(function () {
        Route::get('{refKey}', [AssetProxyController::class, 'show'])
            ->where('refKey', '[A-Za-z0-9_\-]+')
            ->name('ledric.asset');
    });

Route::prefix(config('ledric.admin.route_prefix'))
    ->middleware(array_merge(
        (array) config('ledric.admin.middleware', ['web', 'auth']),
        [AdminGate::class]
    ))
    ->group(function () {
        // Specific routes register before the catch-all so they win.
        Route::post('preview-toggle', [PreviewController::class, 'toggle'])
            ->name('ledric.preview-toggle');

        Route::any('{path?}', [AdminProxyController::class, 'handle'])
            ->where('path', '.*')
            ->name('ledric.admin');
    });

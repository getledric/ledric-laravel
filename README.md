# ledric/laravel

Laravel client + admin proxy + asset proxy for [ledric](https://github.com/getledric/ledric).

The Laravel app stays the only public face; the ledric process listens
on localhost and is invisible from the WWW. Reads are cached via
Laravel's cache; ledric being unreachable degrades gracefully (cached
values served past their fresh window, isHealthy() returns false).

```
browser → (CDN) → Laravel → ledric (127.0.0.1, supervised separately)
```

## Compatibility

- **PHP:** 7.4+ / 8.x
- **Laravel:** 6.20+ / 7 / 8 / 9 / 10
- **Guzzle:** 6.5+ / 7

## Install

```bash
composer require ledric/laravel
php artisan vendor:publish --tag=ledric-config
```

`config/ledric.php` is created. Add to `.env`:

```env
LEDRIC_URL=http://127.0.0.1:3030
LEDRIC_ADMIN_KEY=lka_...
LEDRIC_READER_KEY=lkr_...
LEDRIC_ADMIN_USER_IDS=1,42      # Laravel user IDs allowed in the admin GUI
```

ledric should be running as a separate systemd / supervisord job. This
package will not start it for you.

## Reading content

```php
use Ledric;

$post   = Ledric::read('post', 'hello-world');
$page   = Ledric::find(['type' => 'post', 'limit' => 10]);
$hits   = Ledric::searchEntries(['q' => 'astro']);
```

Reads are cached for `cache.ttl` seconds (default 5 min). On a cache
miss within `cache.stale_ttl` (default 24 h), if ledric is unreachable
the previous value is served.

## Writing content

```php
// Create (slug auto-assigned by ledric):
Ledric::draftEntry('post', ['title' => 'Hi', 'body' => '...']);

// Update an existing entry (slug positions it):
Ledric::draftEntry('post', ['title' => 'Hi'], 'new-post', [
    'parent_version' => 3,
    'author'         => 'james',
]);

Ledric::publishEntry('post', 'new-post');             // ?, $version
Ledric::renameEntry('post', 'new-post', 'shipped');   // ?, $locale
Ledric::deleteEntry('post', 'shipped');
Ledric::addEntryTags('post', 'shipped', ['featured']);
Ledric::removeEntryTags('post', 'shipped', ['featured']);
```

Writes invalidate cached reads of the same type.

## Asset proxy

`GET /ledric-assets/<refKey>` is mounted by the package. Browser
requests for asset bytes go through Laravel, which fetches from ledric
and caches the bytes. ref_keys are immutable so the cache lifetime is
effectively forever.

```html
<img src="{{ route('ledric.asset', ['refKey' => $entry['fields']['hero']['ref_key']]) }}" />
```

Optional transform query params (`?w=400&fmt=webp`) are forwarded to
ledric and cached per-variant.

## Inline admin GUI

`GET /ledric-admin/...` proxies the live ledric admin GUI. The
`AdminGate` middleware allows only users whose ID is in
`config('ledric.admin.user_ids')`.

```php
// routes are auto-registered with prefix from config('ledric.admin.route_prefix')
// default chain: ['web', 'auth', AdminGate::class]
```

The proxy sets `X-Forwarded-Prefix` on outbound requests so ledric
emits a `<base href>` and `window.LEDRIC_BASE_URL` matching the
external prefix. Requires ledric ≥ 0.3.5 for this behavior.

## Inline editor

Drop the loader and ref attributes into your Blade templates:

```blade
{{-- Layout --}}
<head>
  ...
  @ledricInline   {{-- emits the editor script for admin users; nothing for visitors --}}
</head>

{{-- A page rendering an entry --}}
<article @ledricRefAttrs($post)>
  <h1 @ledricRefAttrs($post, 'title')>{{ $post['fields']['title'] }}</h1>
  <div @ledricRefAttrs($post, 'body')>{!! $rendered !!}</div>
</article>
```

For non-Blade contexts:

```php
use Ledric\Laravel\Inline;

$attrs = Inline::refAttrs($post, 'title');
// ['data-ledric-ref' => 'post/hello', 'data-ledric-field' => 'title']
```

## Preview mode

When an admin enables preview, reads return draft content instead of
the published version. The inline editor surfaces a toggle button —
clicking it POSTs to `/ledric-admin/preview-toggle`, the cookie flips,
and the page reloads.

By default reads include `published=true`. In preview mode, the flag is
dropped so ledric returns the current (potentially-unpublished) version.

```php
// auto-detect from the cookie + admin gate (default behavior)
Ledric::find(['type' => 'post']);

// explicit override on a per-call basis
Ledric::preview()->find(['type' => 'post']);    // drafts
Ledric::published()->find(['type' => 'post']);  // published-only

// branch on it
if (Ledric::isPreview()) {
    // show banner, etc
}
```

Cache entries are partitioned by preview state so a draft view never
pollutes the published cache and vice versa.

## Graceful degradation

```php
if (!Ledric::isHealthy()) {
    // ledric down — disable live-search box, hide editor, etc.
}

try {
    $post = Ledric::read('post', $slug);
} catch (\Ledric\Laravel\Exceptions\LedricUnavailableException $e) {
    // ledric unreachable AND no cached fallback in the stale window.
    abort(503);
}
```

`LedricUnavailableException` is raised on connection refused, timeout,
and 5xx responses. 4xx surfaces as `LedricException`.

## Manual invalidation

```php
Ledric::flush();           // everything
Ledric::flush('post');     // a specific type
```

## Tests

```bash
composer install
vendor/bin/phpunit
```

## License

MIT

<?php

namespace Ledric\Laravel;

/**
 * Helpers for the inline editing surface.
 *
 * `refAttrs()` produces the `data-ledric-ref` / `data-ledric-field`
 * attributes the inline editor uses to discover editable elements.
 * `loaderHtml()` emits the `<script>` tag that boots the editor —
 * gated on the current user being in `config('ledric.admin.user_ids')`,
 * and seeds the preview-mode globals the upstream `inline.js` reads.
 */
class Inline
{
    public const PREVIEW_COOKIE = 'ledric_preview';

    /**
     * Build the `data-ledric-*` attribute set for an editable element.
     *
     * Accepts:
     *   - an entry array `['type' => 'post', 'slug' => 'hello', ...]`
     *   - an object with public `$type` / `$slug`
     *   - a `'type/slug'` string
     *
     * @param  array<string, mixed>|object|string  $entry
     * @return array<string, string>
     */
    public static function refAttrs($entry, ?string $field = null): array
    {
        [$type, $slug] = self::extractRef($entry);
        if ($type === null || $slug === null) {
            return [];
        }
        $attrs = ['data-ledric-ref' => $type . '/' . $slug];
        if ($field !== null && $field !== '') {
            $attrs['data-ledric-field'] = $field;
        }
        return $attrs;
    }

    /**
     * Same as `refAttrs()` but pre-rendered as inline HTML attributes
     * so it can be dropped straight into a Blade element. Returns the
     * empty string when the entry is missing type/slug — safe to spread.
     *
     * @param  array<string, mixed>|object|string  $entry
     */
    public static function refAttrsHtml($entry, ?string $field = null): string
    {
        $attrs = self::refAttrs($entry, $field);
        $out = '';
        foreach ($attrs as $k => $v) {
            $out .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }
        return $out;
    }

    /**
     * Emit the `<script>` block that loads the inline editor and seeds
     * its preview-mode globals. Returns an empty string when the
     * current user isn't a permitted admin — safe to drop in the
     * shared layout for everyone.
     */
    public static function loaderHtml(): string
    {
        if (!self::canPreview()) {
            return '';
        }

        $prefix = self::adminPrefix();
        $active = self::previewActive() ? 'true' : 'false';

        return sprintf(
            '<script>window.LEDRIC_PREVIEW_AVAILABLE=true;window.LEDRIC_PREVIEW=%s;</script>'
            . '<script src="%s/inline.js" defer></script>',
            $active,
            htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Whether the current Laravel user is permitted to use the inline
     * editor / preview drafts. Driven by `ledric.admin.user_ids`.
     */
    public static function canPreview(): bool
    {
        $auth = function_exists('auth') ? auth() : null;
        $user = $auth !== null ? $auth->user() : null;
        if ($user === null) {
            return false;
        }
        $allowed = (array) config('ledric.admin.user_ids', []);
        if (empty($allowed)) {
            return false;
        }
        $userKey = (string) $user->getAuthIdentifier();
        return in_array($userKey, array_map('strval', $allowed), true);
    }

    /**
     * Whether preview mode is currently active for this request.
     * Combines a permission check (so a stale cookie doesn't keep
     * leaking drafts to a deauthorized user) with the cookie value.
     */
    public static function previewActive(): bool
    {
        if (!self::canPreview()) {
            return false;
        }
        $req = function_exists('request') ? request() : null;
        if ($req === null) {
            return false;
        }
        return $req->cookie(self::PREVIEW_COOKIE) === '1';
    }

    public static function adminPrefix(): string
    {
        return '/' . trim((string) config('ledric.admin.route_prefix', 'ledric-admin'), '/');
    }

    /**
     * @param  array<string, mixed>|object|string  $entry
     * @return array{0: string|null, 1: string|null}
     */
    private static function extractRef($entry): array
    {
        if (is_string($entry)) {
            $parts = explode('/', $entry, 2);
            return [$parts[0] ?? null, $parts[1] ?? null];
        }
        if (is_array($entry)) {
            return [
                isset($entry['type']) ? (string) $entry['type'] : null,
                isset($entry['slug']) ? (string) $entry['slug'] : null,
            ];
        }
        if (is_object($entry)) {
            $type = property_exists($entry, 'type') ? $entry->type : null;
            $slug = property_exists($entry, 'slug') ? $entry->slug : null;
            return [
                $type !== null ? (string) $type : null,
                $slug !== null ? (string) $slug : null,
            ];
        }
        return [null, null];
    }
}

<?php

namespace Ledric\Laravel\Tests;

use Ledric\Laravel\Inline;

class InlineTest extends TestCase
{
    public function test_refAttrs_from_array(): void
    {
        $entry = ['type' => 'post', 'slug' => 'hello', 'fields' => []];
        $this->assertSame(
            ['data-ledric-ref' => 'post/hello'],
            Inline::refAttrs($entry)
        );
        $this->assertSame(
            ['data-ledric-ref' => 'post/hello', 'data-ledric-field' => 'title'],
            Inline::refAttrs($entry, 'title')
        );
    }

    public function test_refAttrs_from_string(): void
    {
        $this->assertSame(
            ['data-ledric-ref' => 'post/hello', 'data-ledric-field' => 'body'],
            Inline::refAttrs('post/hello', 'body')
        );
    }

    public function test_refAttrs_returns_empty_when_missing_fields(): void
    {
        $this->assertSame([], Inline::refAttrs([]));
        $this->assertSame([], Inline::refAttrs(['type' => 'post'])); // no slug
    }

    public function test_refAttrs_html_escapes(): void
    {
        $html = Inline::refAttrsHtml(['type' => 'post', 'slug' => 'a"b'], 'title');
        $this->assertStringContainsString('data-ledric-ref="post/a&quot;b"', $html);
        $this->assertStringContainsString('data-ledric-field="title"', $html);
    }

    public function test_loaderHtml_empty_for_unauthenticated_user(): void
    {
        $this->app['config']->set('ledric.admin.user_ids', ['7']);
        $this->assertSame('', Inline::loaderHtml());
    }

    public function test_loaderHtml_empty_for_user_not_in_allowlist(): void
    {
        $this->app['config']->set('ledric.admin.user_ids', ['7']);
        $this->actingAs(new FakeAuthUser(99));
        $this->assertSame('', Inline::loaderHtml());
    }

    public function test_loaderHtml_emits_for_admin(): void
    {
        $this->app['config']->set('ledric.admin.user_ids', ['7']);
        $this->actingAs(new FakeAuthUser(7));

        $html = Inline::loaderHtml();
        $this->assertStringContainsString('window.LEDRIC_PREVIEW_AVAILABLE=true', $html);
        $this->assertStringContainsString('window.LEDRIC_PREVIEW=false', $html);
        $this->assertStringContainsString('/ledric-admin/inline.js', $html);
    }

    public function test_loaderHtml_marks_preview_active_when_cookie_set(): void
    {
        $this->app['config']->set('ledric.admin.user_ids', ['7']);
        $this->actingAs(new FakeAuthUser(7));

        // Bake the cookie into the bound request — Inline::previewActive
        // reads via request()->cookie().
        $req = $this->app['request'];
        $req->cookies->set(Inline::PREVIEW_COOKIE, '1');

        $html = Inline::loaderHtml();
        $this->assertStringContainsString('window.LEDRIC_PREVIEW=true', $html);
    }
}

class FakeAuthUser implements \Illuminate\Contracts\Auth\Authenticatable
{
    /** @var mixed */
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function getAuthIdentifierName() { return 'id'; }
    public function getAuthIdentifier() { return $this->id; }
    public function getAuthPassword() { return ''; }
    public function getRememberToken() { return null; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName() { return ''; }
}

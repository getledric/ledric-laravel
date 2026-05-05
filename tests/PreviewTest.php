<?php

namespace Ledric\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Ledric\Laravel\Cache\CachedClient;
use Ledric\Laravel\Inline;

class PreviewTest extends TestCase
{
    public function test_default_reads_pass_published_true(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['slug' => 'a'])));

        $cached = $this->app->make(CachedClient::class);
        $cached->find(['type' => 'post']);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertTrue($body['args']['published']);
    }

    public function test_preview_drops_published_filter(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['slug' => 'a'])));

        $cached = $this->app->make(CachedClient::class);
        $cached->preview()->find(['type' => 'post']);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertArrayNotHasKey('published', $body['args']);
    }

    public function test_explicit_published_arg_is_preserved(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['slug' => 'a'])));

        $cached = $this->app->make(CachedClient::class);
        $cached->preview()->find(['type' => 'post', 'published' => true]);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertTrue($body['args']['published']);
    }

    public function test_preview_and_published_use_separate_cache_entries(): void
    {
        // Two responses for the same args — different bodies prove the
        // cache key differs.
        $this->mockHandler->append(new Response(200, [], json_encode(['slug' => 'a', 'v' => 'pub'])));
        $this->mockHandler->append(new Response(200, [], json_encode(['slug' => 'a', 'v' => 'draft'])));

        $cached = $this->app->make(CachedClient::class);

        $pub = $cached->find(['type' => 'post']);
        $draft = $cached->preview()->find(['type' => 'post']);

        $this->assertSame('pub', $pub['v']);
        $this->assertSame('draft', $draft['v']);
        $this->assertCount(2, $this->history);
    }

    public function test_preview_active_reads_cookie_and_admin_check(): void
    {
        $this->app['config']->set('ledric.admin.user_ids', ['7']);

        // No user — never active even if cookie set
        $this->app['request']->cookies->set(Inline::PREVIEW_COOKIE, '1');
        $this->assertFalse(Inline::previewActive());

        // Admin user, cookie set — active
        $this->actingAs(new \Ledric\Laravel\Tests\FakeAuthUser(7));
        $this->app['request']->cookies->set(Inline::PREVIEW_COOKIE, '1');
        $this->assertTrue(Inline::previewActive());

        // Admin user, cookie cleared — inactive
        $this->app['request']->cookies->remove(Inline::PREVIEW_COOKIE);
        $this->assertFalse(Inline::previewActive());
    }
}

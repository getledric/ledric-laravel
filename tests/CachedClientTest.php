<?php

namespace Ledric\Laravel\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Ledric\Laravel\Cache\CachedClient;
use Ledric\Laravel\Exceptions\LedricUnavailableException;

class CachedClientTest extends TestCase
{
    public function test_repeated_reads_within_ttl_hit_cache(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['slug' => 'a'])));
        // Second response would be different, proving we didn't fetch again.
        $this->mockHandler->append(new Response(200, [], json_encode(['slug' => 'b'])));

        $cached = $this->app->make(CachedClient::class);
        $first  = $cached->read('post', 'a');
        $second = $cached->read('post', 'a');

        $this->assertSame('a', $first['slug']);
        $this->assertSame('a', $second['slug']);
        $this->assertCount(1, $this->history);
    }

    public function test_stale_cache_serves_when_ledric_unavailable(): void
    {
        // Prime the cache.
        $this->mockHandler->append(new Response(200, [], json_encode(['slug' => 'a', 'v' => 1])));
        $cached = $this->app->make(CachedClient::class);
        $cached->read('post', 'a');

        // Force expiry of the fresh window. Trick: bump the clock by
        // setting ttl=0 dynamically — simpler than time-mocking.
        $this->app['config']->set('ledric.cache.ttl', 0);
        $this->app->forgetInstance(CachedClient::class);
        $cached = $this->app->make(CachedClient::class);

        // Now ledric is "down" — connection fails. We should still get
        // the cached value.
        $this->mockHandler->append(new ConnectException(
            'connection refused',
            new Psr7Request('POST', 'http://127.0.0.1:3030/rpc')
        ));

        $entry = $cached->read('post', 'a');
        $this->assertSame('a', $entry['slug']);
    }

    public function test_unavailable_with_no_cache_throws(): void
    {
        $this->mockHandler->append(new ConnectException(
            'connection refused',
            new Psr7Request('POST', 'http://127.0.0.1:3030/rpc')
        ));

        $cached = $this->app->make(CachedClient::class);

        $this->expectException(LedricUnavailableException::class);
        $cached->read('post', 'a');
    }

    public function test_writes_invalidate_cache(): void
    {
        // Prime
        $this->mockHandler->append(new Response(200, [], json_encode(['slug' => 'a', 'v' => 1])));
        $cached = $this->app->make(CachedClient::class);
        $cached->read('post', 'a');

        // Write to the same type
        $this->mockHandler->append(new Response(200, [], json_encode(['ok' => true])));
        $cached->publishEntry('post', 'a');

        // Read again — should hit ledric, not cache
        $this->mockHandler->append(new Response(200, [], json_encode(['slug' => 'a', 'v' => 2])));
        $entry = $cached->read('post', 'a');

        $this->assertSame(2, $entry['v']);
        $this->assertCount(3, $this->history);
    }

    public function test_is_healthy_caches_ping_result(): void
    {
        $this->mockHandler->append(new Response(200, [], 'ledric'));

        $cached = $this->app->make(CachedClient::class);

        $this->assertTrue($cached->isHealthy());
        // Second call within the health TTL should not re-ping.
        $this->assertTrue($cached->isHealthy());

        $this->assertCount(1, $this->history);
    }

    public function test_is_healthy_returns_false_on_connect_failure(): void
    {
        $this->mockHandler->append(new ConnectException(
            'connection refused',
            new Psr7Request('GET', 'http://127.0.0.1:3030/')
        ));

        $cached = $this->app->make(CachedClient::class);
        $this->assertFalse($cached->isHealthy());
    }
}

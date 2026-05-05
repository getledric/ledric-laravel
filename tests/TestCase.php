<?php

namespace Ledric\Laravel\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Ledric\Laravel\LedricServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var MockHandler */
    protected $mockHandler;

    /** @var array<int, array<string, mixed>>  Captured Guzzle history */
    protected $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Replace the singleton Guzzle with one backed by MockHandler so
        // tests don't actually hit a server. History middleware lets us
        // assert "yes the controller forwarded the right thing."
        $this->mockHandler = new MockHandler();
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->history));

        $this->app->singleton(Guzzle::class, function () use ($stack) {
            return new Guzzle([
                'base_uri'    => 'http://127.0.0.1:3030/',
                'handler'     => $stack,
                'http_errors' => false,
            ]);
        });
    }

    protected function getPackageProviders($app): array
    {
        return [LedricServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ledric.base_url', 'http://127.0.0.1:3030');
        $app['config']->set('ledric.admin_key', 'lka_admin_test');
        $app['config']->set('ledric.reader_key', 'lkr_reader_test');
        $app['config']->set('ledric.env', 'main');

        // Faster tests — keep the cache windows tight enough to exercise
        // expiry without sleeping.
        $app['config']->set('ledric.cache.ttl', 1);
        $app['config']->set('ledric.cache.stale_ttl', 60);

        // Use the array cache store so tests don't litter the filesystem.
        $app['config']->set('cache.default', 'array');
    }
}

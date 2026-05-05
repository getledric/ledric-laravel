<?php

namespace Ledric\Laravel\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Ledric\Laravel\Client;
use Ledric\Laravel\Exceptions\LedricException;
use Ledric\Laravel\Exceptions\LedricUnavailableException;

class ClientTest extends TestCase
{
    public function test_read_round_trips_through_rpc(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'id'      => 'abc',
            'type'    => 'post',
            'slug'    => 'hello',
            'fields'  => ['title' => 'Hello'],
            'version' => 1,
        ])));

        $client = $this->app->make(Client::class);
        $entry  = $client->read('post', 'hello');

        $this->assertSame('hello', $entry['slug']);

        $this->assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/rpc', $req->getUri()->getPath());

        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('read', $body['tool']);
        $this->assertSame('post', $body['args']['type']);
    }

    public function test_connect_exception_becomes_unavailable(): void
    {
        $this->mockHandler->append(new ConnectException(
            'connection refused',
            new Psr7Request('POST', 'http://127.0.0.1:3030/rpc')
        ));

        $client = $this->app->make(Client::class);

        $this->expectException(LedricUnavailableException::class);
        $client->read('post', 'hello');
    }

    public function test_5xx_becomes_unavailable_too(): void
    {
        $this->mockHandler->append(new Response(503, [], 'down for maintenance'));

        $client = $this->app->make(Client::class);

        $this->expectException(LedricUnavailableException::class);
        $client->read('post', 'hello');
    }

    public function test_4xx_surfaces_as_ledric_exception(): void
    {
        // 4xx is not "unavailable" — it's a real bug or auth issue and
        // should not trigger stale-cache fallback.
        $this->mockHandler->append(new Response(400, [], json_encode(['error' => 'bad type'])));

        $client = $this->app->make(Client::class);

        $this->expectException(LedricException::class);
        $client->read('post', 'hello');
    }

    public function test_writes_use_admin_key_not_reader(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['ok' => true])));

        $client = $this->app->make(Client::class);
        $client->draftEntry(['type' => 'post', 'slug' => 'x', 'content' => []]);

        $auth = $this->history[0]['request']->getHeaderLine('Authorization');
        $this->assertSame('Bearer lka_admin_test', $auth);
    }

    public function test_reads_use_reader_key_when_set(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([])));

        $client = $this->app->make(Client::class);
        $client->find(['type' => 'post']);

        $auth = $this->history[0]['request']->getHeaderLine('Authorization');
        $this->assertSame('Bearer lkr_reader_test', $auth);
    }
}

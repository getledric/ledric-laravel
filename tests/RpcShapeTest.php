<?php

namespace Ledric\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Ledric\Laravel\Client;

/**
 * Pin the wire shape of every method that posts to /rpc against the
 * actual MCP/RPC schemas. The first round of bugs (wrong tool names,
 * missing ref wrapping) sailed through the unit suite because Guzzle's
 * MockHandler doesn't validate args. These tests assert the request
 * body shape so that class of bug regresses loudly.
 */
class RpcShapeTest extends TestCase
{
    private function captureBody(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    public function test_read_uses_ref_wrapped_args(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => null])));

        $this->app->make(Client::class)->read('post', 'hello');

        $body = $this->captureBody();
        $this->assertSame('read', $body['tool']);
        $this->assertSame(['type' => 'post', 'slug' => 'hello'], $body['args']['ref']);
    }

    public function test_draftEntry_creates_without_ref(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => ['slug' => 'auto']])));

        $this->app->make(Client::class)->draftEntry('post', ['title' => 'X']);

        $body = $this->captureBody();
        $this->assertSame('draft', $body['tool']);
        $this->assertSame('post', $body['args']['type']);
        $this->assertSame(['title' => 'X'], $body['args']['fields']);
        // No slug → no ref → ledric mints the slug.
        $this->assertArrayNotHasKey('ref', $body['args']);
    }

    public function test_draftEntry_updates_with_ref_when_slug_passed(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => ['slug' => 'x']])));

        $this->app->make(Client::class)->draftEntry('post', ['title' => 'X'], 'x', [
            'parent_version' => 3,
            'author'         => 'james',
        ]);

        $body = $this->captureBody();
        $this->assertSame(['type' => 'post', 'slug' => 'x'], $body['args']['ref']);
        $this->assertSame(3, $body['args']['parent_version']);
        $this->assertSame('james', $body['args']['author']);
    }

    public function test_publishEntry_posts_publish_with_ref(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => ['slug' => 'x']])));

        $this->app->make(Client::class)->publishEntry('post', 'x');

        $body = $this->captureBody();
        $this->assertSame('publish', $body['tool']);
        $this->assertSame(['type' => 'post', 'slug' => 'x'], $body['args']['ref']);
        // type/slug must NOT remain as siblings — strict schema rejects.
        $this->assertArrayNotHasKey('type', $body['args']);
        $this->assertArrayNotHasKey('slug', $body['args']);
    }

    public function test_publishEntry_carries_version_when_given(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => null])));

        $this->app->make(Client::class)->publishEntry('post', 'x', 3);

        $this->assertSame(3, $this->captureBody()['args']['version']);
    }

    public function test_renameEntry_posts_rename_entry_with_new_slug(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => ['slug' => 'y']])));

        $this->app->make(Client::class)->renameEntry('post', 'x', 'y');

        $body = $this->captureBody();
        $this->assertSame('rename_entry', $body['tool']);
        $this->assertSame(['type' => 'post', 'slug' => 'x'], $body['args']['ref']);
        $this->assertSame('y', $body['args']['new_slug']);
    }

    public function test_addEntryTags_wraps_ref(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => ['featured']])));

        $this->app->make(Client::class)->addEntryTags('post', 'x', ['featured']);

        $body = $this->captureBody();
        $this->assertSame('add_entry_tags', $body['tool']);
        $this->assertSame(['type' => 'post', 'slug' => 'x'], $body['args']['ref']);
        $this->assertSame(['featured'], $body['args']['tags']);
    }

    public function test_searchEntries_posts_find_tool_with_q(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'result' => ['results' => [], 'total' => 0],
        ])));

        $this->app->make(Client::class)->searchEntries(['type' => 'post', 'q' => 'astro']);

        $body = $this->captureBody();
        $this->assertSame('find', $body['tool']);   // NOT search_entries
        $this->assertSame('astro', $body['args']['q']);
    }

    public function test_searchEntries_throws_without_q(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(Client::class)->searchEntries(['type' => 'post']);
    }

    public function test_listTypes_uses_describe_model(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'result' => [
                'name'  => 'ledric',
                'types' => [
                    ['name' => 'post', 'fields' => []],
                    ['name' => 'page', 'fields' => []],
                ],
            ],
        ])));

        $types = $this->app->make(Client::class)->listTypes();

        $this->assertSame('describe_model', $this->captureBody()['tool']);
        $this->assertSame(['post', 'page'], array_column($types, 'name'));
    }

    public function test_find_passes_through_with_no_arg_translation(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'result' => ['results' => [], 'total' => 0],
        ])));

        $this->app->make(Client::class)->find([
            'type'  => 'post',
            'limit' => 10,
            'tags'  => ['featured'],
        ]);

        $body = $this->captureBody();
        $this->assertSame('find', $body['tool']);
        $this->assertSame('post', $body['args']['type']);
        $this->assertSame(10, $body['args']['limit']);
        $this->assertSame(['featured'], $body['args']['tags']);
    }
}

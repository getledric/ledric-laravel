<?php

namespace Ledric\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Ledric\Laravel\Client;

/**
 * Pin the wire shape of every method that posts to /rpc.
 *
 * These would have caught the original wave of bugs: wrong tool names
 * (draft_entry vs draft), missing ref wrapping, search_entries posted
 * to a tool that doesn't exist, list_types posted to a tool that
 * doesn't exist. The unit suite passed before because Guzzle's mock
 * handler doesn't validate args — only a live ledric does. The e2e
 * harness covers that, but these locked-in shape checks let us catch
 * regressions cheaply.
 */
class RpcShapeTest extends TestCase
{
    private function captureBody(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    public function test_draftEntry_posts_draft_tool_with_fields_and_ref(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => ['slug' => 'x']])));

        $this->app->make(Client::class)->draftEntry([
            'type'    => 'post',
            'slug'    => 'x',
            'content' => ['title' => 'X'],   // legacy alias for fields
            'schema_version' => 1,           // dropped — strict schema rejects
        ]);

        $body = $this->captureBody();
        $this->assertSame('draft', $body['tool']);
        $this->assertSame('post', $body['args']['type']);
        $this->assertSame(['type' => 'post', 'slug' => 'x'], $body['args']['ref']);
        $this->assertSame(['title' => 'X'], $body['args']['fields']);
        $this->assertArrayNotHasKey('content', $body['args']);
        $this->assertArrayNotHasKey('schema_version', $body['args']);
        $this->assertArrayNotHasKey('slug', $body['args']);
    }

    public function test_publishEntry_posts_publish_tool_with_ref_only(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => ['slug' => 'x']])));

        $this->app->make(Client::class)->publishEntry([
            'type' => 'post',
            'slug' => 'x',
        ]);

        $body = $this->captureBody();
        $this->assertSame('publish', $body['tool']);
        $this->assertSame(['type' => 'post', 'slug' => 'x'], $body['args']['ref']);
        // type/slug must NOT remain as siblings — publish's strict
        // schema rejects unknown keys.
        $this->assertArrayNotHasKey('type', $body['args']);
        $this->assertArrayNotHasKey('slug', $body['args']);
    }

    public function test_publishEntry_preserves_explicit_ref(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => null])));

        $this->app->make(Client::class)->publishEntry([
            'ref'     => ['type' => 'post', 'slug' => 'x'],
            'version' => 3,
        ]);

        $body = $this->captureBody();
        $this->assertSame(['type' => 'post', 'slug' => 'x'], $body['args']['ref']);
        $this->assertSame(3, $body['args']['version']);
    }

    public function test_renameEntry_posts_rename_entry_with_ref(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => ['slug' => 'y']])));

        $this->app->make(Client::class)->renameEntry([
            'type'     => 'post',
            'slug'     => 'x',
            'new_slug' => 'y',
        ]);

        $body = $this->captureBody();
        $this->assertSame('rename_entry', $body['tool']);
        $this->assertSame(['type' => 'post', 'slug' => 'x'], $body['args']['ref']);
        $this->assertSame('y', $body['args']['new_slug']);
    }

    public function test_addEntryTags_wraps_ref(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['result' => ['featured']])));

        $this->app->make(Client::class)->addEntryTags([
            'type' => 'post',
            'slug' => 'x',
            'tags' => ['featured'],
        ]);

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

        $this->app->make(Client::class)->searchEntries([
            'type' => 'post',
            'q'    => 'astro',
        ]);

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

        $body = $this->captureBody();
        $this->assertSame('describe_model', $body['tool']);
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

<?php

namespace Ledric\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Ledric\Laravel\Cache\CachedClient;

/**
 * @method static array|null read(string $type, string $slug, array $opts = [])
 * @method static array find(array $args)
 * @method static array searchEntries(array $args)
 * @method static array listTypes()
 * @method static array describeModel()
 * @method static array|null getAsset(string $id)
 * @method static array draftEntry(string $type, array $fields, ?string $slug = null, array $opts = [])
 * @method static array publishEntry(string $type, string $slug, ?int $version = null)
 * @method static array renameEntry(string $type, string $slug, string $newSlug, ?string $locale = null)
 * @method static array deleteEntry(string $type, string $slug)
 * @method static array addEntryTags(string $type, string $slug, array $tags)
 * @method static array removeEntryTags(string $type, string $slug, array $tags)
 * @method static array alterType(array $args)
 * @method static bool isHealthy()
 * @method static void flush(?string $type = null)
 * @method static \Ledric\Laravel\Client client()
 * @method static \Ledric\Laravel\Cache\CachedClient preview()
 * @method static \Ledric\Laravel\Cache\CachedClient published()
 * @method static bool isPreview()
 *
 * @see \Ledric\Laravel\Cache\CachedClient
 */
class Ledric extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CachedClient::class;
    }
}

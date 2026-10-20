<?php

declare(strict_types=1);

namespace Pype\Cache;

use Psr\SimpleCache\CacheInterface;
use Pype\Application;
use Pype\Database\Connection;
use Pype\Http\Request;
use Pype\Provider;

/**
 * Binds Cache (also as Psr\SimpleCache\CacheInterface) and its Store from config/cache.php.
 * CACHE_DRIVER: file (default), apcu, database, array (tests), null (off), or the class name
 * of your own Store. Nothing is built until something asks for the cache.
 */
final class CacheProvider implements Provider
{
    public function register(Application $app): void
    {
        $container = $app->container;

        $container->singleton(Store::class, fn ($c) => match ($driver = (string) $app->config('cache.driver', 'file')) {
            'file' => new FileStore($app->path($app->config('cache.path', 'storage/cache'))),
            'apcu' => new ApcuStore(),
            'database' => new DatabaseStore($c->get(Connection::class), $app->config('cache.table', 'cache')),
            'array' => new ArrayStore(),
            'null' => new NullStore(),
            default => $c->get($driver),
        });

        $container->singleton(Cache::class, fn ($c) => new Cache(
            $c->get(Store::class),
            (string) $app->config('cache.prefix', 'pype.'),
            // One request at a time per process, so the bound request is the current one.
            request: static fn (): ?Request => $container->resolved(Request::class) ? $container->get(Request::class) : null,
        ));

        $container->singleton(CacheInterface::class, fn ($c) => $c->get(Cache::class));
    }
}

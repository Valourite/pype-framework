<?php

declare(strict_types=1);

namespace Pype\Database;

use Pype\Application;
use Pype\Provider;

/**
 * Binds Connection (lazy — no connection is opened until first query) and Migrator
 * from config/database.php. Type-hint Connection in any stage or stage to use it.
 */
final class DatabaseProvider implements Provider
{
    public function register(Application $app): void
    {
        $app->container->singleton(Connection::class, function () use ($app) {
            $name = $app->config('database.default', 'sqlite');
            $config = $app->config("database.connections.{$name}");

            if (!is_array($config)) {
                throw new \RuntimeException("Database connection [{$name}] is not configured.");
            }

            return new Connection($config, $app->basePath);
        });

        $app->container->singleton(Migrator::class, fn ($c) => new Migrator(
            $c->get(Connection::class),
            $app->path($app->config('database.migrations', 'database/migrations')),
        ));
    }
}

<?php

declare(strict_types=1);

namespace Pype\Database;

use Pype\Application;
use Pype\Provider;
use Pype\RateLimit\ApcuRateLimiter;
use Pype\RateLimit\ArrayRateLimiter;
use Pype\RateLimit\DatabaseRateLimiter;
use Pype\RateLimit\RateLimiter;

/**
 * Binds Connection (lazy — no connection is opened until first query) and Migrator
 * from config/database.php, and the RateLimiter used by Throttle (database.rate_limits).
 * Type-hint Connection in any stage or handler to use it.
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

        $app->container->singleton(RateLimiter::class, fn ($c) => match ($driver = $app->config('database.rate_limits.driver', 'database')) {
            'database' => new DatabaseRateLimiter($c->get(Connection::class), $app->config('database.rate_limits.table', 'rate_limits')),
            'apcu' => new ApcuRateLimiter(),
            'array' => new ArrayRateLimiter(),
            default => $c->get($driver),   // a class name of your own limiter
        });
    }
}

<?php

declare(strict_types=1);

namespace Pype\Queue;

use Pype\Application;
use Pype\Database\Connection;
use Pype\Provider;

/**
 * Binds the Queue from config/queue.php. QUEUE_DRIVER: sync (runs jobs right away), database
 * (production: run `bin/pype queue:work`), array (tests), or the class name of your own Queue.
 */
final class QueueProvider implements Provider
{
    public function register(Application $app): void
    {
        $app->container->singleton(Queue::class, fn ($c) => match ($driver = (string) $app->config('queue.driver', 'sync')) {
            'sync' => new SyncQueue($c),
            'database' => new DatabaseQueue(
                $c->get(Connection::class),
                (string) $app->config('queue.table', 'jobs'),
                (string) $app->config('queue.failed_table', 'failed_jobs'),
                (string) $app->config('queue.default', 'default'),
                (int) $app->config('queue.retry_after', 90),
            ),
            'array' => new ArrayQueue((string) $app->config('queue.default', 'default')),
            default => $c->get($driver),
        });
    }
}

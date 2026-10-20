<?php

declare(strict_types=1);

namespace Pype\Queue;

use DateInterval;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Runs each job the moment it is pushed, in the same process, and throws if it fails. For
 * development and tests: nothing to start, and mistakes show up right away. Delays are ignored.
 */
final class SyncQueue implements Queue
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function push(object $job, ?string $queue = null, int $delay = 0): string
    {
        Payload::encode($job);   // same rules as a real queue: must be serializable, needs handle()
        Worker::call($this->container, $job);

        return 'sync';
    }

    public function later(int|DateInterval $delay, object $job, ?string $queue = null): string
    {
        return $this->push($job, $queue);
    }

    public function pop(array $queues): ?QueuedJob
    {
        return null;
    }

    public function delete(QueuedJob $job): void
    {
    }

    public function release(QueuedJob $job, int $delay): void
    {
    }

    public function fail(QueuedJob $job, Throwable $e): void
    {
    }

    public function size(?string $queue = null): int
    {
        return 0;
    }
}

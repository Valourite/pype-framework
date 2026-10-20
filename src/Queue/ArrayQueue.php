<?php

declare(strict_types=1);

namespace Pype\Queue;

use Closure;
use DateInterval;
use Throwable;

/**
 * Jobs kept in memory, for tests: assert what was pushed, or run them with a Worker.
 *
 *   $this->assertCount(1, $queue->pushed(SendReceipt::class));
 */
final class ArrayQueue implements Queue, FailedJobs
{
    /** @var array<int, array{queue: string, payload: string, attempts: int, available_at: int, reserved: bool}> */
    private array $jobs = [];

    /** @var array<int, array{queue: string, name: string, payload: string, exception: string, failed_at: int}> */
    private array $failed = [];

    private int $nextId = 1;

    /** @param (Closure(): int)|null $now  the clock, for tests */
    public function __construct(
        private readonly string $default = 'default',
        private readonly ?Closure $now = null,
    ) {
    }

    public function push(object $job, ?string $queue = null, int $delay = 0): string
    {
        $id = $this->nextId++;
        $this->jobs[$id] = ['queue' => $queue ?? $this->default, 'payload' => Payload::encode($job), 'attempts' => 0, 'available_at' => $this->now() + max(0, $delay), 'reserved' => false];

        return (string) $id;
    }

    public function later(int|DateInterval $delay, object $job, ?string $queue = null): string
    {
        return $this->push($job, $queue, Delay::seconds($delay, $this->now()));
    }

    public function pop(array $queues): ?QueuedJob
    {
        foreach ($this->jobs as $id => $row) {
            if (!$row['reserved'] && $row['available_at'] <= $this->now() && in_array($row['queue'], $queues, true)) {
                $this->jobs[$id]['reserved'] = true;
                $attempts = ++$this->jobs[$id]['attempts'];

                return new QueuedJob((string) $id, $row['queue'], $attempts, Payload::decode($row['payload']), $row['payload']);
            }
        }

        return null;
    }

    public function delete(QueuedJob $job): void
    {
        unset($this->jobs[(int) $job->id]);
    }

    public function release(QueuedJob $job, int $delay): void
    {
        if (isset($this->jobs[(int) $job->id])) {
            $this->jobs[(int) $job->id]['reserved'] = false;
            $this->jobs[(int) $job->id]['available_at'] = $this->now() + max(0, $delay);
        }
    }

    public function fail(QueuedJob $job, Throwable $e): void
    {
        $this->failed[$this->nextId++] = ['queue' => $job->queue, 'name' => $job->name(), 'payload' => $job->payload, 'exception' => Payload::exception($e), 'failed_at' => $this->now()];
        $this->delete($job);
    }

    public function size(?string $queue = null): int
    {
        return count(array_filter($this->jobs, fn ($row) => $queue === null || $row['queue'] === $queue));
    }

    /**
     * The jobs waiting (not yet run), optionally only those of one class.
     *
     * @template T of object
     * @param class-string<T>|null $class
     * @return list<T>
     */
    public function pushed(?string $class = null): array
    {
        $jobs = array_map(fn ($row) => Payload::decode($row['payload']), array_values($this->jobs));

        return array_values(array_filter($jobs, fn ($job) => $class === null || $job instanceof $class));
    }

    public function failed(): array
    {
        $rows = [];

        foreach (array_reverse($this->failed, true) as $id => $row) {
            $rows[] = ['id' => (string) $id] + array_diff_key($row, ['payload' => 1]);
        }

        return $rows;
    }

    public function retry(string $id): bool
    {
        $row = $this->failed[(int) $id] ?? null;

        if ($row === null) {
            return false;
        }

        unset($this->failed[(int) $id]);
        $this->jobs[$this->nextId++] = ['queue' => $row['queue'], 'payload' => $row['payload'], 'attempts' => 0, 'available_at' => $this->now(), 'reserved' => false];

        return true;
    }

    public function forget(string $id): bool
    {
        $found = isset($this->failed[(int) $id]);
        unset($this->failed[(int) $id]);

        return $found;
    }

    private function now(): int
    {
        return $this->now === null ? time() : ($this->now)();
    }
}

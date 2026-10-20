<?php

declare(strict_types=1);

namespace Pype\Queue;

use Closure;
use DateInterval;
use Pype\Database\Connection;
use Throwable;

/**
 * Jobs in a table, shared by every server and worker using the database:
 *   jobs(id, queue, name, payload, attempts, available_at, reserved_at, created_at)
 *   failed_jobs(id, queue, name, payload, exception, failed_at)
 *
 * A worker claims a job inside a transaction: `FOR UPDATE SKIP LOCKED` on MySQL 8 and
 * Postgres, so workers never wait on each other or take the same job; SQLite's BEGIN
 * IMMEDIATE lets one writer in at a time. A job reserved for longer than $retryAfter seconds
 * (its worker died) can be claimed again, so keep it above the longest job's run time.
 */
final class DatabaseQueue implements Queue, FailedJobs
{
    /** @param (Closure(): int)|null $now  the clock, for tests */
    public function __construct(
        private readonly Connection $db,
        private readonly string $table = 'jobs',
        private readonly string $failedTable = 'failed_jobs',
        private readonly string $default = 'default',
        private readonly int $retryAfter = 90,
        private readonly ?Closure $now = null,
    ) {
    }

    public function push(object $job, ?string $queue = null, int $delay = 0): string
    {
        $now = $this->now();

        return $this->db->table($this->table)->insert([
            'queue' => $queue ?? $this->default,
            'name' => $job::class,
            'payload' => Payload::encode($job),
            'attempts' => 0,
            'available_at' => $now + max(0, $delay),
            'reserved_at' => null,
            'created_at' => $now,
        ]);
    }

    public function later(int|DateInterval $delay, object $job, ?string $queue = null): string
    {
        return $this->push($job, $queue, Delay::seconds($delay, $this->now()));
    }

    public function pop(array $queues): ?QueuedJob
    {
        if ($queues === []) {
            return null;
        }

        $row = $this->db->transaction(function (Connection $db) use ($queues) {
            $now = $this->now();
            $table = $db->quote($this->table);
            $in = implode(', ', array_fill(0, count($queues), '?'));
            $lock = $db->driver() === 'sqlite' ? '' : ' FOR UPDATE SKIP LOCKED';

            $row = $db->first(
                "SELECT id, {$db->quote('queue')} AS q, payload, attempts FROM {$table}"
                . " WHERE {$db->quote('queue')} IN ({$in}) AND available_at <= ? AND (reserved_at IS NULL OR reserved_at <= ?)"
                . " ORDER BY id LIMIT 1{$lock}",
                [...$queues, $now, $now - $this->retryAfter],
            );

            if ($row !== null) {
                $db->execute("UPDATE {$table} SET reserved_at = ?, attempts = attempts + 1 WHERE id = ?", [$now, (int) $row['id']]);
            }

            return $row;
        });

        if ($row === null) {
            return null;
        }

        $id = (string) $row['id'];

        try {
            $job = Payload::decode((string) $row['payload']);
        } catch (Throwable $e) {
            // A class that no longer exists, a corrupt row: park it with the failed jobs.
            $this->fail(new QueuedJob($id, (string) $row['q'], (int) $row['attempts'] + 1, new \stdClass(), (string) $row['payload']), $e);

            return $this->pop($queues);
        }

        return new QueuedJob($id, (string) $row['q'], (int) $row['attempts'] + 1, $job, (string) $row['payload']);
    }

    public function delete(QueuedJob $job): void
    {
        $this->db->table($this->table)->where('id', (int) $job->id)->delete();
    }

    public function release(QueuedJob $job, int $delay): void
    {
        $this->db->table($this->table)->where('id', (int) $job->id)->update([
            'reserved_at' => null,
            'available_at' => $this->now() + max(0, $delay),
        ]);
    }

    public function fail(QueuedJob $job, Throwable $e): void
    {
        $this->db->transaction(function (Connection $db) use ($job, $e) {
            $db->table($this->failedTable)->insert([
                'queue' => $job->queue,
                'name' => $job->job instanceof \stdClass ? 'unknown' : $job->name(),
                'payload' => $job->payload,
                'exception' => Payload::exception($e),
                'failed_at' => $this->now(),
            ]);
            $db->table($this->table)->where('id', (int) $job->id)->delete();
        });
    }

    public function size(?string $queue = null): int
    {
        $query = $this->db->table($this->table);

        return ($queue === null ? $query : $query->where('queue', $queue))->count();
    }

    public function failed(): array
    {
        return array_map(static fn (array $row) => [
            'id' => (string) $row['id'],
            'queue' => (string) $row['queue'],
            'name' => (string) $row['name'],
            'exception' => (string) $row['exception'],
            'failed_at' => (int) $row['failed_at'],
        ], $this->db->table($this->failedTable)->select('id', 'queue', 'name', 'exception', 'failed_at')->orderBy('id', 'desc')->get());
    }

    public function retry(string $id): bool
    {
        return $this->db->transaction(function (Connection $db) use ($id) {
            $row = $db->table($this->failedTable)->where('id', (int) $id)->first();

            if ($row === null) {
                return false;
            }

            $now = $this->now();
            $db->table($this->table)->insert([
                'queue' => $row['queue'], 'name' => $row['name'], 'payload' => $row['payload'],
                'attempts' => 0, 'available_at' => $now, 'reserved_at' => null, 'created_at' => $now,
            ]);
            $db->table($this->failedTable)->where('id', (int) $id)->delete();

            return true;
        });
    }

    public function forget(string $id): bool
    {
        return $this->db->table($this->failedTable)->where('id', (int) $id)->delete() > 0;
    }

    private function now(): int
    {
        return $this->now === null ? time() : ($this->now)();
    }
}

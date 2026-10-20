<?php

declare(strict_types=1);

namespace Pype\Queue;

use DateInterval;
use Throwable;

/**
 * Background jobs. A job is a plain object: constructor data (ids, strings, arrays; no
 * services) and a handle() method whose parameters are filled from the container. It is
 * stored with serialize(), so the jobs table is as trusted as the code.
 *
 *   $queue->push(new SendReceipt($expense->id));
 *   $queue->later(60, new SendReceipt($expense->id), 'mail');
 *
 * Optional on the job: public int $tries (default 1), public int|array $backoff (seconds before
 * each retry), public ?int $timeout, and failed(Throwable $e) called once it has given up.
 */
interface Queue
{
    /** @return string the job id */
    public function push(object $job, ?string $queue = null, int $delay = 0): string;

    public function later(int|DateInterval $delay, object $job, ?string $queue = null): string;

    /**
     * Claim the next available job on one of $queues (attempts goes up by one).
     *
     * @param list<string> $queues
     */
    public function pop(array $queues): ?QueuedJob;

    /** Done: remove it. */
    public function delete(QueuedJob $job): void;

    /** Put it back, available again in $delay seconds. */
    public function release(QueuedJob $job, int $delay): void;

    /** Given up: move it to the failed jobs. */
    public function fail(QueuedJob $job, Throwable $e): void;

    public function size(?string $queue = null): int;
}

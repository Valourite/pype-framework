<?php

declare(strict_types=1);

namespace Pype\Queue;

/** A queue that keeps jobs that gave up, for `queue:failed`, `queue:retry` and `queue:forget`. */
interface FailedJobs
{
    /** @return list<array{id: string, queue: string, name: string, exception: string, failed_at: int}> newest first */
    public function failed(): array;

    /** Push a failed job back onto its queue with its attempts reset. */
    public function retry(string $id): bool;

    public function forget(string $id): bool;
}

<?php

declare(strict_types=1);

namespace Pype\Queue;

/** A job claimed by a worker: the job object plus where it came from. */
final class QueuedJob
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly int $attempts,
        public readonly object $job,
        public readonly string $payload,
    ) {
    }

    public function name(): string
    {
        return $this->job::class;
    }

    /** How often the job may run before it fails for good (its public $tries, default 1). */
    public function tries(): int
    {
        return max(1, (int) ($this->job->tries ?? 1));
    }

    /** Seconds to wait before the next try: the job's public $backoff (int, or one per retry; the last repeats). */
    public function backoff(): int
    {
        $backoff = $this->job->backoff ?? 0;

        if (is_array($backoff)) {
            $backoff = $backoff === [] ? 0 : $backoff[min($this->attempts, count($backoff)) - 1];
        }

        return max(0, (int) $backoff);
    }

    public function timeout(): ?int
    {
        return isset($this->job->timeout) ? (int) $this->job->timeout : null;
    }
}

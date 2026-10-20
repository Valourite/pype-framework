<?php

declare(strict_types=1);

namespace Pype\Queue;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Pype\Cache\Cache;
use Pype\Container\Container;
use Pype\Database\Connection;
use Pype\Pipeline\Invoker;
use Throwable;

/**
 * Runs queued jobs: `bin/pype queue:work`. A long-running process, like an OpenSwoole worker:
 * nothing about one job may be kept in a service for the next.
 *
 * Stops (after the job in hand) on SIGTERM/SIGINT, after --max-jobs or --max-time, above
 * --memory MB, or when `bin/pype queue:restart` was run (deploys: new code needs a new
 * process). Run it under a supervisor (systemd) that starts it again.
 */
final class Worker
{
    public const RESTART_KEY = 'queue.restart';

    private bool $stop = false;

    public function __construct(
        private readonly Queue $queue,
        private readonly ContainerInterface $container,
        private readonly ?LoggerInterface $log = null,
        private readonly ?Cache $cache = null,
    ) {
    }

    /**
     * @param list<string> $queues  checked in order: the first has priority
     * @return int how many jobs were processed
     */
    public function run(
        array $queues = ['default'],
        bool $once = false,
        bool $stopWhenEmpty = false,
        ?int $maxJobs = null,
        ?int $maxTime = null,
        int $memory = 128,
        float $sleep = 1.0,
        int $timeout = 60,
    ): int {
        $this->listenForSignals();
        $started = time();
        $restart = $this->cache?->get(self::RESTART_KEY);
        $processed = 0;

        while (!$this->stop) {
            $job = $this->next($queues);

            if ($job !== null) {
                $this->process($job, $timeout);
                $processed++;
            }

            if ($once || ($job === null && $stopWhenEmpty)) {
                break;
            }

            $reason = match (true) {
                $maxJobs !== null && $processed >= $maxJobs => 'max jobs',
                $maxTime !== null && time() - $started >= $maxTime => 'max time',
                memory_get_usage(true) >= $memory * 1024 * 1024 => 'memory limit',
                $this->cache !== null && $this->cache->get(self::RESTART_KEY) !== $restart => 'restart signal',
                default => null,
            };

            if ($reason !== null) {
                $this->log?->info('Queue worker stopping: {reason}', ['reason' => $reason, 'processed' => $processed]);
                break;
            }

            if ($job === null && $sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }

        return $processed;
    }

    /** Run one claimed job: delete it on success, retry it later or fail it on an exception. */
    public function process(QueuedJob $queued, int $timeout = 60): void
    {
        $start = hrtime(true);
        $this->alarm($queued->timeout() ?? $timeout, $queued);

        try {
            self::call($this->container, $queued->job);
            $this->queue->delete($queued);
            $this->log?->info('Job {job} done in {ms} ms', ['job' => $queued->name(), 'id' => $queued->id, 'ms' => round((hrtime(true) - $start) / 1e6, 1)]);
        } catch (Throwable $e) {
            $this->failed($queued, $e);
        } finally {
            $this->alarm(0, $queued);
        }
    }

    /** Call $job->handle() with its parameters filled from the container. */
    public static function call(ContainerInterface $container, object $job): mixed
    {
        return (new Invoker($container))->call([$job, 'handle'], $job, static fn () => null);
    }

    private function failed(QueuedJob $queued, Throwable $e): void
    {
        if ($queued->attempts < $queued->tries()) {
            $this->queue->release($queued, $queued->backoff());
            $this->log?->warning('Job {job} failed (attempt {attempt} of {tries}), retrying: {error}', [
                'job' => $queued->name(), 'id' => $queued->id, 'attempt' => $queued->attempts, 'tries' => $queued->tries(), 'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->queue->fail($queued, $e);
        $this->log?->error('Job {job} failed: {error}', ['job' => $queued->name(), 'id' => $queued->id, 'error' => $e->getMessage(), 'exception' => $e]);

        if (method_exists($queued->job, 'failed')) {
            try {
                $queued->job->failed($e);
            } catch (Throwable $hook) {
                $this->log?->error('Job {job} failed() hook threw: {error}', ['job' => $queued->name(), 'error' => $hook->getMessage()]);
            }
        }
    }

    /** @param list<string> $queues */
    private function next(array $queues): ?QueuedJob
    {
        try {
            return $this->queue->pop($queues);
        } catch (Throwable $e) {
            // Most likely the database went away: reconnect on the next try.
            $this->log?->error('Queue worker could not fetch a job: {error}', ['error' => $e->getMessage()]);

            if ($this->container instanceof Container && $this->container->resolved(Connection::class)) {
                $this->container->get(Connection::class)->disconnect();
            }

            sleep(1);

            return null;
        }
    }

    private function listenForSignals(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stop = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGQUIT, $stop);
    }

    /** A job running past its timeout kills the worker; the supervisor starts a new one and the job is claimed again after retry_after. */
    private function alarm(int $seconds, QueuedJob $queued): void
    {
        if (!function_exists('pcntl_alarm')) {
            return;
        }

        if ($seconds > 0) {
            pcntl_signal(SIGALRM, function () use ($queued, $seconds): void {
                $this->log?->error('Job {job} timed out after {seconds} s; worker exiting', ['job' => $queued->name(), 'id' => $queued->id, 'seconds' => $seconds]);
                exit(1);
            });
        }

        pcntl_alarm($seconds);
    }
}

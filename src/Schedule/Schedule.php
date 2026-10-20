<?php

declare(strict_types=1);

namespace Pype\Schedule;

use Closure;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Pype\Cache\Cache;
use Pype\Pipeline\Invoker;
use Pype\Queue\Queue;
use Throwable;

/**
 * The app's scheduled tasks, defined in routes/schedule.php and run by `bin/pype schedule:run`
 * (from cron, every minute) or `schedule:work`. Never loaded for web requests.
 *
 *   $schedule->command('expenses:recur')->dailyAt('01:00');
 *   $schedule->call(fn (SessionStore $s) => $s->gc())->hourly();
 *   $schedule->job(new PruneUploads())->daily();
 */
final class Schedule
{
    /** @var list<Task> */
    private array $tasks = [];

    private ?string $timezone = null;

    /** Timezone for the tasks defined after this call (default: PHP's). */
    public function timezone(string $timezone): self
    {
        new \DateTimeZone($timezone);
        $this->timezone = $timezone;

        return $this;
    }

    /** @param list<string> $args  e.g. ['--dry-run', '2026-10'] */
    public function command(string $name, array $args = []): Task
    {
        return $this->tasks[] = new Task('command', $name, $args, $this->timezone);
    }

    /** A closure; its parameters are filled from the container. */
    public function call(Closure $callback): Task
    {
        return $this->tasks[] = new Task('call', $callback, [], $this->timezone);
    }

    /** Push $job onto the queue when due; a worker runs it. */
    public function job(object $job, ?string $queue = null): Task
    {
        return $this->tasks[] = new Task('job', $job, [], $this->timezone, $queue);
    }

    /** @return list<Task> */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /** @return list<Task> */
    public function due(DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->tasks, fn (Task $t) => $t->isDue($now)));
    }

    /**
     * Run every due task, one after the other. A failing task is logged and never stops the
     * others.
     *
     * @param Closure(string, list<string>): int $runCommand  runs a bin/pype command, returns its exit code
     * @return list<array{task: Task, status: 'ran'|'skipped'|'failed', ms: float, error: ?string}>
     */
    public function run(DateTimeImmutable $now, ContainerInterface $container, Closure $runCommand): array
    {
        $log = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
        $invoker = new Invoker($container);
        $results = [];

        foreach ($this->due($now) as $task) {
            $start = hrtime(true);
            $status = 'ran';
            $error = null;

            try {
                $status = $this->runTask($task, $now, $container, $invoker, $runCommand) ? 'ran' : 'skipped';
            } catch (Throwable $e) {
                [$status, $error] = ['failed', $e->getMessage()];
                $log?->error('Scheduled task {task} failed: {error}', ['task' => $task->description(), 'error' => $e->getMessage(), 'exception' => $e]);
            }

            $ms = round((hrtime(true) - $start) / 1e6, 1);

            if ($status === 'ran') {
                $log?->info('Scheduled task {task} ran in {ms} ms', ['task' => $task->description(), 'ms' => $ms]);
            }

            $results[] = ['task' => $task, 'status' => $status, 'ms' => $ms, 'error' => $error];
        }

        return $results;
    }

    /** @return bool false if skipped (condition, another server, still running) */
    private function runTask(Task $task, DateTimeImmutable $now, ContainerInterface $container, Invoker $invoker, Closure $runCommand): bool
    {
        if ($task->condition() !== null && !$invoker->call($task->condition(), null, static fn () => null)) {
            return false;
        }

        $cache = null;

        if ($task->runsOnOneServer() || $task->overlapMinutes() !== null) {
            $cache = $container->has(\Pype\Cache\Store::class)
                ? $container->get(Cache::class)
                : throw new \LogicException('onOneServer() and withoutOverlapping() need the cache: register Pype\Cache\CacheProvider.');
        }

        // One server per minute: the first to add the key runs it.
        if ($task->runsOnOneServer() && !$cache->add("schedule.{$task->id()}." . $now->format('YmdHi'), true, 3600)) {
            return false;
        }

        $lock = $task->overlapMinutes() === null ? null : $cache->lock("schedule.{$task->id()}", $task->overlapMinutes() * 60);

        if ($lock !== null && !$lock->get()) {
            return false;
        }

        try {
            match ($task->type) {
                'command' => ($code = $runCommand($task->target, $task->args)) === 0
                    ?: throw new \RuntimeException("Command exited with code {$code}."),
                'call' => $invoker->call($task->target, null, static fn () => null),
                'job' => $container->get(Queue::class)->push($task->target, $task->queue),
            };
        } finally {
            $lock?->release();
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Pype\Schedule;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One scheduled task: a bin/pype command, a closure or a queued job, plus when it runs.
 * Frequency helpers edit a cron expression, so they combine: ->dailyAt('09:00')->weekdays().
 */
final class Task
{
    /** @var list<string> minute hour day-of-month month day-of-week */
    private array $cron = ['*', '*', '*', '*', '*'];

    private ?string $name = null;

    private ?Closure $when = null;

    private ?int $overlapMinutes = null;

    private bool $oneServer = false;

    /**
     * @param 'command'|'call'|'job' $type
     * @param string|Closure|object $target  command name, closure or job
     * @param list<string> $args  command arguments
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $target,
        public readonly array $args = [],
        private ?string $timezone = null,
        public readonly ?string $queue = null,
    ) {
    }

    // --- when --------------------------------------------------------------------------------

    public function cron(string $expression): self
    {
        new Cron($expression);   // fail now on a typo, not at 3 am
        $this->cron = preg_split('/\s+/', trim($expression));

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int $minute): self
    {
        return $this->cron("{$minute} * * * *");
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /** @param string $time  'HH:MM' */
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron("{$minute} {$hour} * * *");
    }

    /** Only Monday to Friday; keeps the time already set. */
    public function weekdays(): self
    {
        return $this->field(4, '1-5');
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    /** @param int $day  0 (Sunday) to 6 */
    public function weeklyOn(int $day, string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron("{$minute} {$hour} * * {$day}");
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    public function monthlyOn(int $day = 1, string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron("{$minute} {$hour} {$day} * *");
    }

    public function timezone(string $timezone): self
    {
        new DateTimeZone($timezone);   // throws on a typo
        $this->timezone = $timezone;

        return $this;
    }

    /** Only run when $condition (services injected) returns true. */
    public function when(Closure $condition): self
    {
        $this->when = $condition;

        return $this;
    }

    // --- how ---------------------------------------------------------------------------------

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Skip a run while the previous one is still going (a cache lock, held at most $minutes
     * in case the process dies).
     */
    public function withoutOverlapping(int $minutes = 1440): self
    {
        $this->overlapMinutes = $minutes;

        return $this;
    }

    /** With several servers running schedule:run, only the first to claim the minute runs it. Needs a shared cache (database). */
    public function onOneServer(): self
    {
        $this->oneServer = true;

        return $this;
    }

    // --- read --------------------------------------------------------------------------------

    public function expression(): string
    {
        return implode(' ', $this->cron);
    }

    public function timezoneName(): ?string
    {
        return $this->timezone;
    }

    public function condition(): ?Closure
    {
        return $this->when;
    }

    public function overlapMinutes(): ?int
    {
        return $this->overlapMinutes;
    }

    public function runsOnOneServer(): bool
    {
        return $this->oneServer;
    }

    public function description(): string
    {
        return $this->name ?? match ($this->type) {
            'command' => trim($this->target . ' ' . implode(' ', $this->args)),
            'job' => 'job ' . $this->target::class,
            default => 'closure',
        };
    }

    /** Stable between runs: the key for its locks. */
    public function id(): string
    {
        return substr(hash('sha256', $this->expression() . '|' . $this->description()), 0, 16);
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        $local = $this->timezone === null ? $now : $now->setTimezone(new DateTimeZone($this->timezone));

        return (new Cron($this->expression()))->isDue($local);
    }

    private function field(int $index, string $value): self
    {
        $cron = $this->cron;
        $cron[$index] = $value;

        return $this->cron(implode(' ', $cron));
    }

    /** @return array{0: int, 1: int} */
    private static function time(string $time): array
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            throw new \InvalidArgumentException("Invalid time [{$time}], expected HH:MM.");
        }

        return [(int) $m[1], (int) $m[2]];
    }
}

<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Console\Console;
use Pype\Schedule\Cron;
use Pype\Schedule\Schedule;
use Pype\Schedule\Task;

/**
 * Daylight saving, task timezones and the syntax the package added. 2026 changes:
 * New York 03-08 02:00 → 03:00 and 11-01 02:00 → 01:00; London 03-29 01:00 → 02:00 and
 * 10-25 02:00 → 01:00; Sydney 04-05 03:00 → 02:00 and 10-04 02:00 → 03:00.
 */
final class CronTimezoneTest extends TestCase
{
    /**
     * Every minute from $fromUtc for $hours hours, as schedule:run would see it in $zone: the
     * local times (with abbreviation) at which $due says yes.
     *
     * @param callable(DateTimeImmutable): bool $due
     * @return list<string>
     */
    private static function sweep(callable $due, string $zone, string $fromUtc, int $hours = 26): array
    {
        $start = new DateTimeImmutable($fromUtc, new DateTimeZone('UTC'));
        $hits = [];
        for ($i = 0; $i < $hours * 60; $i++) {
            $local = $start->modify("+{$i} minutes")->setTimezone(new DateTimeZone($zone));
            if ($due($local)) {
                $hits[] = $local->format('m-d H:i T');
            }
        }

        return $hits;
    }

    private static function cron(string $expression): callable
    {
        $cron = new Cron($expression);

        return $cron->isDue(...);
    }

    public function test_a_time_skipped_by_spring_forward_runs_an_hour_later(): void
    {
        // 02:30 doesn't exist on 2026-03-08 in New York: the task runs at 03:30, once.
        $this->assertSame(['03-08 03:30 EDT', '03-09 02:30 EDT'], self::sweep(self::cron('30 2 * * *'), 'America/New_York', '2026-03-08 05:00', 48));
        $this->assertSame(['03-08 03:00 EDT'], self::sweep(self::cron('0 2 * * *'), 'America/New_York', '2026-03-08 05:00', 20));
        // A time outside the gap is untouched.
        $this->assertSame(['03-08 03:30 EDT'], self::sweep(self::cron('30 3 * * *'), 'America/New_York', '2026-03-08 05:00', 20));
        // London: 01:30 doesn't exist on 03-29.
        $this->assertSame(['03-29 02:30 BST'], self::sweep(self::cron('30 1 * * *'), 'Europe/London', '2026-03-29 00:00', 24));
        // Sydney (southern hemisphere): 02:30 doesn't exist on 10-04.
        $this->assertSame(['10-04 03:30 AEDT'], self::sweep(self::cron('30 2 * * *'), 'Australia/Sydney', '2026-10-03 13:00', 24));
    }

    public function test_a_fixed_time_in_the_repeated_hour_runs_once_when_clocks_go_back(): void
    {
        // 01:30 happens twice on 2026-11-01 in New York: only the first one runs.
        $this->assertSame(['11-01 01:30 EDT'], self::sweep(self::cron('30 1 * * *'), 'America/New_York', '2026-11-01 04:00'));
        $this->assertSame(['10-25 01:30 BST'], self::sweep(self::cron('30 1 * * *'), 'Europe/London', '2026-10-24 23:00', 24));
        $this->assertSame(['04-05 02:30 AEDT'], self::sweep(self::cron('30 2 * * *'), 'Australia/Sydney', '2026-04-04 13:00', 24));
        // A list of fixed hours: each runs once.
        $this->assertSame(['11-01 00:15 EDT', '11-01 01:15 EDT', '11-01 02:15 EST'], self::sweep(self::cron('15 0-2 * * *'), 'America/New_York', '2026-11-01 04:00', 24));
    }

    public function test_hourly_and_every_minute_tasks_run_in_both_passes(): void
    {
        // The day has 25 hours: hourly runs 25 times, both 01:00s included.
        $hourly = self::sweep(self::cron('0 * * * *'), 'America/New_York', '2026-11-01 04:00', 25);
        $this->assertCount(25, $hourly);
        $this->assertContains('11-01 01:00 EDT', $hourly);
        $this->assertContains('11-01 01:00 EST', $hourly);
        $this->assertCount(25 * 60, self::sweep(self::cron('* * * * *'), 'America/New_York', '2026-11-01 04:00', 25));
        // Every 30 minutes, and every minute of a fixed hour (wildcard minute): both passes.
        $this->assertSame(['11-01 01:00 EDT', '11-01 01:30 EDT', '11-01 01:00 EST', '11-01 01:30 EST'],
            self::sweep(self::cron('*/30 1 * * *'), 'America/New_York', '2026-11-01 04:00', 24));
        // The spring-forward day has 23 hours.
        $this->assertCount(23, self::sweep(self::cron('0 * * * *'), 'America/New_York', '2026-03-08 05:00', 23));
    }

    public function test_task_helpers_and_timezones_across_the_changes(): void
    {
        $task = fn () => (new Task('call', fn () => null))->timezone('America/New_York');

        // A server on UTC, a task on New York time: runs at 09:00 New York in summer and winter.
        $this->assertSame(['10-30 13:00 UTC', '11-02 14:00 UTC'], self::sweep(
            fn (DateTimeImmutable $now) => $task()->dailyAt('09:00')->weekdays()->isDue($now->setTimezone(new DateTimeZone('UTC'))),
            'UTC', '2026-10-30 00:00', 24 * 4));
        // weeklyOn Sunday 01:30 across the fall-back Sunday: once.
        $this->assertSame(['11-01 05:30 UTC'], self::sweep(
            fn (DateTimeImmutable $now) => $task()->weeklyOn(0, '01:30')->isDue($now),
            'UTC', '2026-11-01 00:00', 24));
        // monthlyOn the 8th at 02:30 on the spring-forward day: 03:30 EDT = 07:30 UTC.
        $this->assertSame(['03-08 07:30 UTC'], self::sweep(
            fn (DateTimeImmutable $now) => $task()->monthlyOn(8, '02:30')->isDue($now),
            'UTC', '2026-03-08 00:00', 24));
    }

    public function test_schedule_run_with_a_time_in_another_zone(): void
    {
        $base = sys_get_temp_dir() . '/pype-cron-' . bin2hex(random_bytes(4));
        mkdir("{$base}/routes", 0777, true);
        file_put_contents("{$base}/routes/schedule.php", <<<'PHP'
            <?php
            $schedule->call(fn () => print("joburg\n"))->dailyAt('09:00')->timezone('Africa/Johannesburg')->name('joburg');
            PHP);
        $run = function (string $time) use ($base) {
            ob_start();
            (new Console(new Application($base)))->run(['pype', 'schedule:run', "--time={$time}"]);

            return (string) ob_get_clean();
        };

        try {
            $this->assertStringContainsString('joburg', $run('2026-11-02 07:00 UTC'));
            $this->assertStringContainsString('joburg', $run('2026-11-02 09:00 Africa/Johannesburg'));
            $this->assertStringNotContainsString('joburg', $run('2026-11-02 09:00 UTC'));
        } finally {
            unlink("{$base}/routes/schedule.php");
            rmdir("{$base}/routes");
            rmdir($base);
        }
    }

    public function test_macros_and_extra_syntax(): void
    {
        $due = fn (string $e, string $t) => (new Cron($e))->isDue(new DateTimeImmutable($t));

        $this->assertTrue($due('@daily', '2026-10-20 00:00'));
        $this->assertFalse($due('@daily', '2026-10-20 00:01'));
        $this->assertTrue($due('@HOURLY', '2026-10-20 13:00'));
        $this->assertTrue($due('0 0 L * *', '2026-02-28 00:00'));      // last day of the month
        $this->assertFalse($due('0 0 L * *', '2026-02-27 00:00'));
        $this->assertTrue($due('0 9 * * 1#2', '2026-10-12 09:00'));    // second Monday
        $this->assertFalse($due('0 9 * * 1#2', '2026-10-05 09:00'));
        $this->assertTrue($due('0 9 15W * *', '2026-11-16 09:00'));    // the 15th is a Sunday: Monday
        $this->assertTrue($due('0 9 ? * MON', '2026-10-19 09:00'));

        // Macros become 5 fields, so the helpers still edit them.
        $this->assertSame('0 0 * * *', (new Task('call', fn () => null))->cron('@daily')->expression());
        $this->assertSame('0 0 * * 1-5', (new Task('call', fn () => null))->cron('@daily')->weekdays()->expression());
        $this->assertSame('0 * * * *', (new Schedule())->call(fn () => null)->cron('@hourly')->expression());
    }

    public function test_pype_rules_kept_over_the_package(): void
    {
        // 'n/step' starts at n (the package alone rejects it).
        $this->assertSame(['5-59/20', '*', '*', '*', '*'], (new Cron('5/20 * * * *'))->fields);
        $this->assertSame(['*', '*', '*', '*', '1-6/2'], (new Cron('* * * * mon/2'))->fields);
        $this->assertTrue((new Cron('* * * * mon/2'))->isDue(new DateTimeImmutable('2026-10-21 10:00')));   // Wednesday
        $this->assertFalse((new Cron('* * * * mon/2'))->isDue(new DateTimeImmutable('2026-10-20 10:00')));  // Tuesday

        // Descending ranges are errors (the package alone would accept them).
        foreach (['5-1 * * * *', '* * * DEC-JAN *', '* * * * FRI-MON', '* 9-3/2 * * *'] as $expression) {
            try {
                new Cron($expression);
                $this->fail("[{$expression}] was accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($expression, $e->getMessage());
            }
        }
    }
}

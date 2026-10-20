<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pype\Application;
use Pype\Cache\ArrayStore;
use Pype\Cache\Cache;
use Pype\Cache\Store;
use Pype\Console\Console;
use Pype\Http\Request;
use Pype\Queue\ArrayQueue;
use Pype\Queue\Queue;
use Pype\Schedule\Schedule;
use Pype\Schedule\Task;
use Pype\Tests\Support\MemoryLogger;

final class ScheduleTest extends TestCase
{
    private Application $app;

    private MemoryLogger $log;

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->app = new Application(__DIR__);
        $this->app->container->instance(LoggerInterface::class, $this->log = new MemoryLogger());
        $this->app->container->instance(Queue::class, new ArrayQueue());
        $store = new ArrayStore();
        $this->app->container->instance(Store::class, $store);
        $this->app->container->instance(Cache::class, new Cache($store));
        $this->app->command('report', 'test', function (\Pype\Console\Input $in) {
            $this->ran[] = 'report ' . implode(' ', $in->arguments()) . ($in->option('month') ? ' month=' . $in->option('month') : '');

            return 0;
        });
        $this->app->command('broken', 'test', fn () => 3);
    }

    private function runAt(Schedule $schedule, string $time): array
    {
        $console = new Console($this->app);

        return $schedule->run(new DateTimeImmutable($time), $this->app->container, $console->call(...));
    }

    private static function statuses(array $results): array
    {
        return array_map(fn ($r) => $r['task']->description() . ' ' . $r['status'], $results);
    }

    public function test_frequency_helpers_build_cron_expressions(): void
    {
        $task = fn () => new Task('call', fn () => null);

        $this->assertSame('* * * * *', $task()->expression());
        $this->assertSame('*/5 * * * *', $task()->everyFiveMinutes()->expression());
        $this->assertSame('0 * * * *', $task()->hourly()->expression());
        $this->assertSame('17 * * * *', $task()->hourlyAt(17)->expression());
        $this->assertSame('30 1 * * *', $task()->dailyAt('01:30')->expression());
        $this->assertSame('0 9 * * 1-5', $task()->dailyAt('9:00')->weekdays()->expression());
        $this->assertSame('0 6 * * 1', $task()->weeklyOn(1, '06:00')->expression());
        $this->assertSame('0 6 15 * *', $task()->monthlyOn(15, '06:00')->expression());
        $this->assertSame('0 0 1 * *', $task()->monthly()->expression());

        $this->expectException(\InvalidArgumentException::class);
        $task()->dailyAt('25:00');
    }

    public function test_only_due_tasks_run_and_commands_get_their_arguments(): void
    {
        $schedule = new Schedule();
        $schedule->command('report', ['2026-10', '--month=10'])->dailyAt('01:00');
        $schedule->command('report', ['hourly'])->hourly();

        $this->assertSame(['report 2026-10 --month=10 ran', 'report hourly ran'], self::statuses($this->runAt($schedule, '2026-10-21 01:00')));
        $this->assertSame(['report 2026-10 month=10', 'report hourly'], $this->ran);

        $this->assertSame(['report hourly ran'], self::statuses($this->runAt($schedule, '2026-10-21 02:00')));
        $this->assertSame([], $this->runAt($schedule, '2026-10-21 02:01'));
    }

    public function test_timezones(): void
    {
        $schedule = new Schedule();
        $schedule->timezone('Africa/Johannesburg');   // UTC+2
        $schedule->call(fn () => null)->dailyAt('01:00')->name('local');
        $schedule->call(fn () => null)->dailyAt('01:00')->timezone('UTC')->name('utc');

        $this->assertSame(['local ran'], self::statuses($this->runAt($schedule, '2026-10-20 23:00 UTC')));
        $this->assertSame(['utc ran'], self::statuses($this->runAt($schedule, '2026-10-21 01:00 UTC')));
    }

    public function test_closures_get_services_and_jobs_go_onto_the_queue(): void
    {
        $schedule = new Schedule();
        $seen = null;
        $schedule->call(function (Queue $queue) use (&$seen) { $seen = $queue; });
        $schedule->job(new ScheduledJob('nightly'), 'reports');

        $this->runAt($schedule, '2026-10-21 03:00');

        $queue = $this->app->container->get(Queue::class);
        $this->assertSame($queue, $seen);
        $this->assertEquals([new ScheduledJob('nightly')], $queue->pushed(ScheduledJob::class));
        $this->assertSame(1, $queue->size('reports'));
    }

    public function test_a_failing_task_is_logged_and_the_others_still_run(): void
    {
        $schedule = new Schedule();
        $schedule->call(fn () => throw new \RuntimeException('boom'))->name('exploding');
        $schedule->command('broken');
        $schedule->command('report', ['after']);

        $results = $this->runAt($schedule, '2026-10-21 03:00');

        $this->assertSame(['exploding failed', 'broken failed', 'report after ran'], self::statuses($results));
        $this->assertSame('Command exited with code 3.', $results[1]['error']);
        $this->assertContains('error: Scheduled task exploding failed: boom', $this->log->lines());
    }

    public function test_when_and_without_overlapping(): void
    {
        $schedule = new Schedule();
        $schedule->call(fn () => null)->when(fn () => false)->name('never');
        $schedule->call(fn () => null)->withoutOverlapping()->name('single');

        // Another process holds the lock: skipped.
        $task = $schedule->tasks()[1];
        $this->assertTrue($this->app->container->get(Cache::class)->lock("schedule.{$task->id()}", 60)->get());
        $this->assertSame(['never skipped', 'single skipped'], self::statuses($this->runAt($schedule, '2026-10-21 03:00')));

        // Released after its own run.
        $this->app->container->get(Cache::class)->clear();
        $this->assertSame(['never skipped', 'single ran'], self::statuses($this->runAt($schedule, '2026-10-21 03:01')));
        $this->assertSame(['never skipped', 'single ran'], self::statuses($this->runAt($schedule, '2026-10-21 03:02')));
    }

    public function test_on_one_server_runs_once_per_minute(): void
    {
        $count = 0;
        $schedule = new Schedule();
        $schedule->call(function () use (&$count) { $count++; })->onOneServer();

        $this->runAt($schedule, '2026-10-21 03:00');
        $this->runAt($schedule, '2026-10-21 03:00');   // a second server, same minute, same cache
        $this->runAt($schedule, '2026-10-21 03:01');

        $this->assertSame(2, $count);
    }

    public function test_schedule_commands_load_routes_schedule_php(): void
    {
        $base = sys_get_temp_dir() . '/pype-schedule-' . bin2hex(random_bytes(4));
        mkdir("{$base}/routes", 0777, true);
        file_put_contents("{$base}/routes/schedule.php", <<<'PHP'
            <?php
            $schedule->call(fn () => print("tick\n"))->everyMinute()->name('tick');
            $schedule->command('nope')->dailyAt('04:00')->withoutOverlapping();
            PHP);

        $app = new Application($base);
        $run = function (string ...$argv) use ($app) {
            ob_start();
            $code = (new Console($app))->run(['pype', ...$argv]);

            return [$code, (string) ob_get_clean()];
        };

        [$code, $out] = $run('schedule:list');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('* * * * *', $out);
        $this->assertStringContainsString('0 4 * * *', $out);
        $this->assertStringContainsString('[without overlapping]', $out);

        [$code, $out] = $run('schedule:run', '--time=2026-10-21 03:00');
        $this->assertSame(0, $code);
        $this->assertStringContainsString("tick\nran      tick", $out);

        // Web requests never load it (the file would print).
        ob_start();
        $app->handle(Request::create('GET', '/'));
        $this->assertSame('', ob_get_clean());

        unlink("{$base}/routes/schedule.php");
        rmdir("{$base}/routes");
        rmdir($base);
    }
}

final class ScheduledJob
{
    public function __construct(public readonly string $what)
    {
    }

    public function handle(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Cache\ArrayStore;
use Pype\Cache\Cache;
use Pype\Http\Request;
use Pype\Mail\ArrayTransport;
use Pype\Mail\Mailer;
use Pype\Mail\Message;
use Pype\Mail\SendQueuedMail;
use Pype\Queue\ArrayQueue;
use Pype\Queue\Queue;
use Pype\Queue\QueueProvider;
use Pype\Queue\SyncQueue;
use Pype\Queue\Worker;
use Pype\Tests\Support\MemoryLogger;

final class WorkerTest extends TestCase
{
    private int $time = 1_800_000_000;

    private Application $app;

    private ArrayQueue $queue;

    private MemoryLogger $log;

    private Cache $cache;

    protected function setUp(): void
    {
        $this->app = new Application(__DIR__);
        $this->app->container->instance(Queue::class, $this->queue = new ArrayQueue('default', fn (): int => $this->time));
        $this->app->container->instance(Cache::class, $this->cache = new Cache(new ArrayStore()));
        $this->app->container->instance(Recorder::class, new Recorder());
        $this->log = new MemoryLogger();
        Recorder::$failures = [];
    }

    private function worker(): Worker
    {
        return new Worker($this->queue, $this->app->container, $this->log, $this->cache);
    }

    private function recorder(): Recorder
    {
        return $this->app->container->get(Recorder::class);
    }

    public function test_handle_gets_services_and_done_jobs_are_deleted(): void
    {
        $this->queue->push(new RecordJob('a'));
        $this->queue->push(new RecordJob('b'));

        $this->assertSame(2, $this->worker()->run(stopWhenEmpty: true, sleep: 0));
        $this->assertSame(['a', 'b'], $this->recorder()->seen);
        $this->assertSame(0, $this->queue->size());
        $this->assertCount(2, array_filter($this->log->lines(), fn ($l) => str_starts_with($l, 'info: Job ' . RecordJob::class . ' done')));
    }

    public function test_retries_with_backoff_then_fails_and_calls_the_hook(): void
    {
        $this->queue->push(new FlakyJob());
        $worker = $this->worker();

        $worker->run(once: true, sleep: 0);
        $this->assertSame(1, $this->queue->size());
        $this->assertSame(0, $worker->run(once: true, sleep: 0));   // backoff: 10 s
        $this->time += 10;
        $worker->run(once: true, sleep: 0);
        $this->time += 59;
        $this->assertSame(0, $worker->run(once: true, sleep: 0));   // second backoff: 60 s
        $this->time += 1;
        $worker->run(once: true, sleep: 0);   // third and last try

        $this->assertSame(0, $this->queue->size());
        $this->assertCount(1, $this->queue->failed());
        $this->assertSame(['try 3 failed'], Recorder::$failures);
        $this->assertSame(3, $this->recorder()->tries);
        $this->assertContains('warning: Job ' . FlakyJob::class . ' failed (attempt 1 of 3), retrying: try 1 failed', $this->log->lines());
        $this->assertContains('error: Job ' . FlakyJob::class . ' failed: try 3 failed', $this->log->lines());
    }

    public function test_jobs_try_once_by_default(): void
    {
        $this->queue->push(new FailingJob());
        $this->worker()->run(once: true, sleep: 0);

        $this->assertCount(1, $this->queue->failed());
    }

    public function test_stops_after_max_jobs(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->queue->push(new RecordJob($v));
        }

        $this->assertSame(2, $this->worker()->run(maxJobs: 2, sleep: 0));
        $this->assertContains('info: Queue worker stopping: max jobs', $this->log->lines());
        $this->assertSame(1, $this->queue->size());
    }

    public function test_stops_on_the_restart_signal(): void
    {
        $this->queue->push(new RestartJob());
        $this->queue->push(new RecordJob('never'));

        $this->assertSame(1, $this->worker()->run(sleep: 0));
        $this->assertContains('info: Queue worker stopping: restart signal', $this->log->lines());
        $this->assertSame([], $this->recorder()->seen);
    }

    public function test_nothing_about_a_job_stays_in_the_container(): void
    {
        foreach (range(1, 20) as $i) {
            $this->queue->push(new RecordJob("j{$i}"));
        }

        $this->worker()->run(stopWhenEmpty: true, sleep: 0);

        $this->assertCount(20, $this->recorder()->seen);
        $this->assertFalse($this->app->container->resolved(Request::class));
        $this->assertFalse($this->app->container->resolved(RecordJob::class));
    }

    public function test_sync_queue_runs_now_and_throws(): void
    {
        $queue = new SyncQueue($this->app->container);
        $queue->push(new RecordJob('now'));
        $this->assertSame(['now'], $this->recorder()->seen);

        $this->expectException(\RuntimeException::class);
        $queue->push(new FailingJob());
    }

    public function test_provider_binds_the_configured_driver(): void
    {
        foreach (['sync' => SyncQueue::class, 'array' => ArrayQueue::class, 'database' => \Pype\Queue\DatabaseQueue::class] as $driver => $class) {
            $app = new Application(__DIR__, ['queue' => ['driver' => $driver]]);
            $app->container->instance(\Pype\Database\Connection::class, \Pype\Database\Connection::sqlite());
            $app->register(QueueProvider::class);
            $this->assertInstanceOf($class, $app->container->get(Queue::class));
        }
    }

    public function test_mailer_queue_sends_from_the_worker(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport, ['app@x.test', 'App'], queue: fn () => $this->queue);
        $this->app->container->instance(Mailer::class, $mailer);

        $id = $mailer->queue(Message::to('ada@x.test')->subject('Hi')->html('<p>Hello</p>'), 'mail');

        $this->assertNotSame('', $id);
        $this->assertSame([], $transport->messages());
        $this->assertCount(1, $this->queue->pushed(SendQueuedMail::class));

        $this->worker()->run(queues: ['mail'], once: true, sleep: 0);
        $this->assertCount(1, $transport->messages());
        $sent = $transport->messages()[0];
        $this->assertSame('Hi', $sent->getSubject());
        $this->assertSame("Hello", trim($sent->getText()));
    }

    public function test_a_job_past_its_timeout_kills_the_worker(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('needs pcntl');
        }

        $script = tempnam(sys_get_temp_dir(), 'pype-timeout');
        file_put_contents($script, '<?php
            require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
            final class SlowJob { public int $timeout = 1; public function handle(): void { sleep(5); } }
            $queue = new Pype\Queue\ArrayQueue();
            $queue->push(new SlowJob());
            $log = new Pype\Tests\Support\MemoryLogger();
            register_shutdown_function(fn () => print(implode("\n", $log->lines())));
            (new Pype\Queue\Worker($queue, new Pype\Container\Container(), $log))->run(once: true, sleep: 0);
            echo "not reached";
        ');

        $start = microtime(true);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), $out, $code);
        unlink($script);

        $this->assertSame(1, $code);
        $this->assertLessThan(4, microtime(true) - $start);
        $this->assertStringContainsString('error: Job SlowJob timed out after 1 s; worker exiting', implode("\n", $out));
    }
}

final class Recorder
{
    /** @var list<string> */
    public array $seen = [];

    public int $tries = 0;

    /** @var list<string> */
    public static array $failures = [];
}

final class RecordJob
{
    public function __construct(public readonly string $value)
    {
    }

    public function handle(Recorder $recorder): void
    {
        $recorder->seen[] = $this->value;
    }
}

final class FlakyJob
{
    public int $tries = 3;

    public array $backoff = [10, 60];

    public function handle(Recorder $recorder): void
    {
        throw new \RuntimeException('try ' . ++$recorder->tries . ' failed');
    }

    public function failed(\Throwable $e): void
    {
        Recorder::$failures[] = $e->getMessage();
    }
}

final class FailingJob
{
    public function handle(): void
    {
        throw new \RuntimeException('nope');
    }
}

final class RestartJob
{
    public function handle(Cache $cache): void
    {
        $cache->forever(Worker::RESTART_KEY, 'now');
    }
}

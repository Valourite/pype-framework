<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pype\Queue\ArrayQueue;
use Pype\Queue\DatabaseQueue;
use Pype\Queue\FailedJobs;
use Pype\Queue\Queue;
use Pype\Tests\Support\TestDatabase;

/** The same behaviour from every stored queue; the database one runs on SQLite, MySQL and Postgres. */
final class QueueContractTest extends TestCase
{
    private int $time = 1_800_000_000;

    /** @return array<string, array{string}> */
    public static function queues(): array
    {
        return ['array' => ['array'], 'database' => ['database']];
    }

    private function queue(string $driver, int $retryAfter = 90): Queue&FailedJobs
    {
        $clock = fn (): int => $this->time;

        return $driver === 'array'
            ? new ArrayQueue('default', $clock)
            : new DatabaseQueue(TestDatabase::withQueueTables(), retryAfter: $retryAfter, now: $clock);
    }

    #[DataProvider('queues')]
    public function test_push_and_pop_in_order(string $driver): void
    {
        $queue = $this->queue($driver);

        $a = $queue->push(new QueueJob('a'));
        $queue->push(new QueueJob('b'));
        $this->assertNotSame('', $a);
        $this->assertSame(2, $queue->size());

        $first = $queue->pop(['default']);
        $this->assertSame($a, $first->id);
        $this->assertEquals(new QueueJob('a'), $first->job);
        $this->assertSame(1, $first->attempts);
        $this->assertSame('default', $first->queue);
        $this->assertSame(QueueJob::class, $first->name());

        $second = $queue->pop(['default']);
        $this->assertSame('b', $second->job->value);
        $this->assertNull($queue->pop(['default']));   // both reserved

        $queue->delete($first);
        $queue->delete($second);
        $this->assertSame(0, $queue->size());
    }

    #[DataProvider('queues')]
    public function test_queues_and_priority(string $driver): void
    {
        $queue = $this->queue($driver);
        $queue->push(new QueueJob('low'));
        $queue->push(new QueueJob('mail'), 'mail');

        $this->assertSame(1, $queue->size('mail'));
        $this->assertNull($queue->pop(['reports']));
        $this->assertSame('mail', $queue->pop(['mail'])->job->value);
        $this->assertSame('low', $queue->pop(['mail', 'default'])->job->value);
    }

    #[DataProvider('queues')]
    public function test_delayed_jobs_wait(string $driver): void
    {
        $queue = $this->queue($driver);
        $queue->later(60, new QueueJob('later'));
        $queue->later(new \DateInterval('PT2M'), new QueueJob('later still'));

        $this->assertNull($queue->pop(['default']));
        $this->time += 60;
        $this->assertSame('later', $queue->pop(['default'])->job->value);
        $this->assertNull($queue->pop(['default']));
        $this->time += 60;
        $this->assertSame('later still', $queue->pop(['default'])->job->value);
    }

    #[DataProvider('queues')]
    public function test_release_counts_attempts_and_waits(string $driver): void
    {
        $queue = $this->queue($driver);
        $queue->push(new QueueJob('x'));

        $job = $queue->pop(['default']);
        $queue->release($job, 30);
        $this->assertNull($queue->pop(['default']));
        $this->time += 30;

        $again = $queue->pop(['default']);
        $this->assertSame($job->id, $again->id);
        $this->assertSame(2, $again->attempts);
    }

    #[DataProvider('queues')]
    public function test_failed_jobs_can_be_listed_retried_and_forgotten(string $driver): void
    {
        $queue = $this->queue($driver);
        $queue->push(new QueueJob('bad'), 'mail');
        $queue->push(new QueueJob('worse'));

        $queue->fail($queue->pop(['mail']), new \RuntimeException('SMTP down'));
        $queue->fail($queue->pop(['default']), new \LogicException('bug'));

        $this->assertSame(0, $queue->size());
        $failed = $queue->failed();
        $this->assertCount(2, $failed);
        $this->assertSame(QueueJob::class, $failed[1]['name']);
        $this->assertSame('mail', $failed[1]['queue']);
        $this->assertStringStartsWith("RuntimeException: SMTP down\n", $failed[1]['exception']);
        $this->assertSame($this->time, $failed[1]['failed_at']);

        $this->assertTrue($queue->retry($failed[1]['id']));
        $this->assertFalse($queue->retry($failed[1]['id']));
        $retried = $queue->pop(['mail']);
        $this->assertSame('bad', $retried->job->value);
        $this->assertSame(1, $retried->attempts);   // reset

        $this->assertTrue($queue->forget($failed[0]['id']));
        $this->assertFalse($queue->forget($failed[0]['id']));
        $this->assertSame([], $queue->failed());
    }

    public function test_jobs_of_a_dead_worker_are_claimed_again_after_retry_after(): void
    {
        $queue = $this->queue('database', retryAfter: 90);
        $queue->push(new QueueJob('x'));
        $queue->pop(['default']);   // the worker dies here

        $this->time += 89;
        $this->assertNull($queue->pop(['default']));
        $this->time += 1;
        $again = $queue->pop(['default']);
        $this->assertSame(2, $again->attempts);
    }

    public function test_an_unreadable_payload_goes_to_failed_jobs(): void
    {
        $db = TestDatabase::withQueueTables();
        $queue = new DatabaseQueue($db, now: fn (): int => $this->time);
        $db->table('jobs')->insert(['queue' => 'default', 'name' => 'Gone', 'payload' => base64_encode('not serialized'), 'attempts' => 0, 'available_at' => 0, 'reserved_at' => null, 'created_at' => 0]);
        $queue->push(new QueueJob('fine'));

        $this->assertSame('fine', $queue->pop(['default'])->job->value);
        $this->assertCount(1, $queue->failed());
    }

    public function test_a_job_needs_a_handle_method(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ArrayQueue())->push(new \stdClass());
    }
}

final class QueueJob
{
    public function __construct(public readonly string $value)
    {
    }

    public function handle(): void
    {
    }
}

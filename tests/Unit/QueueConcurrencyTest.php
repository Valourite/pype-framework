<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Container\Container;
use Pype\Database\Connection;
use Pype\Database\Schema\Schema;
use Pype\Database\Schema\Table;
use Pype\Queue\DatabaseQueue;
use Pype\Queue\Worker;
use Pype\Tests\Support\TestDatabase;

/** Several workers on one database never run a job twice and never miss one. */
final class QueueConcurrencyTest extends TestCase
{
    private const JOBS = 1000;
    private const WORKERS = 4;

    public function test_every_job_runs_exactly_once_with_four_workers(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs pcntl');
        }

        $file = TestDatabase::driver() === 'sqlite' ? tempnam(sys_get_temp_dir(), 'pype-queue') : null;
        $db = $file === null ? TestDatabase::withQueueTables() : TestDatabase::withQueueTables(TestDatabase::connect($file));
        (new Schema($db))->create('ran', function (Table $t) {
            $t->id();
            $t->integer('job');
            $t->integer('pid');
        });

        $queue = new DatabaseQueue($db);
        for ($i = 1; $i <= self::JOBS; $i++) {
            $queue->push(new InsertJob($i));
        }
        $db->disconnect();   // children must not share the parent's socket

        $pids = [];
        for ($w = 0; $w < self::WORKERS; $w++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $container = new Container();
                $container->instance(Connection::class, $own = TestDatabase::connect($file));
                (new Worker(new DatabaseQueue($own), $container))->run(stopWhenEmpty: true, sleep: 0);
                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $this->assertSame(self::JOBS, $db->table('ran')->count());
        $this->assertSame(self::JOBS, (int) $db->scalar('SELECT COUNT(DISTINCT job) FROM ran'));
        $this->assertSame(0, $db->table('jobs')->count());
        $this->assertSame(0, $db->table('failed_jobs')->count());
        // The work was actually shared out.
        $this->assertGreaterThan(1, (int) $db->scalar('SELECT COUNT(DISTINCT pid) FROM ran'));

        if ($file !== null) {
            @unlink($file);
            @unlink("{$file}-wal");
            @unlink("{$file}-shm");
        }
    }
}

final class InsertJob
{
    public function __construct(public readonly int $id)
    {
    }

    public function handle(Connection $db): void
    {
        $db->table('ran')->insert(['job' => $this->id, 'pid' => getmypid()]);
    }
}

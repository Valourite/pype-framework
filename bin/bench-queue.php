<?php

/**
 * Queue micro-benchmark: push cost, and throughput of no-op jobs with 1 and 4 workers.
 *
 *   bin/bench-queue.sh                    # in Docker, SQLite + MySQL 8.4 + Postgres 17
 *   PYPE_TEST_DB=pgsql ... php bin/bench-queue.php [jobs]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Pype\Container\Container;
use Pype\Queue\DatabaseQueue;
use Pype\Queue\Worker;
use Pype\Tests\Support\TestDatabase;

final class NoopJob
{
    public function __construct(public readonly int $n)
    {
    }

    public function handle(): void
    {
    }
}

$jobs = (int) ($argv[1] ?? 5000);
$file = TestDatabase::driver() === 'sqlite' ? sys_get_temp_dir() . '/pype-bench-queue.sqlite' : null;

$fresh = function () use ($file) {
    if ($file !== null) {
        @unlink($file);
        @unlink("{$file}-wal");
        @unlink("{$file}-shm");
    }

    return $file === null ? TestDatabase::withQueueTables() : TestDatabase::withQueueTables(TestDatabase::connect($file));
};

printf("%s, PHP %s, %d jobs\n", TestDatabase::driver(), PHP_VERSION, $jobs);

foreach ([1, 4] as $workers) {
    $db = $fresh();
    $queue = new DatabaseQueue($db);

    $start = hrtime(true);
    for ($i = 0; $i < $jobs; $i++) {
        $queue->push(new NoopJob($i));
    }
    $pushUs = (hrtime(true) - $start) / $jobs / 1000;
    $db->disconnect();

    $start = hrtime(true);
    $pids = [];
    for ($w = 0; $w < $workers; $w++) {
        if (($pid = pcntl_fork()) === 0) {
            (new Worker(new DatabaseQueue(TestDatabase::connect($file)), new Container()))->run(stopWhenEmpty: true, sleep: 0);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $seconds = (hrtime(true) - $start) / 1e9;

    $left = TestDatabase::connect($file)->table('jobs')->count();
    printf("  %d worker(s): push %.0f µs/job, processed %.0f jobs/s%s\n", $workers, $pushUs, $jobs / $seconds, $left === 0 ? '' : " ({$left} left!)");
}

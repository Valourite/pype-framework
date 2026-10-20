<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pype\Cache\ApcuStore;
use Pype\Cache\ArrayStore;
use Pype\Cache\Cache;
use Pype\Cache\DatabaseStore;
use Pype\Cache\FileStore;
use Pype\Cache\Store;
use Pype\Tests\Support\TestDatabase;

/** The same behaviour from every store; the database one runs on SQLite, MySQL and Postgres. */
final class CacheStoreContractTest extends TestCase
{
    private int $time = 1_800_000_000;

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
            @rmdir($this->dir);
        }
    }

    /** @return array<string, array{string}> */
    public static function stores(): array
    {
        return ['array' => ['array'], 'file' => ['file'], 'database' => ['database'], 'apcu' => ['apcu']];
    }

    private function store(string $driver): Store
    {
        $clock = fn (): int => $this->time;

        return match ($driver) {
            'array' => new ArrayStore($clock),
            'file' => new FileStore($this->dir = sys_get_temp_dir() . '/pype-cache-' . bin2hex(random_bytes(4)), $clock),
            'database' => new DatabaseStore(TestDatabase::withCacheTable(), 'cache', $clock),
            'apcu' => function_exists('apcu_enabled') && apcu_enabled()
                ? new ApcuStore()
                : $this->markTestSkipped('APCu not enabled for the CLI (apc.enable_cli=1).'),
        };
    }

    private function cache(string $driver, string $prefix = 'pype.'): Cache
    {
        // apcu shares memory between tests: a fresh prefix keeps each test to itself.
        $prefix = $driver === 'apcu' ? $prefix . bin2hex(random_bytes(4)) . '.' : $prefix;

        return new Cache($this->store($driver), $prefix, fn (): int => $this->time);
    }

    #[DataProvider('stores')]
    public function test_get_set_has_delete(string $driver): void
    {
        $cache = $this->cache($driver);

        $this->assertNull($cache->get('a'));
        $this->assertSame('x', $cache->get('a', 'x'));
        $this->assertFalse($cache->has('a'));

        $this->assertTrue($cache->set('a', 'one', 60));
        $this->assertSame('one', $cache->get('a'));
        $this->assertTrue($cache->has('a'));
        $cache->set('a', 'two');
        $this->assertSame('two', $cache->get('a'));

        $this->assertTrue($cache->delete('a'));
        $this->assertFalse($cache->has('a'));
        $this->assertTrue($cache->delete('never.set'));
    }

    #[DataProvider('stores')]
    public function test_null_false_and_types_round_trip(string $driver): void
    {
        $cache = $this->cache($driver);
        $values = [
            'null' => null, 'false' => false, 'zero' => 0, 'neg' => -42, 'float' => 1.5, 'digits' => '123',
            'empty' => '', 'binary' => "a\0b\xff", 'list' => [1, 'two', [3.0, null]],
            'map' => ['a' => true, 'b' => ['c' => 'd']],
            'object' => new CacheValue('Ada', [1, 2]),
            'nested' => ['when' => new \DateTimeImmutable('2026-10-19 12:00:00')],
        ];

        foreach ($values as $key => $value) {
            $cache->set($key, $value, 60);
        }

        foreach ($values as $key => $value) {
            $this->assertTrue($cache->has($key), $key);
            is_object($value) || is_array($value) && is_object(reset($value))
                ? $this->assertEquals($value, $cache->get($key, 'default'), $key)
                : $this->assertSame($value, $cache->get($key, 'default'), $key);
        }
    }

    #[DataProvider('stores')]
    public function test_expiry_and_forever(string $driver): void
    {
        if ($driver === 'apcu') {
            $this->markTestSkipped('APCu expires by its own clock.');
        }

        $cache = $this->cache($driver);
        $cache->set('short', 1, 10);
        $cache->forever('long', 2);

        $this->time += 9;
        $this->assertSame(1, $cache->get('short'));
        $this->time += 1;
        $this->assertFalse($cache->has('short'));
        $this->time += 10 ** 6;
        $this->assertSame(2, $cache->get('long'));
    }

    #[DataProvider('stores')]
    public function test_add_only_when_missing(string $driver): void
    {
        $cache = $this->cache($driver);

        $this->assertTrue($cache->add('a', 'first', 10));
        $this->assertFalse($cache->add('a', 'second', 10));
        $this->assertSame('first', $cache->get('a'));

        if ($driver !== 'apcu') {
            // Over an expired value, add wins again.
            $this->time += 10;
            $this->assertTrue($cache->add('a', 'third', 10));
            $this->assertSame('third', $cache->get('a'));
        }

        $cache->forever('b', 'kept');
        $this->assertFalse($cache->add('b', 'nope'));
        $this->assertSame('kept', $cache->get('b'));
    }

    #[DataProvider('stores')]
    public function test_increment_and_decrement(string $driver): void
    {
        $cache = $this->cache($driver);

        $this->assertSame(1, $cache->increment('hits'));
        $this->assertSame(6, $cache->increment('hits', 5));
        $this->assertSame(4, $cache->decrement('hits', 2));
        $this->assertSame(4, $cache->get('hits'));
        $this->assertSame(-1, $cache->decrement('other'));

        // A number put with set() can be incremented too, and keeps its expiry.
        $cache->set('counter', 10, 60);
        $this->assertSame(11, $cache->increment('counter'));

        if ($driver !== 'apcu') {
            $this->time += 60;
            $this->assertFalse($cache->has('counter'));
            $this->assertSame(1, $cache->increment('counter'));   // expired: starts again
        }
    }

    #[DataProvider('stores')]
    public function test_multiple(string $driver): void
    {
        $cache = $this->cache($driver);

        $this->assertTrue($cache->setMultiple(['a' => 1, 'b' => null], 60));
        $this->assertSame(['a' => 1, 'b' => null, 'c' => 'd'], $cache->getMultiple(['a', 'b', 'c'], 'd'));
        $this->assertTrue($cache->deleteMultiple(['a', 'c']));
        $this->assertSame(['a' => 'gone', 'b' => null], $cache->getMultiple(['a', 'b'], 'gone'));
    }

    #[DataProvider('stores')]
    public function test_clear_only_removes_its_prefix(string $driver): void
    {
        $store = $this->store($driver);
        $tag = $driver === 'apcu' ? bin2hex(random_bytes(4)) . '.' : '';
        $mine = new Cache($store, "app.{$tag}", fn (): int => $this->time);
        $other = new Cache($store, "other.{$tag}", fn (): int => $this->time);

        $mine->set('a', 1);
        $mine->group('g')->set('b', 2);
        $other->set('a', 3);

        $this->assertTrue($mine->clear());
        $this->assertFalse($mine->has('a'));
        $this->assertFalse($mine->group('g')->has('b'));
        $this->assertSame(3, $other->get('a'));
    }

    #[DataProvider('stores')]
    public function test_long_keys(string $driver): void
    {
        $cache = $this->cache($driver);
        $a = str_repeat('a', 300);

        $cache->set($a, 1);
        $cache->set($a . 'b', 2);
        $this->assertSame(1, $cache->get($a));
        $this->assertSame(2, $cache->get($a . 'b'));
    }

    #[DataProvider('stores')]
    public function test_locks(string $driver): void
    {
        $cache = $this->cache($driver);
        $first = $cache->lock('job', 30);
        $second = $cache->lock('job', 30);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
        $this->assertFalse($second->release());   // not the owner: nothing happens
        $this->assertNull($second->run(fn () => 'ran'));
        $this->assertTrue($first->release());
        $this->assertSame('ran', $second->run(fn () => 'ran'));
        $this->assertTrue($first->get());          // run() released it
    }

    #[DataProvider('stores')]
    public function test_prune_expired(string $driver): void
    {
        if (!in_array($driver, ['file', 'database'], true)) {
            $this->markTestSkipped('only the file and database stores keep expired entries');
        }

        $store = $this->store($driver);
        $store->put('old', 1, 10);
        $store->put('new', 2, 100);
        $store->put('forever', 3, null);
        $this->time += 10;

        $this->assertSame(1, $store->pruneExpired());
        $this->assertSame([true, 2], $store->fetch('new'));
        $this->assertSame([true, 3], $store->fetch('forever'));
    }
}

final class CacheValue
{
    public function __construct(public readonly string $name, public readonly array $items)
    {
    }
}

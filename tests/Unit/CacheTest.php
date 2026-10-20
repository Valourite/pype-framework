<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Pype\Application;
use Pype\Cache\ArrayStore;
use Pype\Cache\Cache;
use Pype\Cache\CacheProvider;
use Pype\Cache\DatabaseStore;
use Pype\Cache\FileStore;
use Pype\Cache\NullStore;
use Pype\Cache\Store;
use Pype\Console\Console;
use Pype\Database\Connection;
use Pype\Http\Request;
use Pype\Testing\TestClient;

final class CacheTest extends TestCase
{
    private int $time = 1_800_000_000;

    private function cache(?Store $store = null): Cache
    {
        $clock = fn (): int => $this->time;

        return new Cache($store ?? new ArrayStore($clock), 'pype.', $clock);
    }

    public function test_reserved_characters_in_keys_throw(): void
    {
        $cache = $this->cache();

        foreach (['', 'a{b', 'a}b', 'a(b', 'a)b', 'a/b', 'a\\b', 'a@b', 'a:b'] as $key) {
            try {
                $cache->get($key);
                $this->fail("[{$key}] was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $cache->getMultiple(['ok', 'not:ok']);
    }

    public function test_dots_and_dashes_are_fine(): void
    {
        $cache = $this->cache();
        $cache->set('totals.5.2026-10_x', 1);

        $this->assertSame(1, $cache->get('totals.5.2026-10_x'));
    }

    public function test_ttl_as_date_interval_and_non_positive_ttl_deletes(): void
    {
        $cache = $this->cache();

        $cache->set('a', 1, new DateInterval('PT2M'));
        $this->time += 119;
        $this->assertTrue($cache->has('a'));
        $this->time += 1;
        $this->assertFalse($cache->has('a'));

        $cache->set('b', 1);
        $this->assertTrue($cache->set('b', 2, 0));
        $this->assertFalse($cache->has('b'));
        $cache->set('b', 1);
        $cache->set('b', 2, -5);
        $this->assertFalse($cache->has('b'));
        $this->assertFalse($cache->add('c', 1, 0));
    }

    public function test_remember_calls_the_callback_once_even_for_null(): void
    {
        $cache = $this->cache();
        $calls = 0;

        foreach ([1, 2, 3] as $_) {
            $this->assertNull($cache->remember('n', 60, function () use (&$calls) {
                $calls++;

                return null;
            }));
        }

        $this->assertSame(1, $calls);
        $this->time += 60;
        $cache->remember('n', 60, function () use (&$calls) {
            $calls++;
        });
        $this->assertSame(2, $calls);
    }

    public function test_pull(): void
    {
        $cache = $this->cache();
        $cache->set('a', 'x');

        $this->assertSame('x', $cache->pull('a'));
        $this->assertFalse($cache->has('a'));
        $this->assertSame('d', $cache->pull('a', 'd'));
    }

    public function test_lock_expires_so_a_crashed_holder_cant_block_forever(): void
    {
        $cache = $this->cache();

        $this->assertTrue($cache->lock('job', 30)->get());
        $this->assertFalse($cache->lock('job', 30)->get());
        $this->time += 30;
        $this->assertTrue($cache->lock('job', 30)->get());
    }

    public function test_lock_is_released_when_the_callback_throws(): void
    {
        $cache = $this->cache();

        try {
            $cache->lock('job', 30)->run(fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->assertTrue($cache->lock('job', 30)->get());
    }

    public function test_groups_flush_without_touching_other_groups(): void
    {
        $cache = $this->cache();
        $ada = $cache->group('user.1');
        $ada->set('totals', 10);
        $cache->group('user.2')->set('totals', 20);
        $cache->set('totals', 0);

        $this->assertSame(10, $cache->group('user.1')->get('totals'));
        $this->assertNull($cache->group('user.1')->get('other'));

        $this->assertTrue($cache->group('user.1')->flush());
        $this->assertNull($cache->group('user.1')->get('totals'));
        $this->assertSame(20, $cache->group('user.2')->get('totals'));
        $this->assertSame(0, $cache->get('totals'));

        // The instance that flushed moved to the new version too.
        $ada->flush();
        $ada->set('totals', 11);
        $this->assertSame(11, $cache->group('user.1')->get('totals'));
    }

    public function test_groups_survive_losing_their_version_key(): void
    {
        $store = new ArrayStore(fn (): int => $this->time);
        $cache = $this->cache($store);
        $cache->group('g')->set('a', 'old');
        $cache->group('g')->flush();
        $cache->group('g')->set('a', 'new');

        // An evicted version key restarts at a random version, not at an old one.
        $store->forget('pype.groupver.g');
        $this->assertNull($cache->group('g')->get('a'));
    }

    public function test_null_store_caches_nothing(): void
    {
        $cache = $this->cache(new NullStore());
        $calls = 0;
        $cache->set('a', 1);

        $this->assertFalse($cache->has('a'));
        $cache->remember('b', 60, function () use (&$calls) { $calls++; });
        $cache->remember('b', 60, function () use (&$calls) { $calls++; });
        $this->assertSame(2, $calls);
        $this->assertSame('ran', $cache->lock('x', 1)->run(fn () => 'ran'));
    }

    private function staleApp(int &$computed): Application
    {
        $app = new Application(__DIR__, ['cache' => ['driver' => 'array']]);
        $app->register(CacheProvider::class);
        $clock = fn (): int => $this->time;
        $app->container->instance(Cache::class, new Cache(
            new ArrayStore($clock),
            now: $clock,
            request: fn () => $app->container->resolved(Request::class) ? $app->container->get(Request::class) : null,
        ));

        $app->router->get('/report', function (Cache $cache) use (&$computed) {
            $value = $cache->stale('report', fresh: 60, stale: 600, callback: function () use (&$computed) {
                return ++$computed;
            });

            return ['value' => $value, 'computed' => $computed];
        });

        return $app;
    }

    public function test_stale_serves_old_values_and_refreshes_after_the_response(): void
    {
        $computed = 0;
        $client = new TestClient($this->staleApp($computed));

        $this->assertSame(['value' => 1, 'computed' => 1], $client->get('/report')->json('data'));   // missing: computed now
        $this->time += 59;
        $this->assertSame(['value' => 1, 'computed' => 1], $client->get('/report')->json('data'));   // fresh

        $this->time += 1;
        // Stale: the old value is served, and the refresh hadn't run when the response was made...
        $this->assertSame(['value' => 1, 'computed' => 1], $client->get('/report')->json('data'));
        // ...but ran after it, so the next request sees the new value.
        $this->assertSame(2, $computed);
        $this->assertSame(['value' => 2, 'computed' => 2], $client->get('/report')->json('data'));

        // Past fresh + stale: computed during the request.
        $this->time += 60 + 600;
        $this->assertSame(['value' => 3, 'computed' => 3], $client->get('/report')->json('data'));
    }

    public function test_stale_refresh_is_skipped_while_another_process_refreshes(): void
    {
        $computed = 0;
        $app = $this->staleApp($computed);
        $client = new TestClient($app);
        $client->get('/report');

        $this->time += 61;
        $cache = $app->container->get(Cache::class);
        $this->assertTrue($cache->lock('refresh.report', 600)->get());   // someone else is on it

        $this->assertSame(1, $client->get('/report')->json('data.value'));
        $this->assertSame(1, $computed);
    }

    public function test_stale_outside_a_request_refreshes_immediately(): void
    {
        $cache = $this->cache();
        $n = 0;
        $cache->stale('k', 10, 100, function () use (&$n) { return ++$n; });
        $this->time += 11;

        $this->assertSame(1, $cache->stale('k', 10, 100, function () use (&$n) { return ++$n; }));
        $this->assertSame(2, $n);
        $this->assertSame(2, $cache->stale('k', 10, 100, function () use (&$n) { return ++$n; }));
    }

    public function test_provider_binds_drivers_lazily(): void
    {
        $app = new Application(__DIR__, ['cache' => ['driver' => 'array', 'prefix' => 'myapp.']]);
        $app->register(CacheProvider::class);

        $this->assertFalse($app->container->resolved(Store::class));
        $cache = $app->container->get(CacheInterface::class);
        $this->assertSame($cache, $app->container->get(Cache::class));
        $this->assertInstanceOf(ArrayStore::class, $cache->store());

        $cache->set('a', 1);
        $this->assertSame([true, 1], $cache->store()->fetch('myapp.a'));

        foreach (['file' => FileStore::class, 'null' => NullStore::class, 'database' => DatabaseStore::class] as $driver => $class) {
            $app = new Application(__DIR__, ['cache' => ['driver' => $driver]]);
            $app->container->instance(Connection::class, Connection::sqlite());
            $app->register(CacheProvider::class);
            $this->assertInstanceOf($class, $app->container->get(Store::class));
        }
    }

    public function test_cache_service_holds_no_request_state(): void
    {
        $computed = 0;
        $app = $this->staleApp($computed);
        $app->router->get('/flush', function (Cache $cache) {
            $cache->group('user.1')->flush();

            return ['ok' => true];
        });
        $cache = $app->container->get(Cache::class);
        $before = $this->state($cache);

        $client = new TestClient($app);
        foreach (range(1, 5) as $_) {
            $client->get('/report');
            $client->get('/flush');
            $this->time += 61;
        }

        $this->assertSame($cache, $app->container->get(Cache::class));
        $this->assertSame($before, $this->state($cache));
        $this->assertFalse($app->container->resolved(Request::class));
    }

    /** Every property of the cache except its closures, which are fixed at construction. */
    private function state(Cache $cache): array
    {
        $state = [];
        foreach ((new \ReflectionObject($cache))->getProperties() as $property) {
            $value = $property->getValue($cache);
            $state[$property->getName()] = $value instanceof \Closure ? 'closure' : $value;
        }

        return $state;
    }

    public function test_cache_clear_command(): void
    {
        $dir = sys_get_temp_dir() . '/pype-cache-cmd-' . bin2hex(random_bytes(4));
        $app = new Application(__DIR__, ['cache' => ['driver' => 'file', 'path' => $dir]]);
        $app->register(CacheProvider::class);
        $app->container->get(Cache::class)->set('a', 1);
        (new FileStore($dir))->put('elsewhere.a', 2, null);

        ob_start();
        $code = (new Console($app))->run(['pype', 'cache:clear']);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Cache cleared', $out);
        $this->assertFalse($app->container->get(Cache::class)->has('a'));
        $this->assertSame([true, 2], (new FileStore($dir))->fetch('elsewhere.a'));
        array_map('unlink', array_filter(glob($dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        rmdir($dir);

        // apcu: the CLI can't reach the web server's memory, so it says so instead.
        $app = new Application(__DIR__, ['cache' => ['driver' => 'apcu']]);
        $app->register(CacheProvider::class);
        ob_start();
        $code = (new Console($app))->run(['pype', 'cache:clear']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('reloading PHP-FPM', (string) ob_get_clean());
    }
}

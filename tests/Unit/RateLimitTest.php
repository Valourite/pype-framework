<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Database\DatabaseProvider;
use Pype\RateLimit\ApcuRateLimiter;
use Pype\RateLimit\ArrayRateLimiter;
use Pype\RateLimit\DatabaseRateLimiter;
use Pype\RateLimit\RateLimiter;
use Pype\Stages\Throttle;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;

final class RateLimitTest extends TestCase
{
    public function test_database_limiter_counts_and_resets_the_window(): void
    {
        $db = TestDatabase::withAuthTables();
        $limiter = new DatabaseRateLimiter($db);

        $this->assertSame(1, $limiter->hit('a', 60)[0]);
        $this->assertSame(2, $limiter->hit('a', 60)[0]);
        [$hits, $resetAt] = $limiter->hit('a', 60);
        $this->assertSame(3, $hits);
        $this->assertEqualsWithDelta(time() + 60, $resetAt, 1);
        $this->assertSame(1, $limiter->hit('b', 60)[0]);

        // Window over: starts again from 1.
        $db->table('rate_limits')->where('key', 'a')->update(['reset_at' => time() - 1]);
        $this->assertSame(1, $limiter->hit('a', 60)[0]);
        $this->assertSame(2, $limiter->hit('a', 60)[0]);
    }

    public function test_array_limiter(): void
    {
        $limiter = new ArrayRateLimiter();

        $this->assertSame(1, $limiter->hit('a', 60)[0]);
        $this->assertSame(2, $limiter->hit('a', 60)[0]);
        $this->assertSame(1, $limiter->hit('b', 60)[0]);
    }

    public function test_apcu_limiter(): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            $this->markTestSkipped('APCu not enabled for the CLI (apc.enable_cli=1).');
        }

        $limiter = new ApcuRateLimiter('pype-test:' . bin2hex(random_bytes(4)) . ':');
        $this->assertSame(1, $limiter->hit('a', 60)[0]);
        $this->assertSame(2, $limiter->hit('a', 60)[0]);
        $this->assertSame(1, $limiter->hit('b', 60)[0]);
    }

    public function test_driver_comes_from_config_and_throttle_uses_it(): void
    {
        $app = new Application(__DIR__, ['database' => ['rate_limits' => ['driver' => 'array']]]);
        $app->register(DatabaseProvider::class);
        $this->assertInstanceOf(ArrayRateLimiter::class, $app->container->get(RateLimiter::class));

        $app->router->get('/x', [new Throttle(maxAttempts: 2), fn () => 'ok']);
        $client = new TestClient($app);

        $this->assertSame('1', $client->get('/x')->header('X-RateLimit-Remaining'));
        $this->assertSame(200, $client->get('/x')->status());
        $res = $client->get('/x');
        $this->assertSame(429, $res->status());
        $this->assertNotNull($res->header('Retry-After'));
    }
}

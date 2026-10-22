<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Http\Request;
use Pype\Http\UploadedFile;
use Pype\RateLimit\ArrayRateLimiter;
use Pype\RateLimit\RateLimiter;
use Pype\Schedule\Schedule;
use Pype\Stages\Throttle;
use Pype\Testing\TestClient;

/** withIp(), empty file inputs and Schedule::load(): what the Helpdesk tests had to build by hand. */
final class TestingHelpersTest extends TestCase
{
    private const FILES = __DIR__ . '/../fixtures/files';

    public function test_requests_from_another_address(): void
    {
        $app = new Application(__DIR__);
        $app->container->instance(RateLimiter::class, new ArrayRateLimiter());
        $app->router->post('/tickets', [Throttle::with(2, 3600), fn (Request $request) => ['ip' => $request->ip()]]);
        $client = new TestClient($app);

        $client->post('/tickets')->assertOk()->assertJsonPath('data.ip', '127.0.0.1');
        $client->post('/tickets')->assertOk();
        $client->post('/tickets')->assertStatus(429);                                      // this address is done
        $client->withIp('10.0.0.2')->post('/tickets')->assertOk()->assertJsonPath('data.ip', '10.0.0.2');   // another isn't
        $this->assertSame('10.0.0.2', $client->withIp('10.0.0.2')->withoutCookies()->post('/tickets')->json('data.ip'), 'kept by withoutCookies()');
    }

    public function test_file_inputs_left_empty_are_dropped_like_a_browser_sends_them(): void
    {
        $app = new Application(__DIR__);
        $app->router->post('/contact', fn (Request $request) => [
            'names' => array_map(fn (UploadedFile $f) => $f->clientName(), $request->files()['attachments'] ?? []),
            'fields' => array_keys($request->files()),
            'multipart' => str_starts_with((string) $request->header('content-type'), 'multipart/form-data'),
        ]);
        $client = new TestClient($app);
        $pdf = fn (string $name) => UploadedFile::fake(self::FILES . '/receipt.pdf', $name);

        $client->post('/contact', ['subject' => 'Hi'], [], ['attachments' => [$pdf('a.pdf'), null, $pdf('c.pdf')], 'avatar' => null])
            ->assertOk()
            ->assertJsonPath('data.names', ['a.pdf', 'c.pdf'])   // the empty middle row is gone, the list reindexed
            ->assertJsonPath('data.fields', ['attachments']);

        $client->post('/contact', ['subject' => 'Hi'], [], ['attachments' => [null, null, null]])
            ->assertJsonPath('data.names', [])
            ->assertJsonPath('data.multipart', true);           // still a multipart form, as a browser sends it
    }

    public function test_the_apps_schedule_loads_in_a_test(): void
    {
        $dir = sys_get_temp_dir() . '/pype-schedule-' . bin2hex(random_bytes(4));
        mkdir("{$dir}/routes", 0777, true);
        file_put_contents("{$dir}/routes/schedule.php", <<<'PHP'
            <?php
            $schedule->command('tickets:overdue')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->job(new \Pype\Tests\Unit\PruneThings())->daily();
            $schedule->call(fn () => null)->hourly()->name('tidy');
            PHP);

        try {
            $schedule = Schedule::load(new Application($dir));
        } finally {
            unlink("{$dir}/routes/schedule.php");
            rmdir("{$dir}/routes");
            rmdir($dir);
        }

        $this->assertCount(3, $schedule->tasks());
        $this->assertSame('*/15 * * * *', $schedule->task('tickets:overdue')->expression());
        $this->assertSame(1440, $schedule->task('tickets:overdue')->overlapMinutes());
        $this->assertSame('0 0 * * *', $schedule->task(PruneThings::class)->expression());
        $this->assertSame('0 * * * *', $schedule->task('tidy')->expression());
        $this->assertNull($schedule->task('nope'));
        $this->assertSame([], Schedule::load(new Application(__DIR__))->tasks(), 'no schedule file: no tasks');
    }
}

final class PruneThings
{
}

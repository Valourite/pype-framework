<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Log\LogProvider;
use Pype\Mail\MailProvider;
use Pype\Outlet\Outlet;
use Pype\Server\SwooleServer;
use Pype\Support\Env;
use Pype\Testing\TestClient;
use RuntimeException;

/**
 * Outside debug (APP_DEBUG=false, or APP_ENV=production whatever APP_DEBUG says), nothing a
 * client can reach shows an exception, a trace, the log viewer or the dev mailbox: through
 * PHP-FPM's path (Application::run/handle) and OpenSwoole's (SwooleServer::handle).
 */
final class ProductionSafetyTest extends TestCase
{
    public const SECRET = 'db password is hunter2';
    public const TEMPLATE_SECRET = 'template failed reading /srv/app/.env';
    private const DEV_PAGES = ['/_pype/logs', '/_pype/logs/entries', '/_pype/mail'];

    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pype-prod-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->base);
    }

    /** The whole dev kit switched on by config (viewer, mailbox, request log), plus failing routes. */
    private function app(array $app): Application
    {
        $application = new Application($this->base, [
            'app' => $app,
            'log' => ['requests' => true, 'viewer' => ['enabled' => true]],
            'mail' => ['driver' => 'log', 'mailbox' => ['enabled' => true]],
        ]);
        $application->register(LogProvider::class)->register(MailProvider::class);
        $application->router->get('/boom', fn () => throw new RuntimeException(self::SECRET));
        $application->router->get('/bad-view', [FailingOutlet::class, fn () => ['ok' => true]]);

        return $application;
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function productionLike(): iterable
    {
        yield 'APP_DEBUG=false' => [['env' => 'local', 'debug' => false]];
        yield 'APP_ENV=production, APP_DEBUG=true' => [['env' => 'production', 'debug' => true]];
        yield 'APP_ENV=prod, APP_DEBUG=true' => [['env' => 'prod', 'debug' => true]];
        yield 'APP_ENV=Production (any case)' => [['env' => 'Production', 'debug' => true]];
        yield 'debug as the string "off"' => [['env' => 'local', 'debug' => 'off']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('productionLike')]
    public function test_dev_pages_are_not_reachable(array $config): void
    {
        $app = $this->app($config);
        $this->assertFalse($app->config('app.debug'));
        $client = new TestClient($app);

        foreach (self::DEV_PAGES as $path) {
            foreach (['text/html', 'application/json'] as $accept) {
                $this->assertSame(404, $client->get($path, ['Accept' => $accept])->status(), "{$path} ({$accept})");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('productionLike')]
    public function test_errors_show_nothing_of_the_exception(array $config): void
    {
        $client = new TestClient($this->app($config));

        foreach (['/boom', '/bad-view'] as $path) {
            foreach (['text/html', 'application/json'] as $accept) {
                $res = $client->get($path, ['Accept' => $accept]);
                $this->assertSame(500, $res->status(), "{$path} ({$accept})");
                $this->assertSame(['status' => 500, 'message' => 'Server Error'], $res->json('error'), "{$path} ({$accept})");
                self::assertNoLeak($res->body());
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('productionLike')]
    public function test_the_same_through_openswoole(array $config): void
    {
        $server = new SwooleServer($this->app($config));

        foreach ([...self::DEV_PAGES, '/boom', '/bad-view'] as $path) {
            foreach (['text/html', 'application/json'] as $accept) {
                $res = FakeSwoole::response();
                $server->handle(FakeSwoole::request($path, $accept), $res);
                $this->assertSame(in_array($path, self::DEV_PAGES, true) ? 404 : 500, $res->status, "{$path} ({$accept})");
                self::assertNoLeak((string) $res->body);
                $this->assertStringNotContainsString('<!doctype', strtolower((string) $res->body), 'no HTML error page');
            }
        }
    }

    public function test_debug_still_works_outside_production(): void
    {
        $client = new TestClient($this->app(['env' => 'local', 'debug' => true]));

        $this->assertSame(200, $client->get('/_pype/logs')->status());
        $this->assertSame(200, $client->get('/_pype/mail')->status());
        $this->assertStringContainsString(self::SECRET, $client->get('/boom', ['Accept' => 'text/html'])->body());
    }

    public function test_a_missing_app_env_means_production(): void
    {
        $saved = getenv('APP_ENV');
        putenv('APP_ENV');
        unset($_ENV['APP_ENV']);

        try {
            $app = $this->app(['debug' => true]);
            $this->assertSame('production', $app->environment());
            $this->assertTrue($app->isProduction());
            $this->assertFalse($app->config('app.debug'));
            $this->assertSame(404, (new TestClient($app))->get('/_pype/logs')->status());

            putenv('APP_ENV=local');
            $this->assertTrue($this->app(['debug' => true])->config('app.debug'), 'APP_ENV from the environment');
            $this->assertFalse($this->app(['env' => 'production', 'debug' => true])->config('app.debug'), 'app.env wins');
        } finally {
            $saved === false ? putenv('APP_ENV') : putenv("APP_ENV={$saved}");
        }
    }

    public function test_falsy_words_in_env_are_false(): void
    {
        foreach (['false', 'FALSE', 'off', 'Off', 'no', ' false', 'false '] as $value) {
            putenv("PYPETEST_DEBUG={$value}");
            $this->assertFalse(Env::get('PYPETEST_DEBUG'), json_encode($value));
        }
        foreach (['true', 'on', 'yes', ' TRUE'] as $value) {
            putenv("PYPETEST_DEBUG={$value}");
            $this->assertTrue(Env::get('PYPETEST_DEBUG'), json_encode($value));
        }
        putenv('PYPETEST_DEBUG=0');
        $this->assertFalse((bool) Env::get('PYPETEST_DEBUG'));
        putenv('PYPETEST_DEBUG');
    }

    public function test_routes_compiled_in_debug_do_not_bring_dev_pages_to_production(): void
    {
        $dev = $this->app(['env' => 'local', 'debug' => true]);
        mkdir($this->base . '/bootstrap/cache', 0775, true);
        file_put_contents($this->base . '/' . Application::ROUTE_CACHE, '<?php return ' . var_export($dev->router->compiled(), true) . ';');

        $client = new TestClient($this->app(['env' => 'production', 'debug' => true]));
        foreach (self::DEV_PAGES as $path) {
            $this->assertSame(404, $client->get($path)->status(), $path);
        }
    }

    public function test_run_turns_display_errors_off_outside_debug(): void
    {
        $saved = ini_get('display_errors');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/warn';

        try {
            foreach ([['local', true, '1'], ['production', true, '0'], ['local', false, '0']] as [$env, $debug, $expected]) {
                ini_set('display_errors', '1');
                $app = $this->app(['env' => $env, 'debug' => $debug]);
                $app->router->get('/warn', function () {
                    $a = [];

                    return ['x' => @$a['nope']];
                });
                ob_start();
                $app->run();
                ob_end_clean();
                $this->assertSame($expected, ini_get('display_errors'), "{$env}, debug " . var_export($debug, true));
            }
        } finally {
            ini_set('display_errors', (string) $saved);
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        }
    }

    private static function assertNoLeak(string $body): void
    {
        foreach ([self::SECRET, self::TEMPLATE_SECRET, '/srv/app', 'RuntimeException', 'LogicException', 'trace', '.php', 'Stack'] as $needle) {
            self::assertStringNotContainsString($needle, $body, "response leaks '{$needle}'");
        }
    }
}

/** An outlet whose render fails (a broken template), after the pipeline has returned. */
final class FailingOutlet extends Outlet
{
    public function render(Response $response, Request $request): string
    {
        throw new \LogicException(ProductionSafetyTest::TEMPLATE_SECRET);
    }

    public function contentType(Response $response): string
    {
        return 'text/html';
    }
}

/** Objects shaped like OpenSwoole's request and response (no extension needed). */
final class FakeSwoole
{
    public static function request(string $path, string $accept): object
    {
        return new class (['request_method' => 'GET', 'request_uri' => $path, 'remote_addr' => '203.0.113.5'], ['accept' => $accept]) {
            public array $get = [];
            public ?array $post = null;
            public array $cookie = [];
            public array $files = [];

            public function __construct(public array $server, public array $header)
            {
            }

            public function rawContent(): string
            {
                return '';
            }
        };
    }

    public static function response(): object
    {
        return new class () {
            public int $status = 200;
            public array $headers = [];
            public ?string $body = null;

            public function status(int $code): void
            {
                $this->status = $code;
            }

            public function header(string $k, string $v): void
            {
                $this->headers[$k] = $v;
            }

            public function rawCookie(mixed ...$args): void
            {
            }

            public function end(string $body = ''): void
            {
                $this->body = $body;
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Console\Console;
use Pype\Routing\Route;
use Pype\Testing\TestClient;

final class RoutesInTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pype-routes-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/routes', 0777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->base);
    }

    private function file(string $relative, string $routes): void
    {
        $path = "{$this->base}/routes/{$relative}";
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "<?php\n\n/** @var Pype\\Routing\\Router \$router */\n\n{$routes}\n");
    }

    private function app(): Application
    {
        return (new Application($this->base))->routesIn("{$this->base}/routes");
    }

    public function test_every_file_loads_in_alphabetical_order_and_api_files_go_under_api(): void
    {
        $this->file('web.php', "\$router->get('/about', fn () => ['page' => 'about'])->name('about');");
        $this->file('api.php', "\$router->get('/health', fn () => ['ok' => true])->name('health');");
        $this->file('quotes/web.php', "\$router->group('/quotes', [], function (\$router) { \$router->get('/', fn () => ['from' => 'web'])->name('index'); }, name: 'quotes.');");
        $this->file('quotes/api.php', "\$router->group('/quotes', [], function (\$router) { \$router->get('/', fn () => ['from' => 'api'])->name('index'); }, name: 'quotes.');");
        $this->file('quotes/admin.php', "\$router->get('/admin/quotes', fn () => ['from' => 'admin']);");

        $app = $this->app();

        $this->assertSame(
            ['/api/health', '/admin/quotes', '/api/quotes', '/quotes', '/about'],
            array_map(static fn (Route $r) => $r->path(), $app->router->routes()),
        );
        $this->assertSame(['api.health', 'api.quotes.index', 'quotes.index', 'about'], array_keys($app->router->names()));

        $client = new TestClient($app);
        $this->assertSame('api', $client->get('/api/quotes')->json('data.from'));
        $this->assertSame('web', $client->get('/quotes')->json('data.from'));
        $this->assertSame('admin', $client->get('/admin/quotes')->json('data.from'));
        $this->assertTrue($client->get('/api/health')->json('data.ok'));
        $this->assertSame(404, $client->get('/health')->status());
    }

    public function test_underscore_files_and_folders_and_the_schedule_are_skipped(): void
    {
        $this->file('web.php', "\$router->get('/', fn () => 'home');");
        $this->file('_helpers.php', "throw new RuntimeException('loaded _helpers.php');");
        $this->file('_old/web.php', "throw new RuntimeException('loaded _old/web.php');");
        $this->file('schedule.php', "throw new RuntimeException('loaded schedule.php');");
        file_put_contents("{$this->base}/routes/notes.md", 'not PHP');

        $this->assertSame(['/'], array_map(static fn (Route $r) => $r->path(), $this->app()->router->routes()));
    }

    public function test_two_routes_with_one_name_fail_while_loading(): void
    {
        $this->file('quotes/web.php', "\$router->get('/quotes', fn () => [])->name('quotes.index');");
        $this->file('invoices/web.php', "\$router->get('/invoices', fn () => [])->name('quotes.index');");

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two routes are named [quotes.index]: GET /invoices and GET /quotes.');
        $this->app();
    }

    public function test_a_missing_folder_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Application($this->base))->routesIn("{$this->base}/nope");
    }

    public function test_make_routes_writes_files_that_routes_in_loads(): void
    {
        $console = static function (Application $app, string ...$argv): array {
            ob_start();
            $code = (new Console($app))->run(['pype', ...$argv]);

            return [$code, (string) ob_get_clean()];
        };

        [$code, $out] = $console(new Application($this->base), 'make:routes', 'quotes');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Created routes/quotes/web.php', $out);
        $this->assertStringContainsString('Created routes/quotes/api.php', $out);
        $this->assertStringContainsString("['web']", (string) file_get_contents("{$this->base}/routes/quotes/web.php"));
        $this->assertStringContainsString("['auth']", (string) file_get_contents("{$this->base}/routes/quotes/api.php"));

        [$code, $out] = $console(new Application($this->base), 'make:routes', 'invoices', '--api');
        $this->assertSame(0, $code);
        $this->assertFileExists("{$this->base}/routes/invoices/api.php");
        $this->assertFileDoesNotExist("{$this->base}/routes/invoices/web.php");

        $this->assertSame(1, $console(new Application($this->base), 'make:routes', 'quotes', '--web')[0]);   // already there
        $this->assertSame(1, $console(new Application($this->base), 'make:routes', 'Quotes')[0]);            // not lowercase

        // The stubs hold only a commented example, so the app boots with no routes from them.
        $this->assertSame([], $this->app()->router->routes());
    }
}

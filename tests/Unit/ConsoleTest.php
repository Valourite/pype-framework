<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Console\Console;
use Pype\Console\Input;
use Pype\Database\Connection;

final class ConsoleTest extends TestCase
{
    private function console(Application $app, string ...$argv): array
    {
        ob_start();
        $code = (new Console($app))->run(['pype', ...$argv]);

        return [$code, (string) ob_get_clean()];
    }

    public function test_input_parsing(): void
    {
        $in = new Input(['2026-10', '--dry-run', '--to=a@b.c', 'extra', '--empty=']);

        $this->assertSame('2026-10', $in->argument(0));
        $this->assertSame('extra', $in->argument(1));
        $this->assertNull($in->argument(2));
        $this->assertSame('a@b.c', $in->option('to'));
        $this->assertSame('', $in->option('empty'));
        $this->assertTrue($in->flag('dry-run'));
        $this->assertNull($in->option('dry-run'));   // a flag has no value
        $this->assertFalse($in->flag('nope'));
    }

    public function test_app_commands_get_input_and_services_injected(): void
    {
        $app = new Application(__DIR__);
        $app->container->instance(Connection::class, Connection::sqlite());
        $app->command('greet', 'Say hello', function (Input $input, Connection $db) {
            echo 'Hello ' . $input->argument(0, 'world') . ' via ' . $db->driver() . "\n";
        });
        $app->command('fail', 'Exit non-zero', GreetFailing::class);

        $this->assertSame([0, "Hello Ada via sqlite\n"], $this->console($app, 'greet', 'Ada'));
        $this->assertSame([3, ''], $this->console($app, 'fail', '--code=3'));

        [, $help] = $this->console($app, 'help');
        $this->assertStringContainsString('greet', $help);
        $this->assertStringContainsString('Say hello', $help);
    }

    public function test_make_command_writes_a_working_class(): void
    {
        $base = sys_get_temp_dir() . '/pype-console-' . bin2hex(random_bytes(4));
        mkdir($base);

        [$code, $out] = $this->console(new Application($base), 'make:command', 'SendReport');
        $file = "{$base}/app/Commands/SendReport.php";

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Created app/Commands/SendReport.php', $out);
        $this->assertStringContainsString("\$app->command('app:send-report'", (string) file_get_contents($file));
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $lint, $lintCode);
        $this->assertSame(0, $lintCode, implode("\n", $lint));

        unlink($file);
        rmdir("{$base}/app/Commands");
        rmdir("{$base}/app");
        rmdir($base);
    }

    public function test_key_generate_sets_app_key_once(): void
    {
        $base = sys_get_temp_dir() . '/pype-key-' . bin2hex(random_bytes(4));
        mkdir($base);
        file_put_contents("{$base}/.env", "APP_NAME=x\nAPP_KEY=\nDB_CONNECTION=sqlite\n");

        [$code] = $this->console(new Application($base), 'key:generate');
        $this->assertSame(0, $code);
        $env = (string) file_get_contents("{$base}/.env");
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m', $env);
        $this->assertStringContainsString("APP_NAME=x\n", $env);
        $this->assertSame(32, strlen(\Pype\Routing\Urls::decodeKey(substr((string) strstr($env, 'base64:'), 0, 51))));

        [$code] = $this->console(new Application($base), 'key:generate');
        $this->assertSame(1, $code, 'an existing key is kept');
        $this->assertSame($env, file_get_contents("{$base}/.env"));

        [$code] = $this->console(new Application($base), 'key:generate', '--force');
        $this->assertSame(0, $code);
        $this->assertNotSame($env, file_get_contents("{$base}/.env"));

        unlink("{$base}/.env");
        rmdir($base);
    }

    public function test_optimize_writes_route_tables_the_app_then_uses(): void
    {
        $base = sys_get_temp_dir() . '/pype-optimize-' . bin2hex(random_bytes(4));
        mkdir($base);
        $boot = function () use ($base): Application {
            $app = new Application($base);
            $app->router->get('/posts/{id}', fn (int $id) => ['id' => $id]);
            $app->router->get('/about', fn () => ['page' => 'about']);

            return $app;
        };

        [$code, $out] = $this->console($boot(), 'optimize');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Compiled 2 routes', $out);
        $this->assertFileExists($base . '/' . Application::ROUTE_CACHE);

        $client = new \Pype\Testing\TestClient($boot());
        $this->assertSame(5, $client->get('/posts/5')->json('data.id'));
        $this->assertSame('about', $client->get('/about')->json('data.page'));

        $this->console($boot(), 'optimize:clear');
        $this->assertFileDoesNotExist($base . '/' . Application::ROUTE_CACHE);
        rmdir("{$base}/bootstrap/cache");
        rmdir("{$base}/bootstrap");
        rmdir($base);
    }
}

final class GreetFailing
{
    public function __invoke(Input $input): int
    {
        return (int) $input->option('code', '1');
    }
}

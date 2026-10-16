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
}

final class GreetFailing
{
    public function __invoke(Input $input): int
    {
        return (int) $input->option('code', '1');
    }
}

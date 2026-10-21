<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Console\Console;

/** php bin/pype install: optional packages for a new app, from options or questions. */
final class InstallCommandTest extends TestCase
{
    /** @return array{0: int, 1: string} */
    private function install(string ...$args): array
    {
        ob_start();
        $code = (new Console(new Application(sys_get_temp_dir())))->run(['pype', 'install', ...$args]);

        return [$code, (string) ob_get_clean()];
    }

    public function test_options_choose_the_packages(): void
    {
        [$code, $out] = $this->install('--template=both', '--faker', '--dry-run');

        $this->assertSame(0, $code);
        $this->assertStringContainsString("'require' 'latte/latte' 'eftec/bladeone'", $out);
        $this->assertStringContainsString("'require' '--dev' 'fakerphp/faker'", $out);
        $this->assertStringContainsString('would add: Latte templates', $out);

        [, $out] = $this->install('--template=blade', '--no-faker', '--dry-run');
        $this->assertStringContainsString("'require' 'eftec/bladeone'", $out);
        $this->assertStringNotContainsString('latte', $out);
        $this->assertStringNotContainsString('faker', $out);
    }

    public function test_without_a_terminal_nothing_is_added(): void
    {
        // PHPUnit's STDIN is not a terminal, like CI: no questions, plain PHP, no Faker.
        [$code, $out] = $this->install('--dry-run');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing extra to install', $out);
    }

    public function test_an_unknown_engine_is_refused(): void
    {
        $this->assertSame(1, $this->install('--template=twig', '--dry-run')[0]);
    }
}

<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Support\Env;

final class EnvTest extends TestCase
{
    private const VARS = ['PYPETEST_MAX_REQUEST', 'PYPETEST_ENABLE_COROUTINE', 'PYPETEST_MODE', 'PYPETEST_RATIO', 'PYPETEST_CACHE_SIZE', 'PYPETEST_BLANK', 'PYPETEST_NOTHING'];

    protected function tearDown(): void
    {
        foreach (self::VARS as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    public function test_prefixed_collects_settings_with_their_types(): void
    {
        putenv('PYPETEST_MAX_REQUEST=0');                 // real environment variables
        putenv('PYPETEST_ENABLE_COROUTINE=false');
        $_ENV['PYPETEST_MODE'] = 'process';               // and ones .env loaded
        $_ENV['PYPETEST_RATIO'] = '0.5';
        $_ENV['PYPETEST_CACHE_SIZE'] = '-64000';
        $_ENV['PYPETEST_BLANK'] = '';                     // blank: keep the config's default
        $_ENV['PYPETEST_NOTHING'] = 'null';

        $this->assertSame([
            'cache_size' => -64000,
            'enable_coroutine' => false,
            'max_request' => 0,
            'mode' => 'process',
            'nothing' => null,
            'ratio' => 0.5,
        ], Env::prefixed('PYPETEST_'));
        $this->assertSame(['max_request' => 0], array_intersect_key(env_prefixed('PYPETEST_'), ['max_request' => 1]));
        $this->assertSame('0', Env::get('PYPETEST_MAX_REQUEST'), 'env() itself still returns the string');
    }
}

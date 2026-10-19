<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Security\DecryptException;
use Pype\Security\Encrypter;

final class EncrypterTest extends TestCase
{
    private function key(string $seed): string
    {
        return hash('sha256', $seed, true);
    }

    public function test_round_trips_values_and_never_repeats_ciphertext(): void
    {
        $e = new Encrypter($this->key('a'));

        foreach (['secret', 42, 1.5, true, null, ['token' => 'abc', 'scopes' => ['read', 'write']], 'ünïcødé ✓'] as $value) {
            $this->assertSame($value, $e->decrypt($e->encrypt($value)));
        }

        $this->assertNotSame($e->encrypt('same'), $e->encrypt('same'), 'fresh nonce each time');
        $this->assertStringStartsWith('pype1.', $e->encrypt('x'));
        $this->assertMatchesRegularExpression('/^pype1\.[A-Za-z0-9_-]+$/', $e->encrypt(['a' => 1]), 'safe in URLs and cookies');
    }

    public function test_tampered_foreign_and_garbage_values_fail(): void
    {
        $e = new Encrypter($this->key('a'));
        $box = $e->encrypt('secret');

        foreach ([
            'tampered' => substr($box, 0, -2) . ($box[-2] === 'A' ? 'B' : 'A') . $box[-1],
            'other key' => (new Encrypter($this->key('b')))->encrypt('secret'),
            'garbage' => 'pype1.not-base64!!',
            'short' => 'pype1.AAAA',
            'no prefix' => 'hello',
        ] as $case => $payload) {
            try {
                $e->decrypt($payload);
                $this->fail("{$case} should not decrypt");
            } catch (DecryptException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_previous_keys_still_decrypt_after_rotation(): void
    {
        $old = new Encrypter($this->key('old'));
        $box = $old->encrypt(['token' => 't']);

        $rotated = new Encrypter($this->key('new'), [$this->key('old')]);
        $this->assertSame(['token' => 't'], $rotated->decrypt($box));
        $this->assertSame('n', $rotated->decrypt($rotated->encrypt('n')));

        $this->expectException(DecryptException::class);
        (new Encrypter($this->key('new')))->decrypt($box);
    }

    public function test_key_comes_from_app_key_and_must_be_32_bytes(): void
    {
        $app = new Application(__DIR__, ['app' => ['key' => 'base64:' . base64_encode($this->key('k')), 'previous_keys' => ['base64:' . base64_encode($this->key('p'))]]]);
        $e = $app->container->get(Encrypter::class);
        $this->assertSame('x', $e->decrypt((new Encrypter($this->key('p')))->encrypt('x')), 'APP_PREVIOUS_KEYS');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('key:generate');
        (new Application(__DIR__))->container->get(Encrypter::class);
    }
}

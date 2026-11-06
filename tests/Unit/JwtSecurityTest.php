<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Auth\InvalidToken;
use Pype\Auth\Jwt;

/** Jwt on lcobucci/jwt: key rules, more attacks, and tokens other code signs or reads. */
final class JwtSecurityTest extends TestCase
{
    private const SECRET = 'a-test-secret-that-is-long-enough-1234';

    private int $time = 1_800_000_000;

    /** @var array<int, array{string, string}> */
    private static array $keys = [];

    /** @return array{0: string, 1: string} private, public PEM */
    private static function rsa(int $n = 0, int $bits = 2048): array
    {
        if (!isset(self::$keys[$n])) {
            $key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$keys[$n] = [$private, openssl_pkey_get_details($key)['key']];
        }

        return self::$keys[$n];
    }

    private function jwt(array $config = ['secret' => self::SECRET]): Jwt
    {
        return new Jwt($config, fn (): int => $this->time);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function hs256(array $header, array $claims, string $secret = self::SECRET): string
    {
        $input = self::b64(json_encode($header)) . '.' . self::b64(json_encode($claims));

        return $input . '.' . self::b64(hash_hmac('sha256', $input, $secret, true));
    }

    private function rejects(Jwt $jwt, string $token, ?string $reason = null): void
    {
        try {
            $jwt->verify($token);
            $this->fail('accepted a token that should fail' . ($reason ? ": {$reason}" : ''));
        } catch (InvalidToken $e) {
            if ($reason !== null) {
                $this->assertSame($reason, $e->getMessage());
            } else {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_secrets_shorter_than_32_bytes_are_a_config_error(): void
    {
        foreach ([fn () => $this->jwt(['secret' => 'too-short'])->issue(['sub' => '1']),
                  fn () => $this->jwt(['secret' => 'too-short'])->verify(self::hs256(['alg' => 'HS256'], ['exp' => $this->time + 60], 'too-short'))] as $call) {
            try {
                $call();
                $this->fail('a short secret was accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('at least 32 bytes', $e->getMessage());
            }
        }

        $this->expectExceptionMessage('auth.jwt.secret is not set.');
        $this->jwt([])->issue(['sub' => '1']);
    }

    public function test_a_short_secret_stops_the_app_at_boot(): void
    {
        $this->expectExceptionMessage('at least 32 bytes');
        (new \Pype\Application(__DIR__, ['auth' => ['jwt' => ['secret' => 'too-short']]]))->register(\Pype\Auth\AuthProvider::class);
    }

    public function test_no_secret_or_a_long_one_boots(): void
    {
        foreach ([[], ['secret' => str_repeat('k', 32)], ['public_key' => 'only RS256']] as $jwt) {
            (new \Pype\Application(__DIR__, ['auth' => ['jwt' => $jwt]]))->register(\Pype\Auth\AuthProvider::class);
        }
        $this->addToAssertionCount(1);
    }

    public function test_rsa_keys_under_2048_bits_are_refused(): void
    {
        [$private] = self::rsa(9, 1024);

        $this->expectException(\RuntimeException::class);
        $this->jwt(['private_key' => $private])->issue(['sub' => '1']);
    }

    public function test_tokens_are_standard_and_claims_come_back_as_sent(): void
    {
        $token = $this->jwt(['secret' => self::SECRET, 'issuer' => 'https://app.example'])->issue([
            'sub' => '5', 'aud' => 'api', 'nbf' => $this->time - 10, 'jti' => 'abc', 'roles' => ['a', 'b'], 'n' => 3, 'url' => 'https://x.test/a',
        ], ttl: 120);
        [$h, $p, $s] = explode('.', $token);

        // Anyone with the secret can check it with plain hash_hmac (jwt.io, other languages).
        $this->assertSame($s, self::b64(hash_hmac('sha256', "{$h}.{$p}", self::SECRET, true)));
        $this->assertSame(['typ' => 'JWT', 'alg' => 'HS256'], json_decode(base64_decode(strtr($h, '-_', '+/')), true));

        $claims = $this->jwt(['secret' => self::SECRET, 'issuer' => 'https://app.example', 'audience' => 'api'])->verify($token);
        $this->assertSame([
            'sub' => '5', 'aud' => 'api', 'nbf' => $this->time - 10, 'jti' => 'abc', 'roles' => ['a', 'b'], 'n' => 3,
            'url' => 'https://x.test/a', 'iat' => $this->time, 'exp' => $this->time + 120, 'iss' => 'https://app.example',
        ], $claims);
    }

    public function test_more_attacks(): void
    {
        [$private, $public] = self::rsa();
        [$otherPrivate, $otherPublic] = self::rsa(1);
        $both = $this->jwt(['secret' => self::SECRET, 'public_key' => ['k1' => $public, 'k2' => $otherPublic]]);

        // A token signed with key 2 that claims to be key 1.
        $wrongKid = $this->jwt(['private_key' => $otherPrivate, 'kid' => 'k1'])->issue(['sub' => '1']);
        $this->rejects($both, $wrongKid, 'Bad signature.');

        // An RS256 token relabelled HS256 (same payload and signature), where both are configured.
        $rs = $this->jwt(['private_key' => $private, 'kid' => 'k1'])->issue(['sub' => '1']);
        [, $p, $s] = explode('.', $rs);
        $this->rejects($both, self::b64('{"alg":"HS256","typ":"JWT","kid":"k1"}') . ".{$p}.{$s}", 'Bad signature.');

        // Algorithm confusion with both configured: HS256 signed with the public key as the secret.
        $this->rejects($both, self::hs256(['alg' => 'HS256'], ['sub' => '1', 'exp' => $this->time + 60], $public), 'Bad signature.');

        // Stripped or empty signature, none in any case, unknown algorithms.
        $good = $this->jwt()->issue(['sub' => '1']);
        [$h, $p] = explode('.', $good);
        $this->rejects($this->jwt(), "{$h}.{$p}.");   // Malformed JWT. (the parser refuses an empty signature)
        foreach (['none', 'None', 'NONE', 'HS384', 'RS512', 'ES256', 'PS256', ''] as $alg) {
            $this->rejects($this->jwt(), self::hs256(['alg' => $alg], ['sub' => '1', 'exp' => $this->time + 60]), 'Algorithm not accepted.');
        }

        // Claims that don't parse: a non-numeric expiry.
        $this->rejects($this->jwt(), self::hs256(['alg' => 'HS256'], ['sub' => '1', 'exp' => 'tomorrow']));
    }

    public function test_issued_in_the_future_is_not_valid_yet(): void
    {
        $jwt = $this->jwt(['secret' => self::SECRET, 'leeway' => 30]);

        $this->assertSame('1', $jwt->verify(self::hs256(['alg' => 'HS256'], ['sub' => '1', 'iat' => $this->time + 30, 'exp' => $this->time + 600]))['sub']);
        $this->rejects($jwt, self::hs256(['alg' => 'HS256'], ['sub' => '1', 'iat' => $this->time + 31, 'exp' => $this->time + 600]), 'Not valid yet.');
    }

    public function test_one_instance_serves_many_tokens_and_clocks(): void
    {
        $jwt = $this->jwt();
        $token = $jwt->issue(['sub' => '1'], ttl: 60);

        foreach ([0, 30, 89] as $later) {
            $this->time = 1_800_000_000 + $later;
            $this->assertSame('1', $jwt->verify($token)['sub']);
        }
        $this->time = 1_800_000_000 + 90;
        $this->rejects($jwt, $token, 'Expired.');
        $this->time = 1_800_000_000;
        $this->assertSame('1', $jwt->verify($token)['sub'], 'no state kept from the failed call');
    }
}

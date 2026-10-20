<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\Auth;
use Pype\Auth\AuthProvider;
use Pype\Auth\InvalidToken;
use Pype\Auth\Jwt;
use Pype\Auth\JwtClaims;
use Pype\Auth\JwtGuard;
use Pype\Auth\JwtUser;
use Pype\Auth\TokenGuard;
use Pype\Database\Connection;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;

final class JwtTest extends TestCase
{
    private const SECRET = 'a-test-secret-that-is-long-enough-1234';

    private int $time = 1_800_000_000;

    private static ?array $keys = null;

    /** @return array{0: string, 1: string} private, public PEM */
    private static function rsa(): array
    {
        if (self::$keys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$keys = [$private, openssl_pkey_get_details($key)['key']];
        }

        return self::$keys;
    }

    private function jwt(array $config = ['secret' => self::SECRET]): Jwt
    {
        return new Jwt($config, fn (): int => $this->time);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function forge(array $header, array $claims, string $signature = ''): string
    {
        return self::b64(json_encode($header)) . '.' . self::b64(json_encode($claims)) . '.' . self::b64($signature);
    }

    private function rejects(Jwt $jwt, string $token, string $reason): void
    {
        try {
            $jwt->verify($token);
            $this->fail("accepted a token that should fail: {$reason}");
        } catch (InvalidToken $e) {
            $this->assertSame($reason, $e->getMessage());
        }
    }

    public function test_hs256_round_trip_with_iat_and_exp(): void
    {
        $jwt = $this->jwt(['secret' => self::SECRET, 'ttl' => 600]);
        $claims = $jwt->verify($jwt->issue(['sub' => '5', 'role' => 'admin']));

        $this->assertSame(['sub' => '5', 'role' => 'admin', 'iat' => $this->time, 'exp' => $this->time + 600], $claims);
    }

    public function test_matches_a_token_signed_elsewhere(): void
    {
        // Built by hand with openssl's HMAC, like the challenge's test suite and jwt.io do.
        $input = self::b64('{"alg":"HS256","typ":"JWT"}') . '.' . self::b64('{"sub":"1","exp":1900000000}');
        $token = $input . '.' . self::b64(hash_hmac('sha256', $input, 'twelve-dollar-challenge', true));

        $this->assertSame('1', $this->jwt(['secret' => 'twelve-dollar-challenge'])->verify($token)['sub']);
    }

    public function test_rs256_round_trip_and_key_ids(): void
    {
        [$private, $public] = self::rsa();
        $issuer = $this->jwt(['private_key' => $private, 'kid' => 'k2']);
        $token = $issuer->issue(['sub' => '7']);

        $this->assertSame('RS256', json_decode(base64_decode(explode('.', $token)[0]), true)['alg']);
        $this->assertSame('7', $this->jwt(['public_key' => $public])->verify($token)['sub']);
        $this->assertSame('7', $this->jwt(['public_key' => ['k1' => 'unused', 'k2' => $public]])->verify($token)['sub']);
        $this->rejects($this->jwt(['public_key' => ['k1' => $public, 'k3' => $public]]), $issuer->issue(['sub' => '7']) . '', 'Unknown key id.');
    }

    public function test_tampering_and_attacks_are_rejected(): void
    {
        $jwt = $this->jwt();
        $token = $jwt->issue(['sub' => '5']);
        [$h, $p, $s] = explode('.', $token);

        $this->rejects($jwt, $h . '.' . self::b64(json_encode(['sub' => '1', 'exp' => $this->time + 60])) . '.' . $s, 'Bad signature.');
        $this->rejects($jwt, "{$h}.{$p}." . self::b64('nope'), 'Bad signature.');
        $this->rejects($this->jwt(['secret' => 'another-secret']), $token, 'Bad signature.');
        $this->rejects($jwt, self::forge(['alg' => 'none'], ['sub' => '1', 'exp' => $this->time + 60]), 'Algorithm not accepted.');
        $this->rejects($jwt, self::forge(['alg' => 'HS512'], ['sub' => '1', 'exp' => $this->time + 60], 'x'), 'Algorithm not accepted.');
        $this->rejects($jwt, 'abc', 'Not a JWT.');
        $this->rejects($jwt, 'a.b', 'Not a JWT.');
        $this->rejects($jwt, 'a$.b.c', 'Malformed JWT.');
        $this->rejects($jwt, self::b64('[1]') . ".{$p}.{$s}", 'Malformed JWT.');

        // Algorithm confusion: an RS256 setup must not accept HS256 tokens signed with its public key.
        [, $public] = self::rsa();
        $input = self::b64('{"alg":"HS256","typ":"JWT"}') . '.' . self::b64(json_encode(['sub' => '1', 'exp' => $this->time + 60]));
        $confused = $input . '.' . self::b64(hash_hmac('sha256', $input, $public, true));
        $this->rejects($this->jwt(['public_key' => $public]), $confused, 'Bad signature.');

        // And an HS256-only setup doesn't accept RS256 at all.
        [$private] = self::rsa();
        $this->rejects($jwt, $this->jwt(['private_key' => $private])->issue(['sub' => '1']), 'Algorithm not accepted.');
    }

    public function test_times_issuer_and_audience(): void
    {
        $jwt = $this->jwt(['secret' => self::SECRET, 'leeway' => 30]);
        $token = $jwt->issue(['sub' => '5'], ttl: 60);

        $this->time += 89;   // within exp + leeway
        $this->assertSame('5', $jwt->verify($token)['sub']);
        $this->time += 1;
        $this->rejects($jwt, $token, 'Expired.');

        $this->rejects($jwt, self::sign(['sub' => '5']), 'No expiry.');
        $this->rejects($jwt, self::sign(['sub' => '5', 'exp' => $this->time + 600, 'nbf' => $this->time + 31]), 'Not valid yet.');
        $this->assertSame('5', $jwt->verify(self::sign(['sub' => '5', 'exp' => $this->time + 600, 'nbf' => $this->time + 30]))['sub']);

        $strict = $this->jwt(['secret' => self::SECRET, 'issuer' => 'https://id.example', 'audience' => 'api']);
        $this->assertSame('5', $strict->verify(self::sign(['sub' => '5', 'exp' => $this->time + 60, 'iss' => 'https://id.example', 'aud' => ['web', 'api']]))['sub']);
        $this->rejects($strict, self::sign(['sub' => '5', 'exp' => $this->time + 60, 'iss' => 'https://evil.example', 'aud' => 'api']), 'Wrong issuer.');
        $this->rejects($strict, self::sign(['sub' => '5', 'exp' => $this->time + 60, 'iss' => 'https://id.example', 'aud' => 'web']), 'Wrong audience.');
        $this->assertSame('https://id.example', $strict->verify($strict->issue(['sub' => '5', 'aud' => 'api']))['iss']);
    }

    private static function sign(array $claims): string
    {
        $input = self::b64('{"alg":"HS256","typ":"JWT"}') . '.' . self::b64(json_encode($claims));

        return $input . '.' . self::b64(hash_hmac('sha256', $input, self::SECRET, true));
    }

    private function app(array $jwt, array $guards): Application
    {
        $app = new Application(__DIR__, ['auth' => ['user' => JwtTestUser::class, 'guards' => $guards, 'jwt' => $jwt]]);
        $app->container->instance(Connection::class, TestDatabase::withAuthTables());
        $app->register(AuthProvider::class);
        $app->container->instance(Jwt::class, new Jwt($jwt, fn (): int => $this->time));
        $app->router->get('/me', ['auth', fn (JwtTestUser $user, JwtClaims $claims) => ['id' => $user->id, 'role' => $claims->get('role')]]);
        $app->router->get('/token-me', ['auth', fn (JwtTestUser $user) => ['id' => $user->id]]);
        $app->router->get('/claims-me', ['auth', fn (JwtUser $user, JwtClaims $claims) => ['id' => $user->id, 'role' => $claims->get('role')]]);

        return $app;
    }

    public function test_guard_loads_the_user_by_sub(): void
    {
        $app = $this->app(['secret' => self::SECRET], [JwtGuard::class]);
        $ada = $app->container->get(Auth::class)->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret123']);
        $jwt = $app->container->get(Jwt::class);
        $client = new TestClient($app);
        $as = fn (string $token) => $client->withHeaders(['Authorization' => "Bearer {$token}"])->get('/me');

        $res = $as($jwt->issue(['sub' => (string) $ada->id, 'role' => 'admin']));
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(['id' => $ada->id, 'role' => 'admin'], $res->json('data'));

        $this->assertSame('Invalid or expired token', $as($jwt->issue(['sub' => '1'], ttl: -100))->json('error.message'));
        $this->assertSame('Invalid token payload', $as($jwt->issue(['sub' => 'abc']))->json('error.message'));
        $this->assertSame('Invalid token payload', $as($jwt->issue(['sub' => '999']))->json('error.message'));   // no such user
        $this->assertSame(401, $client->get('/me')->status());
        $this->assertSame(401, $as('not-a-jwt')->status());   // left to other guards: none here
    }

    public function test_guard_chains_with_api_tokens(): void
    {
        $app = $this->app(['secret' => self::SECRET], [JwtGuard::class, TokenGuard::class]);
        $auth = $app->container->get(Auth::class);
        $ada = $auth->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret123']);
        $client = new TestClient($app);

        $apiToken = $auth->issueToken($ada)->plainText;
        $this->assertSame($ada->id, $client->withHeaders(['Authorization' => "Bearer {$apiToken}"])->get('/token-me')->json('data.id'));

        $jwt = $app->container->get(Jwt::class)->issue(['sub' => (string) $ada->id]);
        $this->assertSame($ada->id, $client->withHeaders(['Authorization' => "Bearer {$jwt}"])->get('/token-me')->json('data.id'));
    }

    public function test_claims_users_and_only_mode(): void
    {
        $app = $this->app(['secret' => self::SECRET, 'user' => 'claims', 'only' => true], [JwtGuard::class]);
        $jwt = $app->container->get(Jwt::class);
        $client = new TestClient($app);

        $res = $client->withHeaders(['Authorization' => 'Bearer ' . $jwt->issue(['sub' => 'auth0|42', 'role' => 'editor'])])->get('/claims-me');
        $this->assertSame(['id' => 'auth0|42', 'role' => 'editor'], $res->json('data'));

        // only: a bearer token that isn't a JWT is invalid, not "missing".
        $this->assertSame('Invalid or expired token', $client->withHeaders(['Authorization' => 'Bearer abc'])->get('/claims-me')->json('error.message'));
    }

    public function test_guard_keeps_no_state_between_requests(): void
    {
        $app = $this->app(['secret' => self::SECRET, 'user' => 'claims'], [JwtGuard::class]);
        $jwt = $app->container->get(Jwt::class);
        $client = new TestClient($app);

        foreach (range(1, 20) as $i) {
            $this->assertSame((string) $i, $client->withHeaders(['Authorization' => 'Bearer ' . $jwt->issue(['sub' => (string) $i])])->get('/claims-me')->json('data.id'));
            $this->assertSame(401, $client->get('/claims-me')->status());
        }
    }
}

final class JwtTestUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $email_verified_at = null,
    ) {
    }
}

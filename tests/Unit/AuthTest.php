<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\AccessToken;
use Pype\Auth\Auth;
use Pype\Auth\AuthProvider;
use Pype\Auth\Authenticate;
use Pype\Database\Connection;
use Pype\Stages\Throttle;
use Pype\Testing\TestClient;

final class AuthTest extends TestCase
{
    private Application $app;
    private Auth $auth;
    private TestClient $client;

    protected function setUp(): void
    {
        $this->app = new Application(__DIR__, ['auth' => ['user' => AuthUser::class]]);
        $db = Connection::sqlite();
        $db->statement((string) file_get_contents(__DIR__ . '/../fixtures/auth_schema.sql'));
        $this->app->container->instance(Connection::class, $db);
        $this->app->register(AuthProvider::class);

        $this->auth = $this->app->container->get(Auth::class);
        $this->client = new TestClient($this->app);
    }

    public function test_create_user_hashes_password_and_hydrates_without_it(): void
    {
        $user = $this->auth->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret123']);

        $this->assertInstanceOf(AuthUser::class, $user);
        $this->assertSame('Ada', $user->name);
        $this->assertNotSame('secret123', $this->app->container->get(Connection::class)->scalar('SELECT password FROM users'));
    }

    public function test_attempt_checks_credentials(): void
    {
        $this->auth->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret123']);

        $this->assertSame('Ada', $this->auth->attempt('ada@x.test', 'secret123')?->name);
        $this->assertNull($this->auth->attempt('ada@x.test', 'wrong'));
        $this->assertNull($this->auth->attempt('nobody@x.test', 'secret123'));
    }

    public function test_token_round_trip_through_auth_stack_and_revocation(): void
    {
        $user = $this->auth->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret123']);
        $token = $this->auth->issueToken($user);

        $this->assertStringStartsWith('pype_', $token->plainText);
        $this->assertSame(64, strlen($this->app->container->get(Connection::class)->scalar('SELECT token_hash FROM api_tokens')));

        $this->app->router->get('/me', ['auth', fn (AuthUser $u, AccessToken $t) => ['name' => $u->name, 'token' => $t->id]]);

        $this->assertSame(401, $this->client->get('/me')->status());
        $this->assertSame('Bearer', $this->client->get('/me')->header('WWW-Authenticate'));

        $ok = $this->client->get('/me', ['Authorization' => 'Bearer ' . $token->plainText]);
        $this->assertSame(['name' => 'Ada', 'token' => $token->accessToken->id], $ok->json('data'));

        $this->auth->revoke($token->accessToken);
        $this->assertSame(401, $this->client->get('/me', ['Authorization' => 'Bearer ' . $token->plainText])->status());
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $user = $this->auth->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret123']);
        $token = $this->auth->issueToken($user, ttlMinutes: -1);

        $this->assertNull($this->auth->authenticateToken($token->plainText));
        $this->assertSame(1, $this->auth->pruneExpired());
    }

    public function test_optional_authentication(): void
    {
        $this->app->router->get('/feed', [new Authenticate(optional: true), fn (?AuthUser $u) => ['user' => $u?->name]]);

        $this->assertSame(['user' => null], $this->client->get('/feed')->json('data'));
    }

    public function test_throttle(): void
    {
        $this->app->router->post('/login', [new Throttle(maxAttempts: 2, decaySeconds: 60), fn () => 'ok']);

        $this->assertSame('1', $this->client->post('/login')->header('X-RateLimit-Remaining'));
        $this->assertSame(200, $this->client->post('/login')->status());

        $blocked = $this->client->post('/login');
        $this->assertSame(429, $blocked->status());
        $this->assertNotNull($blocked->header('Retry-After'));
    }
}

final class AuthUser
{
    public function __construct(public readonly int $id, public readonly string $name, public readonly string $email)
    {
    }
}

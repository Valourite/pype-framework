<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\Auth;
use Pype\Auth\AuthProvider;
use Pype\Auth\Authenticate;
use Pype\Auth\EnsureEmailIsVerified;
use Pype\Auth\PasswordResets;
use Pype\Database\Connection;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;

final class PasswordResetTest extends TestCase
{
    private Application $app;
    private Connection $db;
    private Auth $auth;
    private PasswordResets $resets;
    private object $ada;

    protected function setUp(): void
    {
        $this->app = new Application(__DIR__, ['auth' => ['user' => AuthUser::class, 'reset_throttle' => 0]]);
        $this->db = TestDatabase::withAuthTables();
        $this->app->container->instance(Connection::class, $this->db);
        $this->app->register(AuthProvider::class);
        $this->auth = $this->app->container->get(Auth::class);
        $this->resets = $this->app->container->get(PasswordResets::class);
        $this->ada = $this->auth->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'old-password']);
    }

    public function test_reset_changes_the_password_once_and_revokes_api_tokens(): void
    {
        $this->auth->issueToken($this->ada);
        $token = $this->resets->create($this->ada);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $this->db->table('password_resets')->value('token_hash'), 'only the hash is stored');
        $this->assertTrue($this->resets->valid($token));

        $user = $this->resets->reset($token, 'new-password');
        $this->assertSame($this->ada->id, $user?->id);
        $this->assertNull($this->auth->attempt('ada@x.test', 'old-password'));
        $this->assertNotNull($this->auth->attempt('ada@x.test', 'new-password'));
        $this->assertSame(0, $this->db->table('api_tokens')->count(), 'API tokens revoked');

        $this->assertNull($this->resets->reset($token, 'again-password'), 'a token works once');
        $this->assertFalse($this->resets->valid($token));
    }

    public function test_wrong_old_and_expired_tokens_fail(): void
    {
        $first = $this->resets->create($this->ada);
        $second = $this->resets->create($this->ada);

        $this->assertNull($this->resets->reset($first, 'x-password'), 'a new link replaces the old one');
        $this->assertNull($this->resets->reset(str_repeat('a', 64), 'x-password'));
        $this->assertNull($this->resets->reset('../../etc', 'x-password'));

        $this->db->table('password_resets')->update(['created_at' => time() - 61 * 60]);
        $this->assertNull($this->resets->reset($second, 'x-password'), 'expired after 60 minutes');
        $this->assertNotNull($this->auth->attempt('ada@x.test', 'old-password'));
        $this->assertSame(1, $this->resets->pruneExpired());
    }

    public function test_links_can_not_be_sent_too_often(): void
    {
        $app = new Application(__DIR__, ['auth' => ['user' => AuthUser::class]]);
        $app->container->instance(Connection::class, $this->db);
        $app->register(AuthProvider::class);
        $resets = $app->container->get(PasswordResets::class);

        $this->assertNotNull($resets->create($this->ada));
        $this->assertNull($resets->create($this->ada), 'within reset_throttle (60 s)');
    }

    public function test_a_used_link_can_not_change_the_password_again(): void
    {
        // (Two requests truly racing are handled by deleting the token as the check, inside the
        // transaction: only the one that removes it continues. That needs two processes to test.)
        $token = $this->resets->create($this->ada);
        $this->assertTrue($this->resets->valid($token));
        $this->assertNotNull($this->resets->reset($token, 'first-password'));
        $this->assertNull($this->resets->reset($token, 'second-password'));
        $this->assertNotNull($this->auth->attempt('ada@x.test', 'first-password'));
    }

    public function test_email_verification(): void
    {
        $this->assertFalse($this->auth->isEmailVerified($this->ada));
        $this->auth->markEmailVerified($this->ada);
        $this->assertTrue($this->auth->isEmailVerified($this->ada->id));

        $hash = $this->auth->verificationHash($this->ada);
        $this->assertSame($hash, $this->auth->verificationHash(new AuthUser($this->ada->id, 'Ada', 'ADA@x.test')), 'case-insensitive');
        $this->assertNotSame($hash, $this->auth->verificationHash(new AuthUser($this->ada->id, 'Ada', 'ada@new.test')), 'changes with the email');
    }

    public function test_ensure_email_is_verified_stage(): void
    {
        $bob = $this->auth->createUser(['name' => 'Bob', 'email' => 'bob@x.test', 'password' => 'bob-password']);
        $token = $this->auth->issueToken($bob)->plainText;
        $this->app->router->get('/billing', [new Authenticate(), new EnsureEmailIsVerified(redirectTo: '/email/verify'), fn () => 'ok']);
        $client = new TestClient($this->app);
        $api = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $res = $client->get('/billing', $api);
        $this->assertSame(403, $res->status());
        $this->assertSame('Your email address is not verified.', $res->json('error.message'));
        $this->assertSame('/email/verify', $client->get('/billing', ['Authorization' => "Bearer {$token}"])->header('Location'), 'browsers are redirected');

        $this->auth->markEmailVerified($bob);
        $this->assertSame(200, $client->get('/billing', $api)->status());
    }
}

<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\Auth;
use Pype\Auth\AuthProvider;
use Pype\Auth\Authenticate;
use Pype\Auth\PasswordResets;
use Pype\Auth\SessionGuard;
use Pype\Database\Connection;
use Pype\Http\Request;
use Pype\Session\Session;
use Pype\Session\SessionProvider;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;

final class RememberMeTest extends TestCase
{
    private Application $app;
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::withAuthTables();
        $this->app = $this->app(['remember_table' => 'remember_tokens']);
    }

    private function app(array $auth): Application
    {
        $app = new Application(__DIR__, ['auth' => ['user' => AuthUser::class, 'reset_throttle' => 0] + $auth, 'session' => ['driver' => 'array']]);
        $app->container->instance(Connection::class, $this->db);
        $app->register(AuthProvider::class)->register(SessionProvider::class);
        if ($app->container->get(Auth::class)->findBy('ada@x.test') === null) {
            $app->container->get(Auth::class)->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret123']);
        }

        $app->router->group('/', ['web'], function ($r) {
            $r->get('/form', fn (Session $s) => ['token' => $s->token()]);
            $r->post('/login', function (Request $req, Session $s, SessionGuard $guard, Auth $auth) {
                $guard->login($s, $auth->attempt('ada@x.test', 'secret123'), remember: (bool) $req->input('remember'), userAgent: $req->header('user-agent'));

                return 'in';
            });
            $r->post('/logout', fn (Request $req, Session $s, SessionGuard $guard) => $guard->logout($s, $req) ?? 'out');
            $r->get('/me', [new Authenticate(), fn (AuthUser $u) => ['name' => $u->name]]);
        });

        return $app;
    }

    private function login(TestClient $client, bool $remember = true): void
    {
        $client->post('/login', ['remember' => $remember ? '1' : '', '_token' => $client->get('/form')->json('data.token')], ['User-Agent' => 'Firefox on Linux']);
    }

    /** A browser that comes back later: no session, only the remember cookie. */
    private function returning(string $cookie): TestClient
    {
        $client = new TestClient($this->app);
        $client->cookies()->set('pype_remember', $cookie);

        return $client;
    }

    public function test_remembered_browser_is_signed_back_in_with_a_fresh_cookie(): void
    {
        $browser = new TestClient($this->app);
        $this->login($browser);
        $first = $browser->cookies()->get('pype_remember');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}:[0-9a-f]{64}$/', $first);

        $row = $this->db->table('remember_tokens')->first();
        $this->assertSame(hash('sha256', explode(':', $first)[1]), $row['validator_hash'], 'only a hash is stored');
        $this->assertSame('Firefox on Linux', $row['user_agent']);

        $later = $this->returning($first);
        $res = $later->get('/me');
        $this->assertSame('Ada', $res->json('data.name'));
        $cookie = $res->cookie('pype_remember');
        $this->assertNotNull($cookie, 'a new cookie value');
        $this->assertNotSame($first, $cookie->value);
        $this->assertTrue($cookie->httpOnly);
        $this->assertSame(30 * 86400, $cookie->maxAge);

        // Now it has a session too: no more lookups, same user.
        $this->assertSame('Ada', $later->get('/me')->json('data.name'));
    }

    public function test_without_ticking_the_box_nothing_is_remembered(): void
    {
        $browser = new TestClient($this->app);
        $this->login($browser, remember: false);

        $this->assertNull($browser->cookies()->get('pype_remember'));
        $this->assertSame(0, $this->db->table('remember_tokens')->count());
    }

    public function test_simultaneous_requests_with_the_same_cookie_are_fine(): void
    {
        $browser = new TestClient($this->app);
        $this->login($browser);
        $cookie = $browser->cookies()->get('pype_remember');

        // Two tabs restore at once: the first swaps the validator, the second still carries the old one.
        $this->assertSame(200, $this->returning($cookie)->get('/me')->status());
        $second = $this->returning($cookie)->get('/me');
        $this->assertSame(200, $second->status());
        $this->assertNull($second->cookie('pype_remember'), 'keeps the cookie the first tab got');
    }

    public function test_a_replayed_old_cookie_signs_out_every_remembered_device(): void
    {
        $laptop = new TestClient($this->app);
        $this->login($laptop);
        $phone = new TestClient($this->app);
        $this->login($phone);
        $stolen = $laptop->cookies()->get('pype_remember');

        $this->assertSame(200, $this->returning($stolen)->get('/me')->status());   // the real owner, later
        $this->db->table('remember_tokens')->update(['rotated_at' => time() - 120]);  // past the grace window

        $attacker = $this->returning($stolen)->get('/me');
        $this->assertSame(401, $attacker->status());
        $this->assertSame('', $attacker->cookie('pype_remember')?->value, 'cookie cleared');
        $this->assertSame(0, $this->db->table('remember_tokens')->count(), 'every device signed out');
        $this->assertSame(401, $this->returning((string) $phone->cookies()->get('pype_remember'))->get('/me')->status());
    }

    public function test_expired_tampered_and_unknown_cookies_are_ignored(): void
    {
        $browser = new TestClient($this->app);
        $this->login($browser);
        $cookie = $browser->cookies()->get('pype_remember');

        $this->assertSame(401, $this->returning('garbage')->get('/me')->status());
        $this->assertSame(401, $this->returning(str_repeat('a', 24) . ':' . str_repeat('b', 64))->get('/me')->status());

        $this->db->table('remember_tokens')->update(['expires_at' => time() - 1]);
        $this->assertSame(401, $this->returning($cookie)->get('/me')->status());
    }

    public function test_logout_and_password_reset_forget_remembered_devices(): void
    {
        $browser = new TestClient($this->app);
        $this->login($browser);
        $cookie = $browser->cookies()->get('pype_remember');

        $out = $browser->post('/logout', ['_token' => $browser->get('/form')->json('data.token')]);
        $this->assertSame('', $out->cookie('pype_remember')?->value);
        $this->assertSame(0, $this->db->table('remember_tokens')->count());
        $this->assertSame(401, $this->returning($cookie)->get('/me')->status());

        $this->login($browser);
        $resets = $this->app->container->get(PasswordResets::class);
        $ada = $this->app->container->get(Auth::class)->findBy('ada@x.test');
        $resets->reset($resets->create($ada), 'new-password');
        $this->assertSame(0, $this->db->table('remember_tokens')->count(), 'a password reset signs out remembered devices');
    }

    public function test_remember_needs_the_table_configured(): void
    {
        $app = $this->app([]);
        $guard = $app->container->get(SessionGuard::class);

        $this->expectException(LogicException::class);
        $guard->login(new Session(Session::generateId()), 1, remember: true);
    }
}

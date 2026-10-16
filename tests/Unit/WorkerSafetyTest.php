<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\Auth;
use Pype\Auth\AuthProvider;
use Pype\Auth\Authenticate;
use Pype\Auth\SessionGuard;
use Pype\Database\Connection;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Session\Session;
use Pype\Session\SessionProvider;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;

/**
 * A long-running server (OpenSwoole) sends every request through the same Application.
 * Nothing about one request (user, session, flash, CSRF token) may show up in another.
 */
final class WorkerSafetyTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(__DIR__, ['auth' => ['user' => AuthUser::class], 'session' => ['driver' => 'array']]);
        $this->app->container->instance(Connection::class, TestDatabase::withAuthTables());
        $this->app->register(AuthProvider::class)->register(SessionProvider::class);

        $auth = $this->app->container->get(Auth::class);
        $auth->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret123']);
        $auth->createUser(['name' => 'Bob', 'email' => 'bob@x.test', 'password' => 'secret123']);

        $this->app->router->group('/', ['web'], function ($r) use ($auth) {
            $r->get('/token', fn (Session $s) => ['token' => $s->token()]);
            $r->post('/login', function (Request $req, Session $s, SessionGuard $guard) use ($auth) {
                $guard->login($s, $auth->attempt($req->input('email'), 'secret123'));
                $s->flash('status', 'Hello ' . $req->input('email'));

                return Response::redirect('/me', 303);
            });
            $r->get('/me', [new Authenticate(), fn (AuthUser $u, Session $s) => ['name' => $u->name, 'status' => $s->get('status'), 'token' => $s->token()]]);
        });
    }

    private function login(TestClient $browser, string $email): string
    {
        $token = $browser->get('/token')->json('data.token');
        $this->assertSame(303, $browser->post('/login', ['email' => $email, '_token' => $token])->status());

        return $browser->get('/token')->json('data.token');   // rotated on login
    }

    public function test_interleaved_users_never_see_each_other(): void
    {
        $ada = new TestClient($this->app);
        $bob = new TestClient($this->app);
        $anon = new TestClient($this->app);

        $adaToken = $this->login($ada, 'ada@x.test');
        $first = $bob->get('/me');   // before Bob logs in: not Ada
        $this->assertSame(401, $first->status());
        $bobToken = $this->login($bob, 'bob@x.test');

        for ($i = 0; $i < 100; $i++) {
            $a = $ada->get('/me');
            $b = $bob->get('/me');
            $this->assertSame('Ada', $a->json('data.name'));
            $this->assertSame('Bob', $b->json('data.name'));
            $this->assertSame($adaToken, $a->json('data.token'));
            $this->assertSame($bobToken, $b->json('data.token'));
            $this->assertSame(401, $anon->get('/me')->status());
        }

        // The flash was consumed by each user's own next request, and nobody else's.
        $this->assertNull($ada->get('/me')->json('data.status'));
    }

    public function test_the_last_request_is_not_kept_alive(): void
    {
        (new TestClient($this->app))->get('/token');

        $this->assertFalse($this->app->container->resolved(Request::class));
    }

    public function test_memory_stays_flat_over_many_requests(): void
    {
        $ada = new TestClient($this->app);
        $this->login($ada, 'ada@x.test');

        for ($i = 0; $i < 200; $i++) {
            $ada->get('/me');   // warm up caches (statements, reflection, routes)
        }
        gc_collect_cycles();
        $before = memory_get_usage();

        for ($i = 0; $i < 1000; $i++) {
            $ada->get('/me');
        }
        gc_collect_cycles();

        $this->assertLessThan(100 * 1024, memory_get_usage() - $before, 'per-request state is piling up somewhere');
    }
}

<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\Auth;
use Pype\Auth\AuthProvider;
use Pype\Auth\Authenticate;
use Pype\Auth\Guard;
use Pype\Auth\SessionGuard;
use Pype\Auth\TokenGuard;
use Pype\Database\Connection;
use Pype\Http\Cookie;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Session\Session;
use Pype\Session\SessionProvider;
use Pype\Session\SessionStore;
use Pype\Testing\TestClient;
use Pype\Validation\Validate;
use Pype\Validation\ValidatedInput;
use Pype\View\View;
use Pype\View\ViewProvider;

final class SessionTest extends TestCase
{
    private Application $app;
    private Auth $auth;
    private TestClient $browser;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__, 2), [
            'auth' => ['user' => AuthUser::class],
            'app' => ['views' => 'tests/fixtures/views'],
        ]);
        $db = Connection::sqlite();
        $db->statement((string) file_get_contents(__DIR__ . '/../fixtures/auth_schema.sql'));
        $this->app->container->instance(Connection::class, $db);
        $this->app->register(AuthProvider::class)->register(SessionProvider::class)->register(ViewProvider::class);

        $this->auth = $this->app->container->get(Auth::class);
        $this->auth->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret123']);

        $r = $this->app->router;

        // The same app, two ways in: a browser form and a JSON API.
        $r->group('/', ['web'], function ($r) {
            $r->get('/login', [new View('form')]);
            $r->post('/login', [
                new Validate(['email' => 'required|email', 'password' => 'required']),
                function (ValidatedInput $in, Session $session, SessionGuard $guard) {
                    $user = $this->auth->attempt($in->get('email'), $in->get('password'));
                    if ($user === null) {
                        $session->flash('status', 'Wrong email or password.');

                        return Response::redirect('/login', 303);
                    }
                    $guard->login($session, $user);

                    return Response::redirect($guard->intended($session, '/home'), 303);
                },
            ]);
            $r->post('/logout', [fn (Session $session, SessionGuard $guard) => $guard->logout($session)]);
            $r->get('/status', fn (Session $session) => ['status' => $session->get('status')]);
            $r->get('/counter', fn (Session $session) => ['n' => $session->put('n', $session->get('n', 0) + 1)->get('n')]);
            $r->get('/home', [new Authenticate(redirectTo: '/login'), fn (AuthUser $user) => ['hello' => $user->name]]);
        });

        $r->get('/api/me', ['auth', fn (AuthUser $user) => ['name' => $user->name]]);
        $r->get('/api/key', [new Authenticate(guards: [new HeaderGuard()]), fn (AuthUser $user) => ['name' => $user->name]]);

        $this->browser = new TestClient($this->app);
    }

    private function csrf(): string
    {
        preg_match('/name="_token" value="([0-9a-f]+)"/', $this->browser->get('/login')->body(), $m);

        return $m[1];
    }

    public function test_session_persists_through_cookie(): void
    {
        $first = $this->browser->get('/counter');
        $cookie = $first->cookie('pype_session');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->httpOnly);
        $this->assertSame('Lax', $cookie->sameSite);
        $this->assertSame(1, $first->json('data.n'));
        $this->assertSame(2, $this->browser->get('/counter')->json('data.n'));
        $this->assertSame(1, $this->browser->withoutCookies()->get('/counter')->json('data.n'));

        // Only a hash of the id is stored.
        $stored = $this->app->container->get(Connection::class)->select('SELECT id FROM sessions');
        $this->assertCount(2, $stored);
        $this->assertNotContains($cookie->value, array_column($stored, 'id'));
    }

    public function test_forged_or_unknown_session_ids_start_fresh(): void
    {
        $this->browser->cookies()->set('pype_session', str_repeat('a', 64));
        $res = $this->browser->get('/counter');

        $this->assertSame(1, $res->json('data.n'));
        $this->assertNotSame(str_repeat('a', 64), $res->cookie('pype_session')->value);
    }

    public function test_browser_login_flow_with_csrf_and_session_fixation_protection(): void
    {
        $this->assertSame('/login', $this->browser->get('/home')->header('Location'));

        $token = $this->csrf();
        $before = $this->browser->cookies()->get('pype_session');

        // No token: rejected.
        $this->assertSame(419, $this->browser->post('/login', ['email' => 'ada@x.test', 'password' => 'secret123'])->status());

        $login = $this->browser->post('/login', ['email' => 'ada@x.test', 'password' => 'secret123', '_token' => $token]);
        $this->assertSame(303, $login->status());
        $this->assertSame('/home', $login->header('Location'));      // the page it was sent away from
        $this->assertSame('', $login->body());
        $this->assertNotSame($before, $this->browser->cookies()->get('pype_session'));
        $this->assertSame('Ada', $this->browser->get('/home')->json('data.hello'));

        // The old session id is gone, and the old CSRF token no longer works.
        $this->assertSame('/login', $this->browser->withoutCookies()->withHeaders(['Cookie' => "pype_session={$before}"])->get('/home')->header('Location'));
        $this->assertSame(419, $this->browser->post('/logout', ['_token' => $token])->status());

        // The cookie means nothing outside the 'web' stack: no session there, so no CSRF-able cookie auth.
        $this->assertSame(401, $this->browser->get('/api/me')->status());

        $this->assertSame(204, $this->browser->post('/logout', [], ['X-CSRF-Token' => $this->csrf()])->status());
        $this->assertSame('/login', $this->browser->get('/home')->header('Location'));
    }

    public function test_failed_validation_redirects_back_with_errors_and_old_input(): void
    {
        $res = $this->browser->post('/login', ['email' => 'nope', 'password' => 'secret123', '_token' => $this->csrf()], ['Referer' => 'http://localhost/login', 'Host' => 'localhost']);

        $this->assertSame(303, $res->status());
        $this->assertSame('/login', $res->header('Location'));

        $form = $this->browser->get('/login')->body();
        $this->assertStringContainsString('value="nope"', $form);
        $this->assertStringContainsString('<p class="error">The email field must be a valid email.</p>', $form);
        $this->assertStringNotContainsString('secret123', $form);

        // Flash data lasts exactly one request.
        $this->assertStringNotContainsString('class="error"', $this->browser->get('/login')->body());

        // JSON clients get the 422, and a foreign Referer is never followed.
        $json = $this->browser->post('/login', ['email' => 'nope', '_token' => $this->csrf()], ['Accept' => 'application/json']);
        $this->assertSame(422, $json->status());
        $evil = $this->browser->post('/login', ['email' => 'nope', '_token' => $this->csrf()], ['Referer' => 'https://evil.test/x', 'Host' => 'localhost']);
        $this->assertSame('/', $evil->header('Location'));
    }

    public function test_flash_message_after_redirect(): void
    {
        $this->browser->post('/login', ['email' => 'ada@x.test', 'password' => 'wrong', '_token' => $this->csrf()]);

        $this->assertSame('Wrong email or password.', $this->browser->get('/status')->json('data.status'));
        $this->assertNull($this->browser->get('/status')->json('data.status'));
    }

    public function test_api_clients_use_tokens_and_get_401_not_redirects(): void
    {
        $token = $this->auth->issueToken(1)->plainText;
        $api = $this->browser->withoutCookies();

        $this->assertSame(401, $api->get('/api/me')->status());
        $this->assertSame('Ada', $api->get('/api/me', ['Authorization' => "Bearer {$token}"])->json('data.name'));
        $this->assertSame(401, $api->get('/home', ['Accept' => 'application/json'])->status());
        $this->assertNull($api->get('/api/me', ['Authorization' => "Bearer {$token}"])->cookie('pype_session'));
    }

    public function test_custom_guard(): void
    {
        $this->assertSame(401, $this->browser->get('/api/key')->status());
        $this->assertSame('Ada', $this->browser->get('/api/key', ['X-User' => '1'])->json('data.name'));
    }

    public function test_cookie_header_format(): void
    {
        $header = (new Cookie('theme', 'dark mode', maxAge: 60, secure: true))->toHeader();

        $this->assertStringStartsWith('theme=dark%20mode; Path=/; Max-Age=60; Expires=', $header);
        $this->assertStringEndsWith('; Secure; HttpOnly; SameSite=Lax', $header);
        $this->assertStringContainsString('Max-Age=0', Cookie::forget('theme')->toHeader());

        $request = Request::create('GET', '/', [], ['Cookie' => 'a=1; b=two%20words']);
        $this->assertSame(['a' => '1', 'b' => 'two words'], $request->cookies());
    }

    public function test_session_store_is_swappable(): void
    {
        $this->assertInstanceOf(SessionStore::class, $this->app->container->get(SessionStore::class));
    }
}

/** A deliberately silly guard, to show any scheme works: trusts an X-User id header. */
final class HeaderGuard implements Guard
{
    public function authenticate(Request $request): ?object
    {
        $id = $request->header('x-user');

        return $id === null ? null : new AuthUser((int) $id, 'Ada', 'ada@x.test');
    }
}

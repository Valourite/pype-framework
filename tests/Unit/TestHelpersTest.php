<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\Auth;
use Pype\Auth\AuthProvider;
use Pype\Auth\Authenticate;
use Pype\Database\Connection;
use Pype\Outlet\RedirectOutlet;
use Pype\Session\SessionProvider;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;
use Pype\Validation\Validate;
use Pype\View\View;
use Pype\View\ViewProvider;

/** TestClient::submit() and actingAs(), and TestResponse's assertions. */
final class TestHelpersTest extends TestCase
{
    private Application $app;
    private TestClient $client;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__, 2), [
            'auth' => ['user' => HelperUser::class],
            'app' => ['views' => 'tests/fixtures/views'],
        ]);
        $this->app->container->instance(Connection::class, TestDatabase::withAuthTables());
        $this->app->register(AuthProvider::class)->register(SessionProvider::class)->register(ViewProvider::class);

        $this->app->router->group('/', ['web'], function ($r) {
            $r->get('/form', [View::with('locals')]);
            $r->get('/plain', fn () => 'no form here');
            $r->post('/form', [Validate::with(['email' => 'required|email']), fn () => ['ok' => true], RedirectOutlet::with('/done')]);
            $r->get('/account', [Authenticate::with(redirectTo: '/login'), fn (HelperUser $user) => ['name' => $user->name]]);
        });
        $this->app->router->post('/api/items', [Validate::with(['name' => 'required']), fn () => ['id' => 1]]);

        $this->client = new TestClient($this->app);
    }

    public function test_submit_sends_the_form_with_its_token_and_comes_back_on_errors(): void
    {
        $this->client->submit('/form', '/form', ['email' => 'ada@example.com'])->assertRedirect('/done');

        $this->client->submit('/form', '/form', ['email' => 'nope'])
            ->assertRedirect('/form')
            ->assertInvalid('email');

        $this->client->get('/form')->assertSee('email=nope|');   // old input on the page again
    }

    public function test_submit_says_when_the_page_has_no_token(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No CSRF token on /plain');
        $this->client->submit('/plain', '/form', []);
    }

    public function test_acting_as_signs_in_without_the_login_form(): void
    {
        $ada = $this->app->container->get(Auth::class)->createUser(['name' => 'Ada', 'email' => 'ada@x.test', 'password' => 'secret123']);

        $this->client->withoutCookies()->get('/account')->assertRedirect('/login');
        $this->client->actingAs($ada)->get('/account')->assertOk()->assertJsonPath('data.name', 'Ada');
        $this->client->actingAs($ada->id)->get('/account')->assertJson(['data' => ['name' => 'Ada']]);
    }

    public function test_the_assertions(): void
    {
        $this->client->get('/form')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertCookie('pype_session')->assertDontSee('err=The');
        $this->client->get('/nothing')->assertNotFound();
        $this->client->post('/api/items', [])->assertUnprocessable()->assertInvalid('name');
        $this->client->post('/api/items', ['name' => 'Desk'])->assertOk()->assertJson(['data' => ['id' => 1]]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected status 200, got 404.');
        $this->client->get('/nothing')->assertOk();
    }
}

final class HelperUser
{
    public function __construct(public readonly int $id, public readonly string $name, public readonly string $email)
    {
    }
}

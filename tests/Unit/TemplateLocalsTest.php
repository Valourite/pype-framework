<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\AuthProvider;
use Pype\Database\Connection;
use Pype\Http\Request;
use Pype\Session\SessionProvider;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;
use Pype\Validation\Validate;
use Pype\View\View;
use Pype\View\ViewProvider;
use Pype\View\Views;

/** $csrf, $old(), $error() and $errors in every template rendered for a request. */
final class TemplateLocalsTest extends TestCase
{
    private Application $app;
    private TestClient $browser;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__, 2), [
            'auth' => ['user' => AuthUser::class],
            'app' => ['views' => 'tests/fixtures/views'],
        ]);
        $this->app->container->instance(Connection::class, TestDatabase::withAuthTables());
        $this->app->register(AuthProvider::class)->register(SessionProvider::class)->register(ViewProvider::class);

        $this->app->router->group('/', ['web'], function ($r) {
            $r->get('/form', [View::with('locals')]);
            $r->post('/form', [Validate::with(['email' => 'required|email', 'lines.*.qty' => 'int']), fn () => ['ok' => true]]);
        });
        $this->app->router->get('/mine', [fn () => ['old' => 'my own'], View::with('locals_mine')]);

        $this->browser = new TestClient($this->app);
    }

    public function test_a_fresh_form_has_a_token_and_no_old_input_or_errors(): void
    {
        $html = $this->browser->get('/form')->body();

        $this->assertMatchesRegularExpression('/name="_token" value="[0-9a-f]{64}"/', $html);
        $this->assertStringContainsString('email=|qty=none|err=-|count=0|partial-err=-', $html);
    }

    public function test_after_a_failed_submission_the_form_shows_old_input_and_errors_in_partials_too(): void
    {
        preg_match('/name="_token" value="([0-9a-f]+)"/', $this->browser->get('/form')->body(), $m);

        $res = $this->browser->post('/form', ['_token' => $m[1], 'email' => 'not-an-email', 'lines' => [1 => ['qty' => '7']]], ['Referer' => 'http://localhost/form', 'Host' => 'localhost']);
        $this->assertSame('/form', $res->header('Location'), $res->status() . ' ' . $res->body());

        $html = $this->browser->get('/form')->body();
        $this->assertStringContainsString('email=not-an-email|qty=7|err=The email field must be a valid email.|count=1|partial-err=The email field must be a valid email.', $html);
    }

    public function test_without_a_session_old_and_error_are_empty_and_csrf_says_why(): void
    {
        $views = $this->app->container->get(Views::class);
        $request = Request::create('GET', '/plain');

        $this->assertSame("empty|none|helper|\n", $views->render('locals_no_csrf_safe', ['request' => $request]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("\$csrf needs a session. Add the 'web' stack to this route.");
        $views->render('locals_no_csrf', ['request' => $request]);
    }

    public function test_your_own_data_wins(): void
    {
        $this->assertStringContainsString('my own', $this->browser->get('/mine')->body());
    }
}

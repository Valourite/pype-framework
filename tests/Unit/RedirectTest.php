<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\AuthProvider;
use Pype\Database\Connection;
use Pype\Exceptions\HttpException;
use Pype\Outlet\RedirectOutlet;
use Pype\Routing\Urls;
use Pype\Session\RedirectOnSuccess;
use Pype\Session\Session;
use Pype\Session\SessionProvider;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;

/** redirect() from a stage, RedirectOutlet at the end of a route, RedirectOnSuccess with a route name. */
final class RedirectTest extends TestCase
{
    private Application $app;
    private TestClient $browser;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__, 2), ['auth' => ['user' => AuthUser::class]]);
        $this->app->container->instance(Connection::class, TestDatabase::withAuthTables());
        $this->app->register(AuthProvider::class)->register(SessionProvider::class);

        $flashed = fn (Session $s) => ['status' => $s->get('status'), 'note' => $s->get('note')];

        $this->app->router->group('/', ['web'], function ($r) use ($flashed) {
            $r->get('/quotes', $flashed)->name('quotes.index');
            $r->get('/quotes/{id}', $flashed)->name('quotes.show');

            $r->get('/quotes/{id}/gone', fn () => redirect('quotes.index', flash: 'That quote no longer exists.'));
            $r->get('/quotes/{id}/accept', [fn (int $id) => ['accepted' => $id], new RedirectOutlet('quotes.show', flash: 'Quote accepted.')]);
            $r->get('/drafts/save', [fn () => ['id' => 42], new RedirectOutlet('quotes.show', fn (array $quote) => ['id' => $quote['id']], flash: ['status' => 'Created.', 'note' => 'n'])]);
            $r->get('/quotes/{id}/fail', [fn () => throw HttpException::notFound('No quote'), new RedirectOutlet('quotes.index', flash: 'Should not flash.')]);
            $r->get('/quotes/{id}/early', [fn () => redirect('/elsewhere'), new RedirectOutlet('quotes.index', flash: 'Should not flash.')]);
            $r->get('/quotes/{id}/back', [new RedirectOnSuccess('quotes.show', 'Back.'), fn () => ['ok' => true]]);
        });
        $this->app->router->get('/api/ping', fn () => redirect('quotes.index', flash: 'No session here.'));

        $this->browser = new TestClient($this->app);
    }

    public function test_a_stage_returns_redirect_with_a_flash_for_the_next_request_only(): void
    {
        $res = $this->browser->get('/quotes/5/gone');

        $this->assertSame(303, $res->status());
        $this->assertSame('/quotes', $res->header('Location'));
        $this->assertSame('That quote no longer exists.', $this->browser->get('/quotes')->json('data.status'));
        $this->assertNull($this->browser->get('/quotes')->json('data.status'));
    }

    public function test_redirect_outlet_fills_the_target_from_the_matched_route_and_flashes(): void
    {
        $res = $this->browser->get('/quotes/7/accept');

        $this->assertSame(303, $res->status());
        $this->assertSame('/quotes/7', $res->header('Location'));
        $this->assertNotNull($res->cookie('pype_session'));   // the session cookie survives the redirect
        $this->assertSame('Quote accepted.', $this->browser->get('/quotes/7')->json('data.status'));
    }

    public function test_redirect_outlet_params_can_come_from_what_the_route_returned(): void
    {
        $this->assertSame('/quotes/42', $this->browser->get('/drafts/save')->header('Location'));

        $show = $this->browser->get('/quotes/42');
        $this->assertSame('Created.', $show->json('data.status'));
        $this->assertSame('n', $show->json('data.note'));
    }

    public function test_json_clients_get_the_data_and_nothing_is_flashed(): void
    {
        $res = $this->browser->get('/quotes/7/accept', ['Accept' => 'application/json']);

        $this->assertSame(200, $res->status());
        $this->assertSame(7, $res->json('data.accepted'));
        $this->assertNull($this->browser->get('/quotes')->json('data.status'));
    }

    public function test_errors_and_earlier_redirects_pass_through_without_the_outlets_flash(): void
    {
        $this->assertSame(404, $this->browser->get('/quotes/3/fail')->status());
        $this->assertNull($this->browser->get('/quotes')->json('data.status'));

        $this->assertSame('/elsewhere', $this->browser->get('/quotes/3/early')->header('Location'));
        $this->assertNull($this->browser->get('/quotes')->json('data.status'));
    }

    public function test_redirect_on_success_takes_a_route_name(): void
    {
        $this->assertSame('/quotes/9', $this->browser->get('/quotes/9/back')->header('Location'));
        $this->assertSame('Back.', $this->browser->get('/quotes/9')->json('data.status'));
    }

    public function test_without_a_session_the_flash_is_dropped(): void
    {
        $res = $this->browser->get('/api/ping');

        $this->assertSame(303, $res->status());
        $this->assertSame('/quotes', $res->header('Location'));
    }

    public function test_target_takes_paths_and_route_names(): void
    {
        $urls = Urls::current();

        $this->assertSame('/login', $urls->target('/login'));
        $this->assertSame('/search?q=a%20b', $urls->target('/search', ['q' => 'a b']));
        $this->assertSame('https://example.com/x', $urls->target('https://example.com/x'));
        $this->assertSame('/quotes/3', $urls->target('quotes.show', routeParams: ['id' => '3', 'other' => 'x']));
        $this->assertSame('/quotes/4?tab=lines', $urls->target('quotes.show', ['id' => 4, 'tab' => 'lines'], ['id' => '3']));
        $this->assertSame('/quotes', $urls->target('quotes.index', routeParams: ['id' => '3']));
    }
}

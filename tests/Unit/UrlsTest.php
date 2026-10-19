<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Http\Request;
use Pype\Routing\Router;
use Pype\Routing\Urls;
use Pype\Routing\ValidateSignature;
use Pype\Testing\TestClient;

final class UrlsTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(__DIR__, ['app' => ['url' => 'https://app.test/', 'key' => 'base64:' . base64_encode(str_repeat('k', 32))]]);
        $r = $this->app->router;

        $r->get('/', fn () => 'home')->name('home');
        $r->get('/expenses', fn () => [])->name('expenses.index');
        $r->get('/expenses/{id}/edit', fn () => [])->name('expenses.edit');
        $r->group('/admin', [], function (Router $r) {
            $r->get('/users/{user}', fn () => [])->name('users.show');
        }, name: 'admin.');
        $r->get('/files/{name}', fn () => [])->name('file');
        $r->get('/reset/{user}', [new ValidateSignature(), fn (int $user) => ['user' => $user]])->name('reset');
    }

    public function test_paths_from_names(): void
    {
        $this->assertSame('/', url('home'));
        $this->assertSame('/expenses/3/edit', url('expenses.edit', ['id' => 3]));
        $this->assertSame('/admin/users/7', url('admin.users.show', ['user' => 7]), 'group name prefix');
        $this->assertSame('/expenses?month=2026-10&page=2', url('expenses.index', ['month' => '2026-10', 'page' => 2, 'q' => null]));
        $this->assertSame('/files/a%20b%2Fc.pdf', url('file', ['name' => 'a b/c.pdf']), 'params are encoded');
        $this->assertSame('https://app.test/expenses/3/edit', url('expenses.edit', ['id' => 3], absolute: true));
        $this->assertSame('/expenses/3/edit', $this->app->container->get(Urls::class)->to('expenses.edit', ['id' => 3]));
    }

    public function test_mistakes_are_explained(): void
    {
        try {
            url('nope');
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('No route is named [nope]', $e->getMessage());
        }

        $this->expectExceptionMessage('Route [expenses.edit] needs the [id] param');
        url('expenses.edit');
    }

    public function test_duplicate_names_are_rejected(): void
    {
        $this->app->router->get('/other', fn () => [])->name('home');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two routes are named [home]');
        url('home');
    }

    public function test_names_set_after_a_lookup_are_found(): void
    {
        url('home');
        $this->app->router->get('/late', fn () => [])->name('late');

        $this->assertSame('/late', url('late'));
    }

    public function test_signed_urls_open_only_when_untouched_and_unexpired(): void
    {
        $client = new TestClient($this->app);
        $link = signed_url('reset', ['user' => 5], expiresIn: 3600);
        $this->assertStringStartsWith('https://app.test/reset/5?expires=', $link);

        $path = substr($link, strlen('https://app.test'));
        $this->assertSame(5, $client->get($path)->json('data.user'));

        $this->assertSame(403, $client->get(str_replace('/reset/5', '/reset/6', $path))->status(), 'other user');
        $this->assertSame(403, $client->get(preg_replace('/expires=\d+/', 'expires=' . (time() + 99999), $path))->status(), 'longer expiry');
        $this->assertSame(403, $client->get('/reset/5')->status(), 'unsigned');
        $this->assertSame(403, $client->get($path . '&extra=1')->status(), 'added param');

        $expired = substr(signed_url('reset', ['user' => 5], expiresIn: -1), strlen('https://app.test'));
        $this->assertSame(403, $client->get($expired)->status());

        $forever = substr(signed_url('reset', ['user' => 5]), strlen('https://app.test'));
        $this->assertSame(200, $client->get($forever)->status());
    }

    public function test_param_order_in_the_query_does_not_matter(): void
    {
        $urls = $this->app->container->get(Urls::class);
        $link = $urls->signed('expenses.index', ['b' => '2', 'a' => '1'], absolute: false);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->assertTrue($urls->hasValidSignature(new Request('GET', '/expenses', array_reverse($query, true))));
    }

    public function test_signing_without_a_key_says_how_to_fix_it(): void
    {
        $app = new Application(__DIR__);
        $app->router->get('/x', fn () => [])->name('x');

        $this->expectExceptionMessage('php bin/pype key:generate');
        signed_url('x');
    }
}

<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Routing\Route;
use Pype\Routing\Router;
use Pype\Testing\TestClient;

/** resource() / apiResource(): the usual routes of one kind of thing, each with its own stages. */
final class ResourceRoutesTest extends TestCase
{
    private static function table(Router $router): array
    {
        return array_map(static fn (Route $r) => $r->method() . ' ' . $r->path() . ' ' . $r->name(), $router->routes());
    }

    public function test_every_action(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $say = static fn (string $what) => static fn () => ['action' => $what];

        $routes = $app->router->resource('/items', [
            'index' => $say('index'),
            'create' => $say('create'),
            'store' => [$say('store')],
            'show' => fn (int $id) => ['action' => 'show', 'id' => $id],
            'edit' => $say('edit'),
            'update' => $say('update'),
            'destroy' => $say('destroy'),
        ]);

        $this->assertSame(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'], array_keys($routes));
        $this->assertSame([
            'GET /items items.index',
            'GET /items/new items.create',
            'POST /items items.store',
            'GET /items/{id} items.show',
            'GET /items/{id}/edit items.edit',
            'PATCH /items/{id} items.update',
            'DELETE /items/{id} items.destroy',
        ], self::table($app->router));

        $client = new TestClient($app);
        $client->get('/items/new')->assertJsonPath('data.action', 'create');   // not show with id "new"
        $client->get('/items/5')->assertJson(['data' => ['action' => 'show', 'id' => 5]]);
        $client->patch('/items/5')->assertJsonPath('data.action', 'update');
        $client->delete('/items/5')->assertJsonPath('data.action', 'destroy');
        $this->assertSame('/items/5/edit', url('items.edit', ['id' => 5]));
    }

    public function test_only_the_actions_given_and_names_inside_groups(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->router->group('/admin', [], function (Router $r) {
            $r->resource('/users', ['index' => fn () => [], 'show' => fn () => []]);
            $r->resource('/quotes/{quote}/lines', ['store' => fn () => []], param: 'line');
            $r->resource('/team', ['destroy' => fn () => []], name: 'members', param: 'member');
        }, name: 'admin.');

        $this->assertSame([
            'GET /admin/users admin.users.index',
            'GET /admin/users/{id} admin.users.show',
            'POST /admin/quotes/{quote}/lines admin.quotes.lines.store',
            'DELETE /admin/team/{member} admin.members.destroy',
        ], self::table($app->router));
    }

    public function test_api_resource_has_no_form_pages(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->router->apiResource('/items', ['index' => fn () => [], 'update' => fn () => []]);
        $this->assertSame(['GET /items items.index', 'PATCH /items/{id} items.update'], self::table($app->router));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Resource [/items] has no [create] route; use: index, store, show, update, destroy.');
        $app->router->apiResource('/items', ['create' => fn () => []]);
    }

    public function test_a_misspelled_action_is_an_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no [delete] route');
        (new Application(dirname(__DIR__, 2)))->router->resource('/items', ['delete' => fn () => []]);
    }
}

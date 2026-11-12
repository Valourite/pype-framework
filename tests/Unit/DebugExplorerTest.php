<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Debug\DebugProvider;
use Pype\Debug\RouteExplorer;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Log\LogProvider;
use Pype\Outlet\HtmlOutlet;
use Pype\Outlet\JsonOutlet;
use Pype\Pipeline\Provides;
use Pype\Pipeline\Requires;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;
use Pype\Routing\Route;
use Pype\Routing\Router;
use Pype\Stages\RequestId;
use Pype\Testing\TestClient;

/** The route screens (/_pype, /_pype/routes): what they read from the routes, and who may open them. */
final class DebugExplorerTest extends TestCase
{
    /** @param array<string, mixed> $debug */
    private function app(array $debug = [], bool $on = true): Application
    {
        $app = new Application(sys_get_temp_dir(), ['app' => ['env' => 'local', 'debug' => $on], 'debug' => $debug + ['requests' => ['enabled' => false]]]);
        $app->register(DebugProvider::class);
        $app->through(RequestId::class);
        $app->stack('members', [ExplorerLoadsUser::class]);

        $app->router->group('/quotes', ['members'], function (Router $r) {
            $r->get('/', [ExplorerList::with(kind: 'quote', perPage: 25), HtmlOutlet::class])->name('index');
            $r->get('/{id}', [ExplorerNeedsUser::class, fn () => 'one', JsonOutlet::class])->name('show');
        }, name: 'quotes.');
        $app->router->post('/broken', [ExplorerNeedsUser::class, new ExplorerList('x')]);

        return $app;
    }

    public function test_the_list_has_every_route_but_the_screens_own(): void
    {
        $routes = (new RouteExplorer($this->app()))->routes();

        $this->assertSame(['GET /quotes', 'GET /quotes/{id}', 'POST /broken'], array_map(fn ($r) => "{$r['method']} {$r['path']}", $routes));
        $this->assertSame(['ExplorerLoadsUser', 'ExplorerList'], $routes[0]['stages'], 'stacks are opened; outlets are apart');
        $this->assertSame('HtmlOutlet', $routes[0]['outlet']);
        $this->assertSame('quotes.show', $routes[1]['name']);
        $this->assertSame([0, 0, 1], array_column($routes, 'problems'), 'what routes:check would say, per route');
        $this->assertNull($routes[2]['outlet']);
    }

    public function test_one_route_is_taken_apart_in_the_order_it_runs(): void
    {
        $explorer = new RouteExplorer($this->app());
        $route = $explorer->route('get', '/quotes/{id}');

        $this->assertSame(['id'], $route['params']);
        $this->assertSame(['RequestId', 'ExplorerLoadsUser', 'ExplorerNeedsUser', 'Closure'], array_column($route['stages'], 'name'));
        $this->assertSame(['global', 'route', 'route', 'route'], array_column($route['stages'], 'origin'));
        $this->assertSame([null, 'members', null, null], array_column($route['stages'], 'stack'));
        $this->assertSame(['JsonOutlet'], array_column($route['outlets'], 'name'));

        [, $loads, $needs, $closure] = $route['stages'];
        $this->assertSame([ExplorerUser::class], $loads['provides']);
        $this->assertSame([['key' => ExplorerUser::class, 'from' => 'ExplorerLoadsUser'], ['key' => Route::class, 'from' => 'the application']], $needs['needs']);
        $this->assertContains(ExplorerUser::class, $needs['before']);
        $this->assertNotContains(ExplorerUser::class, $loads['before']);
        $this->assertSame('closure', $closure['kind']);
        $this->assertStringEndsWith('DebugExplorerTest.php', (string) $closure['file']);
        $this->assertIsInt($closure['line']);
        $this->assertSame([], $route['problems']);

        // X::with(...) shows the arguments as they were written.
        $list = $explorer->route('GET', '/quotes')['stages'][2];
        $this->assertSame(['with', ['kind' => "'quote'", 'perPage' => '25']], [$list['kind'], $list['args']]);

        // A stage before what it needs, and a `new X()` in the routes file.
        $broken = $explorer->route('POST', '/broken');
        $this->assertSame(['ExplorerNeedsUser needs [' . ExplorerUser::class . ']; nothing before it provides it.'], $broken['problems']);
        $this->assertNull($broken['stages'][1]['needs'][0]['from']);
        $this->assertSame('instance', $broken['stages'][2]['kind']);
        $this->assertCount(1, $broken['hints']);
        $this->assertSame([], $broken['outlets']);

        $this->assertNull($explorer->route('GET', '/nowhere'));
    }

    public function test_a_stack_name_that_is_not_a_class_is_shown_not_thrown(): void
    {
        $app = $this->app();
        $app->router->get('/typo', ['memberz', fn () => 'x']);
        $route = (new RouteExplorer($app))->route('GET', '/typo');

        $this->assertTrue($route['stages'][1]['missing']);
        $this->assertSame(['[memberz] is neither a class nor a stack name.'], $route['problems']);
    }

    public function test_the_pages_open_in_debug(): void
    {
        $client = new TestClient($this->app());

        $index = $client->get('/_pype');
        $this->assertSame(200, $index->status());
        $this->assertSame('no-store', $index->header('Cache-Control'));
        $this->assertStringContainsString('href="/_pype/routes"', $index->body());
        $this->assertStringNotContainsString('/_pype/logs', $index->body(), 'only the screens this app has');

        $list = $client->get('/_pype/routes');
        $this->assertSame(200, $list->status());
        $this->assertStringContainsString('/quotes/<b>{id}</b>', $list->body());
        $this->assertStringContainsString('ExplorerLoadsUser → ExplorerList', $list->body());
        $this->assertStringContainsString('1 problem', $list->body());
        $this->assertStringNotContainsString('pype.routes', $list->body());

        $one = $client->get('/_pype/routes/show?m=GET&p=' . rawurlencode('/quotes/{id}'));
        $this->assertSame(200, $one->status());
        $this->assertStringContainsString('needs ExplorerUser ← ExplorerLoadsUser', $one->body());
        $this->assertStringContainsString('puts ExplorerUser', $one->body());
        $this->assertStringContainsString("stack 'members'", $one->body());
        $this->assertStringContainsString('every route', $one->body());

        $broken = $client->get('/_pype/routes/show?m=POST&p=/broken');
        $this->assertStringContainsString('nothing before it provides it', $broken->body());
        $this->assertStringContainsString('shared instance', $broken->body());

        $this->assertSame(404, $client->get('/_pype/routes/show?m=GET&p=/nowhere')->status());
    }

    public function test_the_index_links_the_other_screens_when_the_app_has_them(): void
    {
        $base = sys_get_temp_dir() . '/pype-explorer-' . bin2hex(random_bytes(4));
        $app = new Application($base, ['app' => ['env' => 'local', 'debug' => true], 'log' => ['path' => 'logs', 'viewer' => ['enabled' => true]]]);
        $app->register(LogProvider::class)->register(DebugProvider::class);

        $this->assertStringContainsString('href="/_pype/logs"', (new TestClient($app))->get('/_pype')->body());
    }

    public function test_the_pages_are_not_public_outside_debug(): void
    {
        foreach (['/_pype', '/_pype/routes', '/_pype/routes/show?m=GET&p=/quotes'] as $path) {
            $this->assertSame(404, (new TestClient($this->app(on: false)))->get($path)->status(), "{$path}: not registered outside debug");
            $this->assertSame(404, (new TestClient($this->app(['enabled' => true], on: false)))->get($path)->status(), "{$path}: enabled but unguarded");
            $this->assertSame(404, (new TestClient($this->app(['enabled' => false])))->get($path)->status(), "{$path}: switched off in debug");

            $guarded = $this->app(['enabled' => true, 'stages' => [fn () => Response::error(403, 'Admins only')]], on: false);
            $this->assertSame(403, (new TestClient($guarded))->get($path)->status(), "{$path}: behind the app's stages");
        }

        // Behind the app's own guard outside debug, it is the route screen and no more: the recorder
        // only when asked for by name, the sandbox never (it needs debug to be a sandbox at all).
        $admin = ['enabled' => true, 'stages' => [fn (\Pype\Http\Request $r, \Closure $next) => $next($r)]];
        $guarded = new TestClient($this->app($admin + ['requests' => ['enabled' => null]], on: false));
        $this->assertSame([200, 200, 404, 404], array_map(fn ($p) => $guarded->get($p)->status(), ['/_pype', '/_pype/routes', '/_pype/requests', '/_pype/sandbox']));
        $this->assertStringNotContainsString('Sandbox', $guarded->get('/_pype')->body());
        $this->assertStringNotContainsString('sandbox', $guarded->get('/_pype/routes/show?m=GET&p=/quotes')->body());

        $recording = new TestClient($this->app($admin + ['requests' => ['enabled' => true, 'dir' => 'pype-explorer-requests'], 'sandbox' => ['enabled' => true]], on: false));
        $this->assertSame([200, 404], [$recording->get('/_pype/requests')->status(), $recording->get('/_pype/sandbox')->status()]);

        $moved = new TestClient($this->app(['path' => '/dev/']));
        $this->assertSame(200, $moved->get('/dev/routes')->status());
        $this->assertSame(404, $moved->get('/_pype/routes')->status());
    }

    public function test_an_app_without_the_provider_has_none_of_it(): void
    {
        $app = new Application(sys_get_temp_dir(), ['app' => ['env' => 'local', 'debug' => true]]);

        $this->assertSame(404, (new TestClient($app))->get('/_pype/routes')->status());
        $this->assertFalse($app->container->has(RouteExplorer::class) && $app->container->resolved(RouteExplorer::class));
    }
}

final class ExplorerUser
{
}

#[Provides(ExplorerUser::class)]
final class ExplorerLoadsUser implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->context()->put(new ExplorerUser());

        return $next($request);
    }
}

#[Requires(ExplorerUser::class, Route::class)]
final class ExplorerNeedsUser implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}

final class ExplorerList implements Stage
{
    use WithArguments;

    public function __construct(private readonly string $kind, private readonly int $perPage = 10)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return "<p>{$this->kind} {$this->perPage}</p>";
    }
}

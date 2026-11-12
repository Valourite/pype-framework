<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Outlet\Outlet;
use Pype\Outlet\RedirectOutlet;
use Pype\Pipeline\Stage;
use Pype\Stages\MethodOverride;
use Pype\Stages\TrimStrings;
use Pype\Testing\TestClient;

/**
 * TrimStrings and MethodOverride hand a copy of the request to the next stage, and as global
 * stages they run before the route is matched. Whoever holds the request from before the copy
 * (an outlet rendering, a global stage on the way out, the container) must still see the route's
 * parameters, and outlets render with the request the route got.
 */
final class RequestCopiesTest extends TestCase
{
    private function app(): Application
    {
        $app = new Application(sys_get_temp_dir(), ['app' => ['env' => 'local', 'debug' => true]]);
        $app->through(RequestCopiesSeesParams::class, new TrimStrings(), new MethodOverride());

        $app->router->get('/things/{id}', [RequestCopiesOutlet::class, fn () => 'shown'])->name('things.show');
        $app->router->patch('/things/{id}', [RequestCopiesOutlet::class, fn (Request $r) => $r->input('name')]);
        $app->router->post('/things/{id}/go', [RedirectOutlet::with('things.show'), fn () => null]);
        $app->router->get('/asks/{id}', fn (Application $a) => $a->container->get(Request::class)->param('id') . ':' . $a->container->get(Request::class)->query('q'));

        return $app;
    }

    public function test_an_outlet_renders_with_the_route_params_whatever_the_request_carried(): void
    {
        $client = new TestClient($this->app());

        // No query, no body: nothing was copied, and it always worked.
        $this->assertSame('GET id=7 q= name= | shown', $client->get('/things/7')->body());

        // A query string makes TrimStrings copy the request: the params used to be gone here.
        $this->assertSame('GET id=7 q=x name= | shown', $client->get('/things/7?q=%20x%20')->body());

        // A body, and the method overridden: the outlet sees what the route saw.
        $this->assertSame('PATCH id=7 q= name=Ada | Ada', $client->post('/things/7', ['_method' => 'PATCH', 'name' => '  Ada '])->body());
    }

    public function test_a_global_stage_on_the_way_out_and_the_container_see_them_too(): void
    {
        $client = new TestClient($this->app());

        $response = $client->get('/things/9?q=1');
        $this->assertSame('9', $response->header('X-Seen-Id'), 'a global stage that ran before the copy was made, reading after');

        $this->assertSame('3:x', $client->get('/asks/3?q=%20x')->json('data'), 'the container\'s request is the one the route got');
        $this->assertSame('/things/5', $client->post('/things/5/go', ['a' => ' b '])->header('Location'), 'a redirect back to the same record, after a form with a body');
    }

    public function test_one_request_never_sees_anothers_params(): void
    {
        // One Application serving request after request, as under OpenSwoole.
        $client = new TestClient($this->app());

        $this->assertSame('GET id=1 q=a name= | shown', $client->get('/things/1?q=a')->body());
        $this->assertSame('GET id=2 q= name= | shown', $client->get('/things/2')->body());
        $this->assertSame(404, $client->get('/nowhere?q=a')->status());

        $request = Request::create('GET', '/things/1');
        $copy = $request->withInput(['a' => 'b']);
        $request->setParams(['id' => '1']);
        $this->assertSame(['id' => '1'], $copy->params(), 'a copy shares the params, also ones set after it was made');
        $this->assertSame('1', $copy->withMethod('PATCH')->param('id'));
        $this->assertSame([], Request::create('GET', '/other')->params());
    }
}

/** Renders what it can see of the request it is given. */
final class RequestCopiesOutlet extends Outlet
{
    public function render(Response $response, Request $request): string
    {
        return sprintf('%s id=%s q=%s name=%s | %s', $request->method(), $request->param('id'), $request->query('q'), $request->input('name'), (string) $response->data());
    }

    public function contentType(Response $response): string
    {
        return 'text/plain';
    }
}

/** A global stage that holds the request from before any copy, and reads the route's id on the way out. */
final class RequestCopiesSeesParams implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        return Response::from($next($request))->withHeader('X-Seen-Id', (string) $request->param('id'));
    }
}

<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use DomainException;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Stages\Cors;
use Pype\Stages\JsonBody;
use Pype\Testing\TestClient;
use Pype\View\View;
use Pype\View\ViewProvider;

final class HttpFeaturesTest extends TestCase
{
    public function test_render_using_maps_exceptions(): void
    {
        $app = new Application(__DIR__);
        $app->renderUsing(DomainException::class, fn (DomainException $e) => Response::error(409, $e->getMessage()));
        $app->router->post('/orders', fn () => throw new DomainException('Out of stock'));

        $res = (new TestClient($app))->post('/orders');

        $this->assertSame(409, $res->status());
        $this->assertSame('Out of stock', $res->json('error.message'));
    }

    public function test_cors_preflight_and_simple_request(): void
    {
        $app = new Application(__DIR__);
        $app->through(new Cors(origins: ['https://app.test']));
        $app->router->get('/things', fn () => []);
        $client = new TestClient($app);

        $preflight = $client->request('OPTIONS', '/things', headers: [
            'Origin' => 'https://app.test',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'x-token',
        ]);
        $this->assertSame(204, $preflight->status());
        $this->assertSame('https://app.test', $preflight->header('Access-Control-Allow-Origin'));
        $this->assertSame('x-token', $preflight->header('Access-Control-Allow-Headers'));

        $this->assertSame('https://app.test', $client->get('/things', ['Origin' => 'https://app.test'])->header('Access-Control-Allow-Origin'));
        $this->assertNull($client->get('/things', ['Origin' => 'https://evil.test'])->header('Access-Control-Allow-Origin'));
    }

    public function test_json_body_guard(): void
    {
        $app = new Application(__DIR__);
        $app->through(new JsonBody(maxBytes: 20));
        $app->router->post('/echo', fn (Request $r) => $r->input());
        $client = new TestClient($app);

        $this->assertSame(413, $client->post('/echo', ['text' => str_repeat('x', 50)])->status());

        $malformed = new Request('POST', '/echo', [], [], ['content-type' => 'application/json'], '{"a":');
        $this->assertSame(400, $app->handle($malformed)->status());

        $this->assertSame(['a' => 1], $client->post('/echo', ['a' => 1])->json('data'));
    }

    public function test_view_formatter_renders_templates_and_error_pages(): void
    {
        $app = new Application(dirname(__DIR__), ['app' => ['views' => 'fixtures/views']]);
        $app->register(ViewProvider::class);
        $app->router->get('/hi/{name}', [fn (string $name) => ['name' => $name], new View('hello')]);
        $app->router->get('/gone', [fn () => throw \Pype\Exceptions\HttpException::notFound('the thing'), new View('hello')]);
        $client = new TestClient($app);

        $res = $client->get('/hi/<b>');
        $this->assertSame('text/html; charset=utf-8', $res->response()->formatter()?->contentType());
        $this->assertSame("<main><h1>Hi &lt;b&gt;</h1>\n</main>", trim($res->body()));

        $this->assertStringContainsString('Missing: the thing', $client->get('/gone')->body());
    }

    public function test_broken_template_becomes_reported_500(): void
    {
        $reported = 0;
        $app = new Application(dirname(__DIR__), ['app' => ['views' => 'fixtures/views']]);
        $app->register(ViewProvider::class);
        $app->reportUsing(function () use (&$reported) {
            $reported++;
        });
        $app->router->get('/x', [fn () => [], new View('does-not-exist')]);

        $res = (new TestClient($app))->get('/x');

        $this->assertSame(500, $res->status());
        $this->assertSame(1, $reported);
    }
}

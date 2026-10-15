<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Outlet\JsonOutlet;
use Pype\Outlet\Outlet;
use Pype\Testing\TestClient;
use Pype\View\ViewOutlet;
use Pype\View\ViewProvider;

final class OutletTest extends TestCase
{
    private Application $app;
    private TestClient $client;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__), ['app' => ['views' => 'fixtures/views']]);
        $this->app->register(ViewProvider::class);
        $this->client = new TestClient($this->app);
    }

    public function test_route_beats_group_beats_global_beats_default(): void
    {
        $this->app->through(new TextOutlet('global'));
        $this->app->router->get('/plain', fn () => 'x');
        $this->app->router->group('/g', [new TextOutlet('group')], function ($router) {
            $router->get('/inherit', fn () => 'x');
            $router->get('/own', [fn () => 'x', new TextOutlet('route')]);
        });

        $this->assertSame('global:x', $this->client->get('/plain')->body());
        $this->assertSame('group:x', $this->client->get('/g/inherit')->body());
        $this->assertSame('route:x', $this->client->get('/g/own')->body());

        $bare = new Application(__DIR__);
        $bare->router->get('/', fn () => 'x');
        $this->assertSame('{"data":"x"}', (new TestClient($bare))->get('/')->body());
    }

    public function test_position_in_the_list_does_not_matter(): void
    {
        $this->app->router->get('/first', [new TextOutlet('t'), fn () => 'x']);
        $this->app->router->get('/last', [fn () => 'x', new TextOutlet('t')]);

        $this->assertSame('t:x', $this->client->get('/first')->body());
        $this->assertSame('t:x', $this->client->get('/last')->body());
    }

    public function test_errors_and_404s_use_the_nearest_available_outlet(): void
    {
        $this->app->outlet(new TextOutlet('default'));
        $this->app->router->get('/boom', [fn () => throw \Pype\Exceptions\HttpException::notFound('gone'), new TextOutlet('route')]);

        $this->assertSame('route:error:gone', $this->client->get('/boom')->body());
        $this->assertSame('default:error:No route for GET /missing', $this->client->get('/missing')->body());
    }

    public function test_custom_json_envelope_by_extension(): void
    {
        $this->app->outlet(EnvelopeJson::class);
        $this->app->router->get('/', fn () => ['a' => 1]);

        $this->assertSame('{"ok":true,"result":{"a":1}}', $this->client->get('/')->body());
    }

    public function test_class_based_view_shapes_data_and_exposes_methods(): void
    {
        $this->app->router->get('/u/{name}', [fn (string $name) => $name, ProfileView::class]);

        $res = $this->client->get('/u/ada');

        $this->assertSame('text/html; charset=utf-8', $res->response()->outlet()?->contentType());
        $this->assertSame('<p>ADA! (3)</p>', trim($res->body()));
    }
}

final class TextOutlet extends Outlet
{
    public function __construct(private readonly string $label)
    {
    }

    public function render(Response $response, Request $request): string
    {
        $body = $response->isError() ? 'error:' . $response->errorDetails()['message'] : (string) $response->data();

        return "{$this->label}:{$body}";
    }

    public function contentType(): string
    {
        return 'text/plain';
    }
}

final class EnvelopeJson extends JsonOutlet
{
    protected function payload(Response $response): array
    {
        return $response->isError()
            ? ['ok' => false, 'error' => $response->errorDetails()]
            : ['ok' => true, 'result' => $response->data()];
    }
}

final class ProfileView extends ViewOutlet
{
    protected function template(): string
    {
        return 'profile';
    }

    protected function data(mixed $data, Request $request): array
    {
        return ['user' => $data, 'count' => strlen($data)];
    }

    public function shout(string $value): string
    {
        return strtoupper($value) . '!';
    }
}

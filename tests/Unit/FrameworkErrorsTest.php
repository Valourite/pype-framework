<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\Authenticate;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Stages\JsonBody;
use Pype\Stages\TrimStrings;
use Pype\Testing\TestClient;
use Pype\Validation\Validate;

/** config/app.php 'errors' rewords pype's own errors; apps' own HttpExceptions are untouched. */
final class FrameworkErrorsTest extends TestCase
{
    private function app(array $errors = []): Application
    {
        $app = new Application(__DIR__, ['app' => ['errors' => $errors]]);
        $app->through(new JsonBody());
        $app->router->post('/items', [new Validate(['name' => 'required'], status: 400), fn () => ['ok' => true]]);
        $app->router->post('/strict', [new Validate(['name' => 'required']), fn () => ['ok' => true]]);
        $app->router->get('/private', [new Authenticate([new class () implements \Pype\Auth\Guard {
            public function authenticate(Request $request): ?object
            {
                return null;
            }
        }]), fn () => 'secret']);
        $app->router->get('/boom', fn () => throw new \RuntimeException('database password is hunter2'));
        $app->router->get('/mine', fn () => throw HttpException::notFound('post not found'));

        return $app;
    }

    public function test_defaults_are_unchanged(): void
    {
        $client = new TestClient($this->app());

        $this->assertSame('No route for GET /nope', $client->get('/nope')->json('error.message'));
        $this->assertSame('Server Error', $client->get('/boom')->json('error.message'));
        $this->assertSame('Unauthorized', $client->get('/private')->json('error.message'));
        $this->assertSame(422, $client->post('/strict', [])->status());
    }

    public function test_messages_come_from_config(): void
    {
        $client = new TestClient($this->app([
            'no_route' => 'not found',
            'bad_json' => 'malformed JSON body',
            'server_error' => 'internal server error',
            'unauthenticated' => 'missing bearer token',
            'validation' => 'invalid input',
            'method_not_allowed' => 'wrong method',
        ]));

        $this->assertSame([404, 'not found'], [$client->get('/nope')->status(), $client->get('/nope')->json('error.message')]);
        $this->assertSame('internal server error', $client->get('/boom')->json('error.message'));
        $this->assertSame('missing bearer token', $client->get('/private')->json('error.message'));
        $this->assertSame('wrong method', $client->get('/items')->json('error.message'));

        $app = $this->app(['bad_json' => 'malformed JSON body']);
        $bad = $app->handle(new Request('POST', '/items', headers: ['content-type' => 'application/json'], rawBody: '{bad'));
        $this->assertSame([400, 'malformed JSON body'], [$bad->status(), $bad->errorDetails()['message']]);

        // Validation: the status from the stage, the message from config, the errors kept.
        $invalid = $client->post('/items', []);
        $this->assertSame(400, $invalid->status());
        $this->assertSame('invalid input', $invalid->json('error.message'));
        $this->assertSame(['name'], array_keys($invalid->json('error.errors')));

        // The app's own errors keep their wording, even with the same status.
        $this->assertSame('post not found', $client->get('/mine')->json('error.message'));
    }

    public function test_trim_strings(): void
    {
        $app = new Application(__DIR__);
        $app->through(new TrimStrings());
        $app->router->post('/echo', fn (Request $r) => ['body' => $r->input(), 'query' => $r->query(), 'param' => $r->param('x')]);
        $app->router->post('/echo/{x}', fn (Request $r) => ['body' => $r->input(), 'param' => $r->param('x')]);

        $res = (new TestClient($app))->post('/echo?q=%20hi%20', ['name' => '  Ada ', 'tags' => [' a ', ['  b']], 'password' => ' keep ', 'n' => 5, 'empty' => '   ']);
        $this->assertSame(['name' => 'Ada', 'tags' => ['a', ['b']], 'password' => ' keep ', 'n' => 5, 'empty' => ''], $res->json('data.body'));
        $this->assertSame(['q' => 'hi'], $res->json('data.query'));
        $this->assertSame(' x ', (new TestClient($app))->post('/echo/%20x%20', ['a' => 'b'])->json('data.param'));   // decoded, not trimmed

        $app2 = new Application(__DIR__);
        $app2->through(new TrimStrings(except: [], emptyToNull: true));
        $app2->router->post('/echo', fn (Request $r) => $r->input());
        $this->assertSame(['empty' => null, 'password' => 'x'], (new TestClient($app2))->post('/echo', ['empty' => '  ', 'password' => ' x '])->json('data'));
    }
}

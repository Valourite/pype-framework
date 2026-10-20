<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Testing\TestClient;
use RuntimeException;
use Throwable;

final class ApplicationTest extends TestCase
{
    public function test_unexpected_errors_are_reported_once_and_rendered_as_500(): void
    {
        $reported = [];
        $app = new Application(__DIR__);
        $app->reportUsing(function (Throwable $e, Request $request) use (&$reported) {
            $reported[] = [$e->getMessage(), $request->path()];
        });
        $app->through(fn (Request $r, $next) => $next($r)->withHeader('X-Seen', 'yes'));
        $app->router->get('/boom', [fn ($r, $next) => $next($r), fn () => throw new RuntimeException('kaboom')]);

        $res = (new TestClient($app))->get('/boom');

        $this->assertSame(500, $res->status());
        $this->assertSame('Server Error', $res->json('error.message'));
        $this->assertSame('yes', $res->header('X-Seen'));
        $this->assertSame([['kaboom', '/boom']], $reported);
    }

    public function test_client_errors_are_not_reported(): void
    {
        $reported = 0;
        $app = new Application(__DIR__);
        $app->reportUsing(function () use (&$reported) {
            $reported++;
        });
        $app->router->get('/nope', fn () => throw HttpException::notFound());

        $this->assertSame(404, (new TestClient($app))->get('/nope')->status());
        $this->assertSame(0, $reported);
    }

    public function test_broken_reporter_does_not_break_response(): void
    {
        $app = new Application(__DIR__);
        $app->reportUsing(fn () => throw new RuntimeException('reporter down'));
        $app->router->get('/boom', fn () => throw new RuntimeException('kaboom'));

        $previous = ini_set('error_log', '/dev/null');
        try {
            $this->assertSame(500, (new TestClient($app))->get('/boom')->status());
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }

    public function test_null_result_is_no_content(): void
    {
        $app = new Application(__DIR__);
        $app->router->delete('/thing', fn () => null);

        $res = (new TestClient($app))->delete('/thing');

        $this->assertSame(204, $res->status());
        $this->assertSame('', $res->body());
        $this->assertInstanceOf(Response::class, $res->response());
    }

    public function test_container_uses_defaults_for_optional_dependencies_it_cannot_build(): void
    {
        $made = (new \Pype\Container\Container())->get(OptionalDeps::class);

        $this->assertNull($made->callback);
        $this->assertNull($made->stage);
        $this->assertInstanceOf(\Pype\Container\Container::class, $made->container);

        $this->expectException(\Pype\Container\ContainerException::class);
        (new \Pype\Container\Container())->get(RequiredDep::class);
    }
}

final class OptionalDeps
{
    public function __construct(
        public readonly \Pype\Container\Container $container,
        public readonly ?\Closure $callback = null,
        public readonly ?\Pype\Pipeline\Stage $stage = null,
    ) {
    }
}

final class RequiredDep
{
    public function __construct(public readonly \Pype\Pipeline\Stage $stage)
    {
    }
}

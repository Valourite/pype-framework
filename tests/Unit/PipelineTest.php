<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Pype\Container\Container;
use Pype\Http\Context;
use Pype\Pipeline\Pipeline;
use Pype\Routing\Router;

final class PipelineTest extends TestCase
{
    public function test_stages_run_in_order_around_destination(): void
    {
        $log = [];
        $stage = function (string $name) use (&$log) {
            return function ($p, $next) use ($name, &$log) {
                $log[] = "{$name}:in";
                $out = $next($p);
                $log[] = "{$name}:out";

                return $out;
            };
        };

        $result = (new Pipeline(new Container()))
            ->send(1)
            ->through([$stage('a'), $stage('b')])
            ->then(fn ($p) => $p + 1);

        $this->assertSame(2, $result);
        $this->assertSame(['a:in', 'b:in', 'b:out', 'a:out'], $log);
    }

    public function test_exception_handler_lets_outer_stages_finish(): void
    {
        $result = (new Pipeline(new Container()))
            ->send(null)
            ->through([fn ($p, $next) => 'outer(' . $next($p) . ')'])
            ->onException(fn () => 'caught')
            ->then(fn () => throw new \RuntimeException('boom'));

        $this->assertSame('outer(caught)', $result);
    }

    public function test_stacks_expand_recursively_and_detect_cycles(): void
    {
        $router = new Router();
        $router->stack('a', ['X', 'b']);
        $router->stack('b', ['Y']);

        $this->assertSame(['X', 'Y', 'Z'], $router->compile(['a', 'Z'])['stages']);

        $router->stack('b', ['a']);
        $this->expectException(LogicException::class);
        $router->compile(['a']);
    }

    public function test_context_is_typed(): void
    {
        $ctx = new Context();
        $obj = new \ArrayObject();
        $ctx->put($obj, \Countable::class);

        $this->assertSame($obj, $ctx->get(\Countable::class));
        $this->assertNull($ctx->find(\stdClass::class));

        $this->expectException(\InvalidArgumentException::class);
        $ctx->put(new \stdClass(), \Countable::class);
    }
}

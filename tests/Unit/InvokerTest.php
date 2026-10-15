<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Pype\Container\Container;
use Pype\Container\ContainerException;
use Pype\Exceptions\HttpException;
use Pype\Http\Context;
use Pype\Http\Request;
use Pype\Pipeline\Invoker;

final class InvokerTest extends TestCase
{
    private Invoker $invoker;
    private Request $request;
    private Closure $next;

    protected function setUp(): void
    {
        $container = new Container();
        $container->instance(Clock::class, new Clock('fixed'));

        $this->invoker = new Invoker($container);
        $this->request = Request::create('GET', '/x');
        $this->request->setParams(['id' => '42', 'slug' => 'hello']);
        $this->next = fn () => 'next called';
    }

    public function test_injects_request_next_context_params_and_services(): void
    {
        $this->request->context()->put(new Account('acme'));

        $result = $this->invoker->call(
            fn (Request $r, Closure $next, Context $ctx, Account $account, Clock $clock, int $id, string $slug) => [
                $r === $this->request, $next(), $ctx === $this->request->context(), $account->name, $clock->now, $id, $slug,
            ],
            $this->request,
            $this->next,
        );

        $this->assertSame([true, 'next called', true, 'acme', 'fixed', 42, 'hello'], $result);
    }

    public function test_untyped_params_get_passable_and_next(): void
    {
        $result = $this->invoker->call(fn ($payload, $next) => [$payload, $next()], 'payload', $this->next);

        $this->assertSame(['payload', 'next called'], $result);
    }

    public function test_invokable_objects(): void
    {
        $this->request->context()->put(new Account('acme'));

        $this->assertSame('acme', $this->invoker->call(new ShowAccount(), $this->request, $this->next));
    }

    public function test_missing_context_object_uses_null_or_default_else_throws(): void
    {
        $this->assertNull($this->invoker->call(fn (?Account $a) => $a, $this->request, $this->next));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$account');
        $this->invoker->call(fn (Account $account) => $account, $this->request, $this->next);
    }

    public function test_route_param_that_fails_its_type_is_a_404(): void
    {
        $this->request->setParams(['id' => 'abc']);

        $this->expectException(HttpException::class);
        $this->invoker->call(fn (int $id) => $id, $this->request, $this->next);
    }
}

final class Account
{
    public function __construct(public readonly string $name)
    {
    }
}

final class Clock
{
    public function __construct(public readonly string $now)
    {
    }
}

final class ShowAccount
{
    public function __invoke(Account $account): string
    {
        return $account->name;
    }
}

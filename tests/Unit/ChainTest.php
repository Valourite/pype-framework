<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;
use Pype\Testing\Chain;
use RuntimeException;

/** Chain: run a few stages without HTTP, with a pre-filled context. */
final class ChainTest extends TestCase
{
    public function test_stages_run_in_order_and_leave_their_work_in_the_context(): void
    {
        $result = Chain::of([ChainLoadOrder::class, ChainPriceOrder::with(vat: 15)])
            ->with(new ChainShopper('ada', admin: false))
            ->params(['id' => 7])
            ->run('GET /orders/7');

        $this->assertTrue($result->reachedEnd());
        $this->assertSame(7, $result->context()->get(ChainOrder::class)->id);
        $this->assertSame('ada', $result->context()->get(ChainOrder::class)->owner);
        $this->assertSame(115, $result->context()->get(ChainPrice::class)->total);   // service autowired, argument given
        $this->assertSame(204, $result->status());
    }

    public function test_a_stage_that_stops_the_chain(): void
    {
        $admin = Chain::of([ChainEnsureAdmin::class])->with(new ChainShopper('root', admin: true))->run();
        $this->assertTrue($admin->reachedEnd());

        $guest = Chain::of([ChainEnsureAdmin::class])->with(new ChainShopper('ada', admin: false))->run();
        $this->assertFalse($guest->reachedEnd());
        $this->assertSame(303, $guest->status());
        $this->assertSame('/login', $guest->response()->header('Location'));
    }

    public function test_exceptions_reach_the_test(): void
    {
        $this->expectException(HttpException::class);
        Chain::of([ChainLoadOrder::class])->with(new ChainShopper('ada', admin: false))->params(['id' => 404])->run();
    }

    public function test_a_stage_listed_before_what_it_needs_says_so(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Context has no [' . ChainShopper::class . ']');
        Chain::of([ChainLoadOrder::class])->params(['id' => 1])->run();
    }

    public function test_the_last_stage_returns_data_and_body_renders_it(): void
    {
        $result = Chain::of([ChainLoadOrder::class, fn (ChainOrder $order) => ['id' => $order->id]])
            ->with(new ChainShopper('ada', admin: false))
            ->run(Request::create('GET', '/orders/3'));

        $this->assertSame(['id' => 0], $result->data());   // no params given: the stage read id 0
        $this->assertSame('{"data":{"id":0}}', $result->body());
    }
}

final readonly class ChainShopper
{
    public function __construct(public string $name, public bool $admin)
    {
    }
}

final readonly class ChainOrder
{
    public function __construct(public int $id, public string $owner)
    {
    }
}

final readonly class ChainPrice
{
    public function __construct(public int $total)
    {
    }
}

final class ChainTax
{
    public function apply(int $amount, int $percent): int
    {
        return $amount + intdiv($amount * $percent, 100);
    }
}

final class ChainLoadOrder implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        $id = (int) $request->param('id', '0');
        if ($id === 404) {
            throw HttpException::notFound();
        }

        $shopper = $request->context()->get(ChainShopper::class);
        $request->context()->put(new ChainOrder($id, $shopper->name));

        return $next($request);
    }
}

final class ChainPriceOrder implements Stage
{
    use WithArguments;

    public function __construct(private int $vat, private ChainTax $tax)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $request->context()->put(new ChainPrice($this->tax->apply(100, $this->vat)));

        return $next($request);
    }
}

final class ChainEnsureAdmin implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $request->context()->get(ChainShopper::class)->admin ? $next($request) : redirect('/login');
    }
}

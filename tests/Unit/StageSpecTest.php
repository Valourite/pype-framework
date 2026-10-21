<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Pype\Application;
use Pype\Container\ContainerAware;
use Pype\Container\ContainerException;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Outlet\Outlet;
use Pype\Pipeline\Stage;
use Pype\Pipeline\StageSpec;
use Pype\Pipeline\WithArguments;
use Pype\Routing\Router;
use Pype\Testing\TestClient;
use Pype\Validation\Validate;

/** stage(X::class, ...) and X::with(...): stages with arguments, built when a request reaches them. */
final class StageSpecTest extends TestCase
{
    protected function setUp(): void
    {
        LoadPage::$built = 0;
    }

    public function test_arguments_are_given_and_services_autowired_when_the_route_runs(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->router->get('/quotes', [stage(LoadPage::class, kind: 'quote'), fn (Kind $kind) => ['kind' => $kind->value, 'perPage' => $kind->perPage, 'service' => $kind->service]]);
        $app->router->get('/invoices', [LoadPage::with('invoice', perPage: 50), fn (Kind $kind) => ['kind' => $kind->value, 'perPage' => $kind->perPage]]);
        $app->router->get('/other', fn () => ['ok' => true]);

        $this->assertSame(0, LoadPage::$built, 'registering routes builds nothing');

        $client = new TestClient($app);
        $client->get('/other');
        $this->assertSame(0, LoadPage::$built, 'another route builds nothing');

        $quotes = $client->get('/quotes');
        $this->assertSame(['kind' => 'quote', 'perPage' => 25, 'service' => Pager::class], $quotes->json('data'));
        $this->assertSame(['kind' => 'invoice', 'perPage' => 50], $client->get('/invoices')->json('data'));

        $client->get('/quotes');
        $this->assertSame(3, LoadPage::$built, 'a fresh instance per request');
    }

    public function test_with_is_stage(): void
    {
        $this->assertEquals(stage(LoadPage::class, kind: 'quote'), LoadPage::with(kind: 'quote'));
        $this->assertSame(['kind' => 'quote'], LoadPage::with(kind: 'quote')->args);
    }

    public function test_only_plain_data_is_accepted(): void
    {
        stage(LoadPage::class, kind: 'quote', perPage: 10, tags: ['a', 1, null, Mode::Fast]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('argument [pager] is Pype\Tests\Unit\Pager');
        stage(LoadPage::class, kind: 'quote', pager: new Pager());
    }

    public function test_a_wrong_argument_name_says_so(): void
    {
        $app = new Application(dirname(__DIR__, 2));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('its constructor has no parameter $knd');
        $app->container->makeWith(LoadPage::class, ['knd' => 'quote']);
    }

    public function test_outlets_and_listings_see_through_specs(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->router->get('/report', [stage(LoadPage::class, kind: 'report'), fn (Kind $kind) => $kind->value, stage(UpperOutlet::class, suffix: '!')]);

        $this->assertTrue(Router::isOutlet(stage(UpperOutlet::class)));
        $this->assertSame('LoadPage', Router::describe(LoadPage::with(kind: 'x')));

        $compiled = $app->router->compile($app->router->routes()[0]->stages());
        $this->assertInstanceOf(StageSpec::class, $compiled[0]);
        $this->assertSame(UpperOutlet::class, $compiled[0]->class);

        $this->assertSame('REPORT!', (new TestClient($app))->get('/report')->body());
    }

    public function test_built_in_stages_take_with_too(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->router->post('/items', [Validate::with(['name' => 'required|string']), fn () => ['ok' => true]]);
        $client = new TestClient($app);

        $this->assertSame(422, $client->post('/items', [])->status());
        $this->assertTrue($client->post('/items', ['name' => 'Desk'])->json('data.ok'));
    }

    public function test_container_aware_still_works(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->router->get('/', [stage(Aware::class, label: 'x'), fn (Kind $kind) => $kind->value]);

        $this->assertSame('x:yes', (new TestClient($app))->get('/')->json('data'));
    }
}

final class Pager
{
}

enum Mode
{
    case Fast;
}

final readonly class Kind
{
    public function __construct(public string $value, public int $perPage = 0, public string $service = '')
    {
    }
}

final class LoadPage implements Stage
{
    use WithArguments;

    public static int $built = 0;

    public function __construct(private string $kind, private Pager $pager, private int $perPage = 25, private array $tags = [])
    {
        self::$built++;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $request->context()->put(new Kind($this->kind, $this->perPage, $this->pager::class));

        return $next($request);
    }
}

final class Aware implements Stage, ContainerAware
{
    private bool $hasContainer = false;

    public function __construct(private string $label)
    {
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->hasContainer = true;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $request->context()->put(new Kind($this->label . ':' . ($this->hasContainer ? 'yes' : 'no')));

        return $next($request);
    }
}

final class UpperOutlet extends Outlet
{
    public function __construct(private string $suffix = '')
    {
    }

    public function render(Response $response, Request $request): string
    {
        return strtoupper((string) $response->data()) . $this->suffix;
    }

    public function contentType(Response $response): string
    {
        return 'text/plain';
    }
}

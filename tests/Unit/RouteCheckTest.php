<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\Authenticate;
use Pype\Console\Console;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Outlet\Outlet;
use Pype\Pipeline\DeclaresContext;
use Pype\Pipeline\Provides;
use Pype\Pipeline\Requires;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;
use Pype\Routing\RouteCheck;
use Pype\Stages\Throttle;
use Pype\Validation\Validate;
use Pype\Validation\ValidatedInput;

/** routes:check: #[Requires] / #[Provides] (or DeclaresContext) checked in route order, without a request. */
final class RouteCheckTest extends TestCase
{
    private function app(): Application
    {
        return new Application(dirname(__DIR__, 2), ['auth' => ['user' => CheckUser::class]]);
    }

    public function test_a_stage_listed_before_its_provider_is_reported(): void
    {
        $app = $this->app();
        $app->router->get('/good', [MakesWidget::class, NeedsWidget::class, fn () => 'ok']);
        $app->router->get('/bad', [NeedsWidget::class, MakesWidget::class]);

        $this->assertSame(
            ['GET /bad: NeedsWidget needs [' . Widget::class . ']; nothing before it provides it.'],
            $app->checkRoutes(),
        );
    }

    public function test_the_method_form_works_and_wins_over_attributes(): void
    {
        $this->assertSame([[Widget::class], ['gadget']], RouteCheck::declarations(MethodForm::class));

        $app = $this->app();
        $app->router->get('/a', [MethodForm::class]);
        $this->assertSame(['GET /a: MethodForm needs [' . Widget::class . ']; nothing before it provides it.'], $app->checkRoutes());
    }

    public function test_declarations_are_inherited_and_specs_instances_groups_stacks_and_globals_count(): void
    {
        $this->assertSame([[], [ValidatedInput::class]], RouteCheck::declarations(ValidateWidget::class));

        $app = $this->app();
        $app->stack('widgets', [MakesWidget::with()]);
        $app->router->group('/g', ['widgets'], function ($r) {
            $r->get('/', [new NeedsWidget()]);
        });
        $app->router->post('/form', [ValidateWidget::class, NeedsInput::class]);
        $this->assertSame([], $app->checkRoutes());

        $global = $this->app()->through(new MakesWidget());
        $global->router->get('/x', [NeedsWidget::class]);
        $this->assertSame([], $global->checkRoutes());
    }

    public function test_outlets_are_checked_after_every_other_stage(): void
    {
        $app = $this->app();
        $app->router->get('/page', [WidgetPage::class, MakesWidget::class]);   // the outlet first, as written: still fine
        $app->router->get('/empty', [WidgetPage::class]);

        $this->assertSame(['GET /empty: WidgetPage needs [' . Widget::class . ']; nothing before it provides it.'], $app->checkRoutes());
    }

    public function test_the_signed_in_user_is_the_configured_class(): void
    {
        $app = $this->app();
        $app->router->get('/me', [Authenticate::class, NeedsUser::class]);
        $app->router->get('/early', [NeedsUser::class, Authenticate::class]);

        $this->assertSame(['GET /early: NeedsUser needs [' . CheckUser::class . ']; nothing before it provides it.'], $app->checkRoutes());
    }

    public function test_unknown_classes_are_reported_and_closures_skipped(): void
    {
        $app = $this->app();
        $app->router->get('/typo', ['App\\Stages\\LoadQoute', fn (Widget $w) => $w]);

        $this->assertSame(['GET /typo: [App\\Stages\\LoadQoute] is neither a class nor a stack name.'], $app->checkRoutes());
    }

    public function test_hints_list_new_stages_once(): void
    {
        $app = $this->app();
        $throttle = new Throttle(5, 60);
        $app->router->group('/t', [$throttle], function ($r) {
            $r->get('/a', [MakesWidget::class]);
            $r->get('/b', [MakesWidget::with(), new WidgetPage()]);
        });

        $this->assertSame([
            'GET /t/a: new Throttle(...) is built while the routes load and shared by every request a worker serves; Throttle::with(...) builds it per request.',
            'GET /t/b: new WidgetPage(...) is built while the routes load and shared by every request a worker serves; WidgetPage::class builds it per request.',
        ], (new RouteCheck($app))->hints());
    }

    public function test_the_command_fails_on_problems(): void
    {
        $app = $this->app();
        $app->router->get('/bad', [NeedsWidget::class]);

        ob_start();
        $code = (new Console($app))->run(['pype', 'routes:check']);
        $out = (string) ob_get_clean();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('1 routes checked, 1 problem.', $out);
    }
}

final class CheckUser
{
}

final class Widget
{
}

#[Provides(Widget::class)]
final class MakesWidget implements Stage
{
    use WithArguments;

    public function handle(Request $request, Closure $next): mixed
    {
        $request->context()->put(new Widget());

        return $next($request);
    }
}

#[Requires(Widget::class)]
final class NeedsWidget implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}

#[Requires(CheckUser::class)]
final class NeedsUser implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}

#[Requires(ValidatedInput::class)]
final class NeedsInput implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}

final class ValidateWidget extends Validate
{
}

#[Requires('ignored')]
final class MethodForm implements Stage, DeclaresContext
{
    public static function requires(): array
    {
        return [Widget::class];
    }

    public static function provides(): array
    {
        return ['gadget'];
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}

#[Requires(Widget::class)]
final class WidgetPage extends Outlet
{
    public function render(Response $response, Request $request): string
    {
        return 'widget';
    }

    public function contentType(Response $response): string
    {
        return 'text/plain';
    }
}

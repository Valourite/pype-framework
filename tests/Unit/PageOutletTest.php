<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Console\Console;
use Pype\Http\Request;
use Pype\Pipeline\Stage;
use Pype\Testing\TestClient;
use Pype\View\ViewOutlet;
use Pype\View\ViewProvider;

/** A route of stages ending in a page outlet that reads the context; make:view writes one. */
final class PageOutletTest extends TestCase
{
    public function test_stages_load_and_the_page_outlet_shows(): void
    {
        $app = new Application(dirname(__DIR__, 2), ['app' => ['views' => 'tests/fixtures/views']]);
        $app->register(ViewProvider::class);
        $app->router->get('/things/{id}', [LoadThing::class, ThingView::class]);

        $res = (new TestClient($app))->get('/things/7');

        $this->assertSame(200, $res->status());
        $this->assertSame("<h1>Thing 7</h1><p>THING 7! hello</p>\n", $res->body());
    }

    public function test_make_view_writes_a_page_outlet_and_its_template(): void
    {
        $base = sys_get_temp_dir() . '/pype-view-' . bin2hex(random_bytes(4));
        mkdir($base);

        ob_start();
        $code = (new Console(new Application($base)))->run(['pype', 'make:view', 'QuoteListView']);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Created app/Views/QuoteListView.php', $out);
        $this->assertStringContainsString('Created resources/views/quote-list.php', $out);
        $this->assertStringContainsString("return 'quote-list';", (string) file_get_contents("{$base}/app/Views/QuoteListView.php"));
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("{$base}/app/Views/QuoteListView.php"), $lint, $lintCode);
        $this->assertSame(0, $lintCode, implode("\n", $lint));

        exec('rm -rf ' . escapeshellarg($base));
    }
}

final readonly class Thing
{
    public function __construct(public string $name)
    {
    }
}

final class Greeter
{
    public function greet(): string
    {
        return 'hello';
    }
}

final class LoadThing implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->context()->put(new Thing('Thing ' . $request->param('id')));

        return $next($request);
    }
}

final class ThingView extends ViewOutlet
{
    public function __construct(private Greeter $greeter)
    {
    }

    protected function template(): string
    {
        return 'thing';
    }

    protected function data(mixed $data, Request $request): array
    {
        return ['thing' => $request->context()->get(Thing::class), 'greeting' => $this->greeter->greet()];
    }

    public function shout(Thing $thing): string
    {
        return strtoupper($thing->name) . '!';
    }
}

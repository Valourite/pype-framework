<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Auth\AuthProvider;
use Pype\Database\Connection;
use Pype\Http\Request;
use Pype\Outlet\RedirectOutlet;
use Pype\Session\SessionProvider;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;
use Pype\Validation\Validate;
use Pype\View\View;
use Pype\View\Views;

/** One View outlet, the engine picked from the file extension: plain PHP, Latte, BladeOne. */
final class TemplateEnginesTest extends TestCase
{
    private string $cache;
    private Views $views;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/pype-engines-' . bin2hex(random_bytes(4));
        $this->views = new Views(dirname(__DIR__) . '/fixtures/views', $this->cache);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->cache));
    }

    public function test_latte_with_a_layout_escaping_and_the_form_helpers(): void
    {
        $html = $this->views->render('engines/latte_page', ['title' => '<b>Quotes</b>', 'request' => Request::create('GET', '/')]);

        $this->assertSame("<main><h1>&lt;b&gt;Quotes&lt;/b&gt;</h1><p>old=none</p></main>\n", $html);
        $this->assertDirectoryExists($this->cache . '/latte');
    }

    public function test_blade_with_a_layout_escaping_and_the_form_helpers(): void
    {
        $html = $this->views->render('engines/blade_page', ['title' => '<b>Quotes</b>', 'request' => Request::create('GET', '/')]);

        $this->assertStringContainsString('<main><h1>&lt;b&gt;Quotes&lt;/b&gt;</h1><p>old=none</p>', $html);
        $this->assertDirectoryExists($this->cache . '/blade');
    }

    public function test_the_extension_decides_and_a_name_without_one_takes_blade_then_latte_then_php(): void
    {
        $this->assertSame("blade\n", $this->views->render('engines/pick'));
        $this->assertSame("latte\n", $this->views->render('engines/pick.latte'));
        $this->assertSame("php\n", $this->views->render('engines/pick.php'));
        $this->assertTrue($this->views->exists('engines/pick.latte'));
        $this->assertFalse($this->views->exists('engines/nothing'));

        $this->expectException(InvalidArgumentException::class);
        $this->views->render('../secrets');
    }

    public function test_a_latte_form_through_the_view_outlet(): void
    {
        $app = new Application(dirname(__DIR__, 2), ['app' => ['views' => 'tests/fixtures/views'], 'auth' => ['user' => \stdClass::class]]);
        $app->container->instance(Views::class, $this->views);   // compile into this test's temp cache, not the repo
        $app->container->instance(Connection::class, TestDatabase::withAuthTables());
        $app->register(AuthProvider::class)->register(SessionProvider::class);
        $app->router->group('/', ['web'], function ($r) {
            $r->get('/signup', [View::with('engines/form')]);
            $r->post('/signup', [Validate::with(['email' => 'required|email']), RedirectOutlet::with('/signup')]);
        });
        $client = new TestClient($app);

        $client->get('/signup')->assertOk()->assertSee('name="_token" value="');
        $client->submit('/signup', '/signup', ['email' => 'nope'])->assertRedirect('/signup')->assertInvalid('email');
        $client->get('/signup')->assertSee('value="nope"')->assertSee('<p>The email field must be a valid email.</p>');
    }
}

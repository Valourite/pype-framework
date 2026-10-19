<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Exceptions\HttpException;
use Pype\Routing\Router;

final class RouterTest extends TestCase
{
    private function hit(Router $r, string $method, string $path): array
    {
        [$route, $params] = $r->resolve($method, $path);

        return [$route->name(), $params];
    }

    public function test_static_and_dynamic_routes(): void
    {
        $r = new Router();
        $r->get('/', 'x')->name('home');
        $r->get('/users/{id}', 'x')->name('user');
        $r->get('/users/new', 'x')->name('new');                 // static beats {id}, whatever the order
        $r->get('/users/{id}/posts/{post}', 'x')->name('post');
        $r->get('/files/{name}', 'x')->name('file');

        $this->assertSame(['home', []], $this->hit($r, 'GET', '/'));
        $this->assertSame(['user', ['id' => '42']], $this->hit($r, 'GET', '/users/42'));
        $this->assertSame(['new', []], $this->hit($r, 'GET', '/users/new'));
        $this->assertSame(['post', ['id' => '7', 'post' => 'hello-world']], $this->hit($r, 'GET', '/users/7/posts/hello-world'));
        $this->assertSame(['file', ['name' => 'report.2026.pdf']], $this->hit($r, 'GET', '/files/report.2026.pdf'));
        $this->assertSame(['user', ['id' => '42']], $this->hit($r, 'GET', '//users//42/'), 'paths are normalised');
    }

    public function test_methods_head_and_errors(): void
    {
        $r = new Router();
        $r->get('/things/{id}', 'x')->name('show');
        $r->patch('/things/{thing}', 'x')->name('update');      // same shape, other param name
        $r->post('/things', 'x')->name('create');

        $this->assertSame(['show', ['id' => '3']], $this->hit($r, 'GET', '/things/3'));
        $this->assertSame(['show', ['id' => '3']], $this->hit($r, 'HEAD', '/things/3'));
        $this->assertSame(['update', ['thing' => '3']], $this->hit($r, 'PATCH', '/things/3'));
        $this->assertSame(['create', []], $this->hit($r, 'POST', '/things'));

        try {
            $r->resolve('DELETE', '/things/3');
            $this->fail('expected 405');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status());
            $this->assertSame(['GET', 'PATCH'], $e->details()['allowed'] ?? null);
        }

        $this->expectExceptionObject(HttpException::notFound('No route for GET /nope'));
        $r->resolve('GET', '/nope');
    }

    public function test_overlapping_patterns_fall_back_to_registration_order(): void
    {
        $r = new Router();
        $r->post('/a/{x}', 'x')->name('a-post');
        $r->get('/{y}/b', 'x')->name('b-get');

        $r->get('/a/{z}', 'x')->name('a-get');   // same shape as the first, registered last

        // /a/b matches both shapes. Registration order decides: GET finds b-get before a-get.
        // Same answer on the first lookup (plain scan) and later ones (compiled tables).
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(['b-get', ['y' => 'a']], $this->hit($r, 'GET', '/a/b'));
            $this->assertSame(['a-post', ['x' => 'b']], $this->hit($r, 'POST', '/a/b'));
            $this->assertSame(['a-get', ['z' => 'c']], $this->hit($r, 'GET', '/a/c'));
        }
    }

    public function test_many_routes_across_regex_chunks(): void
    {
        $r = new Router();
        for ($i = 0; $i < 230; $i++) {
            $r->get("/s{$i}/items/{id}", 'x')->name("r{$i}");
            $r->get("/s{$i}/list", 'x')->name("l{$i}");
        }

        foreach ([0, 49, 50, 51, 120, 229] as $i) {
            $this->assertSame(["r{$i}", ['id' => "v{$i}"]], $this->hit($r, 'GET', "/s{$i}/items/v{$i}"));
            $this->assertSame(["l{$i}", []], $this->hit($r, 'GET', "/s{$i}/list"));
        }
    }

    public function test_routes_added_after_a_resolve_are_seen(): void
    {
        $r = new Router();
        $r->get('/one', 'x')->name('one');
        $r->resolve('GET', '/one');
        $r->get('/two/{n}', 'x')->name('two');

        $this->assertSame(['two', ['n' => '2']], $this->hit($r, 'GET', '/two/2'));
    }

    public function test_compiled_tables_from_the_optimize_cache(): void
    {
        $define = function (Router $r, bool $extra = false): void {
            $r->get('/', 'x')->name('home');
            $r->get('/users/{id}', 'x')->name('user');
            if ($extra) {
                $r->get('/users/{id}/edit', 'x')->name('edit');
            }
        };

        $source = new Router();
        $define($source);
        $file = tempnam(sys_get_temp_dir(), 'routes') . '.php';
        file_put_contents($file, '<?php return ' . var_export($source->compiled(), true) . ';');

        $cached = new Router();
        $define($cached);
        $cached->cacheFrom($file);
        $this->assertSame(['user', ['id' => '9']], $this->hit($cached, 'GET', '/users/9'));
        $this->assertSame(['home', []], $this->hit($cached, 'GET', '/'));

        // Routes changed since optimize: the stale file is ignored, never trusted.
        $changed = new Router();
        $define($changed, extra: true);
        $changed->cacheFrom($file);
        $this->assertSame(['edit', ['id' => '9']], $this->hit($changed, 'GET', '/users/9/edit'));
        $this->assertSame(['user', ['id' => '9']], $this->hit($changed, 'GET', '/users/9'));

        unlink($file);
    }
}

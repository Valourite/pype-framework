<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Database\Connection;
use Pype\Database\Schema\Schema;
use Pype\Database\Schema\Table;
use Pype\Debug\DebugProvider;
use Pype\Debug\RequestLog;
use Pype\Debug\Trace;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Outlet\JsonOutlet;
use Pype\Pipeline\Pipeline;
use Pype\Pipeline\Provides;
use Pype\Pipeline\Stage;
use Pype\Stages\MethodOverride;
use Pype\Stages\RequestId;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;
use RuntimeException;

/** The request recorder (/_pype/requests): the two hooks it stands on, what it keeps, and that it is off unless asked for. */
final class DebugRecorderTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pype-recorder-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/storage/debug/requests/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (['/storage/debug/requests', '/storage/debug', '/storage', ''] as $dir) {
            @rmdir($this->base . $dir);
        }
    }

    /** @param array<string, mixed> $debug */
    private function app(array $debug = [], bool $on = true, ?Connection $db = null): Application
    {
        $app = new Application($this->base, ['app' => ['env' => 'local', 'debug' => $on], 'debug' => $debug]);
        $db ??= TestDatabase::fresh();
        (new Schema($db))->create('notes', function (Table $t) {
            $t->id();
            $t->string('body', 100)->nullable();
            $t->string('password', 100)->nullable();
        });
        $app->container->instance(Connection::class, $db);
        $app->through(RequestId::class, MethodOverride::class);
        $app->register(DebugProvider::class);

        $app->router->get('/notes/{id}', [RecorderLoadsNote::class, fn (RecorderNote $note) => ['id' => $note->id], JsonOutlet::class])->name('notes.show');
        $app->router->post('/notes', [fn (Connection $db, Request $r) => $db->execute('INSERT INTO notes (body, password) VALUES (?, ?)', [$r->input('body'), 'hunter2']) ? Response::redirect('/notes/1', 303) : null]);
        $app->router->patch('/notes/{id}', [fn () => 'patched']);
        $app->router->get('/guarded', [fn () => throw HttpException::notFound(), fn () => 'never']);
        $app->router->get('/boom', [fn () => throw new RuntimeException('it broke'), RecorderLoadsNote::class]);

        return $app;
    }

    /** @return list<array<string, mixed>> the kept requests in full, oldest first */
    private function kept(Application $app): array
    {
        $log = $app->container->get(RequestLog::class);

        return array_reverse(array_map(fn (array $row) => $log->find($row['id']), $log->all()));
    }

    public function test_the_pipeline_tells_its_observer_about_every_stage_in_nesting_order(): void
    {
        $seen = [];
        $observer = function (string $event, mixed $stage, mixed $passable, mixed $value) use (&$seen): void {
            $seen[] = $event . ':' . (is_string($stage) ? $stage : 'fn') . ($event === 'leave' ? '=' . json_encode($value) : '');
        };

        $result = (new Pipeline(new \Pype\Container\Container()))
            ->observe($observer)
            ->mapResult(fn ($r) => strtoupper((string) $r))
            ->send('x')
            ->through([fn ($p, $next) => $next($p) . '!', fn ($p, $next) => $next($p . 'y')])
            ->then(fn ($p) => $p);

        $this->assertSame('XY!', $result);
        $this->assertSame(['enter:fn', 'enter:fn', 'leave:fn="XY"', 'leave:fn="XY!"'], $seen, 'left innermost first, with the mapped result');

        // A stage that throws: told, then left; with onException the result is what the handler made of it.
        $seen = [];
        $caught = (new Pipeline(new \Pype\Container\Container()))
            ->observe($observer)
            ->onException(fn (\Throwable $e) => 'caught ' . $e->getMessage())
            ->send('x')
            ->through([fn ($p, $next) => $next($p), fn () => throw new RuntimeException('no')])
            ->thenReturn();
        $this->assertSame('caught no', $caught);
        $this->assertSame(['enter:fn', 'enter:fn', 'threw:fn', 'leave:fn="caught no"', 'leave:fn="caught no"'], $seen);

        // Without a handler the exception goes on up, and every stage is still left.
        $seen = [];
        try {
            (new Pipeline(new \Pype\Container\Container()))->observe($observer)->send('x')->through([fn ($p, $next) => $next($p), fn () => throw new RuntimeException('no')])->thenReturn();
            $this->fail('the exception was swallowed');
        } catch (RuntimeException) {
            $this->assertSame(['enter:fn', 'enter:fn', 'threw:fn', 'leave:fn=null', 'threw:fn', 'leave:fn=null'], $seen);
        }

        // No observer: nothing changes.
        $this->assertSame('xy!', (new Pipeline(new \Pype\Container\Container()))->send('x')->through([fn ($p, $next) => $next($p) . '!', fn ($p, $next) => $next($p . 'y')])->thenReturn());
    }

    public function test_a_connection_tells_its_listener_about_every_query(): void
    {
        $db = TestDatabase::fresh();
        (new Schema($db))->create('things', function (Table $t) {
            $t->id();
            $t->string('name', 50);
        });
        $heard = [];
        $db->listen(function (string $sql, array $bindings, float $ms) use (&$heard): void {
            $heard[] = [$sql, $bindings, $ms >= 0];
        });

        $db->execute('INSERT INTO things (name) VALUES (?)', ['a']);
        $db->table('things')->where('name', 'a')->first();
        $db->statement('DELETE FROM things');
        try {
            $db->execute('INSERT INTO things (id, name) VALUES (1, ?), (1, ?)', ['a', 'b']);
        } catch (\Throwable) {
        }

        $this->assertCount(4, $heard);
        $this->assertSame(['INSERT INTO things (name) VALUES (?)', ['a'], true], $heard[0]);
        $this->assertStringContainsString('things', $heard[1][0]);
        $this->assertSame(['DELETE FROM things', [], true], $heard[2]);
        $this->assertSame(['a', 'b'], $heard[3][1], 'a query that failed is still heard');

        $db->listen(null);
        $db->select('SELECT 1');
        $this->assertCount(4, $heard);
    }

    public function test_a_request_is_kept_stage_by_stage(): void
    {
        $app = $this->app();
        $client = new TestClient($app);
        $app->container->get(Connection::class)->execute("INSERT INTO notes (body) VALUES ('hello')");

        $this->assertSame(200, $client->get('/notes/1?page=2')->status());
        [$record] = $this->kept($app);

        $this->assertSame(['GET', '/notes/1', 200, ['page' => '2']], [$record['method'], $record['path'], $record['status'], $record['query']]);
        $this->assertSame(['method' => 'GET', 'path' => '/notes/{id}', 'name' => 'notes.show', 'params' => ['id' => '1']], $record['route']);
        $this->assertSame(['RequestId', 'MethodOverride', 'JsonOutlet', 'RecorderLoadsNote', 'Closure'], array_column($record['stages'], 'name'), 'the recorder\'s own stage is not one of the app\'s');
        $this->assertSame(['global', 'global', 'outlet', 'route', 'route'], array_column($record['stages'], 'kind'));
        $this->assertSame([0, 1, 2, 3, 4], array_column($record['stages'], 'depth'));
        $this->assertSame([], $record['unreached']);
        $this->assertNotEmpty($record['requestId']);

        [$requestId, $override, , $loads, $closure] = $record['stages'];
        $this->assertSame([\Pype\Stages\RequestIdValue::class], $requestId['put']);
        $this->assertSame([], $override['put'], 'the route is put in by the application, not by the stage before it');
        $this->assertSame([RecorderNote::class], $loads['put']);
        $this->assertTrue($loads['calledNext']);
        $this->assertFalse($closure['calledNext']);
        $this->assertSame(200, $closure['status']);
        $this->assertGreaterThanOrEqual($loads['own'], $loads['ms']);
        $this->assertGreaterThanOrEqual($closure['ms'], $loads['ms'], 'a stage\'s time holds that of the stages it called');

        // The query belongs to the stage that ran it.
        $this->assertSame(1, $record['queries']);
        $this->assertCount(1, $loads['queries']);
        $this->assertStringContainsString('notes', $loads['queries'][0]['sql']);
        $this->assertSame(['1'], $loads['queries'][0]['bindings']);
        $this->assertSame([], $closure['queries']);
        $this->assertContains(RecorderNote::class, $record['context']);
        $this->assertNotContains(Trace::class, $record['context']);
    }

    public function test_secrets_are_not_kept(): void
    {
        $app = $this->app();
        (new TestClient($app))->post('/notes?api_token=abc', ['body' => 'hi', 'password' => 'hunter2', 'card' => ['number' => '4111']], ['Authorization' => 'Bearer s3cret', 'Cookie' => 'sid=abc']);
        [$record] = $this->kept($app);
        $stored = (string) file_get_contents(glob($this->base . '/storage/debug/requests/*.json')[0]);

        $this->assertSame(303, $record['status']);
        $this->assertSame('/notes/1', $record['location']);
        $this->assertSame('hi', $record['input']['body']);
        foreach (['hunter2', 's3cret', 'sid=abc', '4111', '"abc"'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored, "{$secret} was written to disk");
        }
        $this->assertSame(['•••• hidden'], $record['stages'][2]['queries'][0]['bindings'], 'a query that names a password keeps its SQL, not its values');
    }

    public function test_a_stage_that_answers_early_or_throws_and_the_stages_that_never_ran(): void
    {
        $app = $this->app();
        $client = new TestClient($app);
        $client->get('/guarded');
        $client->get('/boom');
        $client->get('/nowhere');
        $client->post('/notes/7', ['_method' => 'PATCH']);
        [$guarded, $boom, $nowhere, $patched] = $this->kept($app);

        $this->assertSame([404, ['Closure']], [$guarded['status'], $guarded['unreached']]);
        $this->assertSame(['http' => true, 'class' => HttpException::class], array_intersect_key(end($guarded['stages'])['exception'], ['http' => 1, 'class' => 1]));

        $this->assertSame([500, ['RecorderLoadsNote']], [$boom['status'], $boom['unreached']]);
        $this->assertSame([false, 'it broke'], [end($boom['stages'])['exception']['http'], end($boom['stages'])['exception']['message']]);

        $this->assertSame([404, null, ['RequestId', 'MethodOverride']], [$nowhere['status'], $nowhere['route'], array_column($nowhere['stages'], 'name')], 'no route: the global stages still ran');

        // MethodOverride hands a copy of the request on: flagged, and the record is of the request as the route got it.
        $this->assertSame(['PATCH', ['id' => '7']], [$patched['method'], $patched['route']['params']]);
        $this->assertSame([false, true], array_column(array_slice($patched['stages'], 0, 2), 'replacedRequest'));

        $list = $app->container->get(RequestLog::class)->all();
        $this->assertSame([false, false, true, false], array_column($list, 'problem'), 'newest first; only the unexpected exception is marked');
        $this->assertArrayNotHasKey('stages', $list[0]);
    }

    public function test_the_screens(): void
    {
        $app = $this->app();
        $client = new TestClient($app);
        $app->container->get(Connection::class)->execute("INSERT INTO notes (body) VALUES ('hello')");
        $client->get('/notes/1');
        $client->get('/boom');

        $list = $client->get('/_pype/requests');
        $this->assertSame(200, $list->status());
        $this->assertStringContainsString('/notes/1', $list->body());
        $this->assertStringContainsString('a stage threw', $list->body());
        $this->assertStringContainsString('href="/_pype/requests"', $client->get('/_pype')->body());

        $log = $app->container->get(RequestLog::class);
        $this->assertCount(2, $log->all(), 'the screens\' own requests are not recorded');

        $one = $client->get('/_pype/requests/show?id=' . $log->all()[1]['id']);
        $this->assertSame(200, $one->status());
        $this->assertStringContainsString('RecorderLoadsNote', $one->body());
        $this->assertStringContainsString('put RecorderNote', $one->body());
        $this->assertStringContainsString('SELECT', $one->body());
        $this->assertStringContainsString('/_pype/routes/show?m=GET', $one->body(), 'a link to the route\'s own page');

        $this->assertStringContainsString('Never ran: <strong>RecorderLoadsNote', $client->get('/_pype/requests/show?id=' . $log->all()[0]['id'])->body());
        $this->assertSame(404, $client->get('/_pype/requests/show?id=../../etc/passwd')->status());
        $this->assertSame(404, $client->get('/_pype/requests/show?id=20260101-000000-000000-abcdef')->status());
    }

    public function test_only_the_newest_are_kept(): void
    {
        $app = $this->app(['requests' => ['keep' => 3]]);
        $client = new TestClient($app);
        foreach ([1, 2, 3, 4, 5] as $n) {
            $client->get("/guarded?n={$n}");
        }

        $this->assertSame(['5', '4', '3'], array_map(fn ($r) => $r['query']['n'], $app->container->get(RequestLog::class)->all()));
    }

    public function test_two_requests_never_share_a_trace(): void
    {
        // One Application serving request after request, as under OpenSwoole.
        $app = $this->app();
        $client = new TestClient($app);
        $app->container->get(Connection::class)->execute("INSERT INTO notes (body) VALUES ('hello')");
        $client->get('/notes/1');
        $client->get('/guarded');
        $client->get('/notes/1');
        [$first, $second, $third] = $this->kept($app);

        $this->assertSame([1, 0, 1], [$first['queries'], $second['queries'], $third['queries']]);
        $this->assertSame(count($first['stages']), count($third['stages']));

        // Nothing is left listening once a request is through: a query after it belongs to no request.
        $app->container->get(Connection::class)->select('SELECT 1');
        $this->assertSame(1, $this->kept($app)[2]['queries']);
    }

    public function test_it_is_off_unless_asked_for(): void
    {
        // Not in debug: no screens, no observer, nothing written.
        $prod = $this->app(on: false);
        (new TestClient($prod))->get('/guarded');
        $this->assertSame(404, (new TestClient($prod))->get('/_pype/requests')->status());
        $this->assertDirectoryDoesNotExist($this->base . '/storage/debug');

        // In debug with recording switched off: the route screens stay, the recorder is gone.
        $off = $this->app(['requests' => ['enabled' => false]]);
        $client = new TestClient($off);
        $client->get('/guarded');
        $this->assertSame(200, $client->get('/_pype/routes')->status());
        $this->assertSame(404, $client->get('/_pype/requests')->status());
        $this->assertStringNotContainsString('Requests', $client->get('/_pype')->body());
        $this->assertDirectoryDoesNotExist($this->base . '/storage/debug');

        // While the app's own tests run, nothing is recorded unless asked for.
        $testing = new Application($this->base, ['app' => ['env' => 'testing', 'debug' => true]]);
        $testing->register(DebugProvider::class);
        $testing->router->get('/hi', fn () => 'hi');
        (new TestClient($testing))->get('/hi');
        $this->assertSame(200, (new TestClient($testing))->get('/_pype/routes')->status());
        $this->assertSame(404, (new TestClient($testing))->get('/_pype/requests')->status());
        $this->assertDirectoryDoesNotExist($this->base . '/storage/debug');

        // An app with no database bound records without one.
        $bare = new Application($this->base, ['app' => ['env' => 'local', 'debug' => true]]);
        $bare->register(DebugProvider::class);
        $bare->router->get('/hi', fn () => 'hi');
        $this->assertSame(200, (new TestClient($bare))->get('/hi')->status());
        $this->assertSame(['Closure'], array_column($this->kept($bare)[0]['stages'], 'name'));
        $this->assertSame(404, (new TestClient($bare))->get('/nope')->status());
        $this->assertSame([], $this->kept($bare)[1]['stages'], 'no route and no stages of its own: still recorded');
    }
}

final class RecorderNote
{
    public function __construct(public readonly int $id)
    {
    }
}

#[Provides(RecorderNote::class)]
final class RecorderLoadsNote implements Stage
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $row = $this->db->first('SELECT id FROM notes WHERE id = ?', [(int) $request->param('id')]) ?? throw HttpException::notFound();
        $request->context()->put(new RecorderNote((int) $row['id']));

        return $next($request);
    }
}

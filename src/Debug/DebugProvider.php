<?php

declare(strict_types=1);

namespace Pype\Debug;

use Pype\Application;
use Pype\Console\Input;
use Pype\Database\Connection;
use Pype\Database\Migrator;
use Pype\Outlet\HtmlOutlet;
use Pype\Pipeline\StageSpec;
use Pype\Provider;
use Pype\Routing\Router;
use Pype\Routing\Urls;

/**
 * The development screens under /_pype: every route as the pipeline it is (/_pype/routes), and
 * what each request did, stage by stage (/_pype/requests; debug.requests.enabled, on by default,
 * keeps the newest debug.requests.keep in debug.requests.dir).
 *
 * Add it to config/app.php's providers. Like the log viewer and the dev mailbox it is there in
 * debug only; outside debug it is registered only when switched on AND given stages to guard it
 * (debug.stages), since the screens show how the app is put together. Outside debug that is the
 * route screen alone: the recorder only when debug.requests.enabled says so, the sandbox never.
 *
 *   'debug' => ['enabled' => true, 'path' => '/_pype', 'stages' => [Authenticate::class, EnsureAdmin::class]]
 */
final class DebugProvider implements Provider
{
    public function register(Application $app): void
    {
        $stages = $app->config('debug.stages', []);

        if (!$app->config('debug.enabled', $app->config('app.debug', false)) || !($app->config('app.debug') || $stages !== [])) {
            return;
        }

        $path = rtrim((string) $app->config('debug.path', '/_pype'), '/');
        $debug = (bool) $app->config('app.debug');

        // The recorder writes a file per request and keeps what was sent: in debug by default
        // (not while the app's tests run: hundreds of requests nobody will look at), and outside
        // debug only when asked for by name. The sandbox needs debug: its own process is in
        // sandbox mode only then.
        $recording = (bool) $app->config('debug.requests.enabled', $debug && $app->environment() !== 'testing');
        $sandbox = $debug && (bool) $app->config('debug.sandbox.enabled', true);

        $app->container->singleton(RouteExplorer::class, static fn () => new RouteExplorer($app));
        $app->container->singleton(RequestLog::class, static fn () => new RequestLog(
            $app->path((string) $app->config('debug.requests.dir', 'storage/debug/requests')),
            (int) $app->config('debug.requests.keep', 100),
        ));
        $app->container->singleton(DebugPages::class, static fn ($c) => new DebugPages(
            $c->get(RouteExplorer::class),
            $app->router,
            $c->get(Urls::class),
            $recording ? $c->get(RequestLog::class) : null,
            $sandbox ? $c->get(Sandbox::class) : null,
        ));

        $app->container->singleton(Sandbox::class, static fn () => new Sandbox(
            $app,
            $app->path((string) $app->config('debug.sandbox.dir', 'storage/debug/sandbox')),
        ));

        if ($sandbox) {
            $this->commands($app);
        }

        // In the sandbox's own process the one request it runs is kept where the runner reads it back.
        if ($recording && $app->config('app.sandbox')) {
            $app->container->singleton(RequestLog::class, static fn () => new RequestLog(dirname((string) $app->config('app.sandbox')) . '/trace', 1));
        }

        if ($recording) {
            $app->observeStages((new Recorder($app, $app->container->get(RequestLog::class), $path))->observer());
            $app->through(new StageSpec(RecordRequest::class));
        }

        $app->router->group($path, $stages, function (Router $r) use ($recording, $sandbox) {
            $r->get('/', [ShowDebugIndex::class, HtmlOutlet::class])->name('debug');
            $r->get('/routes', [ShowRoutes::class, HtmlOutlet::class])->name('routes');
            $r->get('/routes/show', [ShowRoute::class, HtmlOutlet::class])->name('routes.show');

            if ($sandbox) {
                $r->get('/sandbox', [ShowSandbox::class, HtmlOutlet::class])->name('sandbox');
                $r->post('/sandbox', [SameOrigin::class, RunSandbox::class, HtmlOutlet::class])->name('sandbox.run');
            }

            if ($recording) {
                $r->get('/requests', [ShowRequests::class, HtmlOutlet::class])->name('requests');
                $r->get('/requests/show', [ShowRequest::class, HtmlOutlet::class])->name('requests.show');
            }
        }, name: 'pype.');
    }

    /**
     * `bin/pype sandbox:build` builds the sandbox's database from the app's migrations and seeder
     * (debug.sandbox.seeder, default DatabaseSeeder; false for none); `sandbox:reset` throws away
     * what the last run left. `sandbox:run` is the screen's: one request, as JSON on standard input.
     */
    private function commands(Application $app): void
    {
        $app->command('sandbox:build', 'Build the development sandbox: a throw-away SQLite database, migrated and seeded', static function (Input $in, Sandbox $sandbox) use ($app): int {
            if (!$in->flag('inside')) {
                try {
                    echo $sandbox->build(), "\n";

                    return 0;
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e->getMessage() . "\n");

                    return 1;
                }
            }

            // Started by Sandbox::build() in sandbox mode: this process's database is the new file.
            if (!$app->config('app.sandbox')) {
                fwrite(STDERR, "sandbox:build --inside is started by sandbox:build itself.\n");

                return 1;
            }

            $seeder = $app->config('debug.sandbox.seeder', 'DatabaseSeeder');
            $class = is_string($seeder) && $seeder !== '' ? (str_contains($seeder, '\\') ? $seeder : "Database\\Seeders\\{$seeder}") : null;
            $class = $class !== null && class_exists($class) ? $class : null;
            $container = $app->container;

            $sandbox->buildInside(
                $container->get(Connection::class),
                static fn () => $container->get(Migrator::class)->migrate(),
                static function () use ($container, $class, $in): void {
                    $instance = $container->get($class);
                    $instance->setContainer($container);
                    $instance($in);
                },
                $class,
            );

            echo 'Sandbox built: migrated', $class === null ? ', not seeded (no ' . (is_string($seeder) ? $seeder : 'seeder') . ').' : ' and seeded by ' . $class . '.', "\n";

            return 0;
        });

        $app->command('sandbox:reset', 'Throw away what the last sandbox run left: the next one starts from the built database', static function (Sandbox $sandbox): int {
            $sandbox->reset();
            echo "The sandbox is back to how it was built.\n";

            return 0;
        });

        $app->command('sandbox:run', 'Run one request against the sandbox (used by /_pype/sandbox; the request is JSON on standard input)', static function () use ($app): int {
            $spec = json_decode((string) stream_get_contents(STDIN), true);

            try {
                $result = (new SandboxRun($app))->run(is_array($spec) ? $spec : []);
            } catch (\Throwable $e) {
                fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

                return 1;
            }

            echo Sandbox::MARK, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            return 0;
        });
    }
}

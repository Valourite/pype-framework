<?php

declare(strict_types=1);

namespace Pype\Console;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pype\Application;
use Pype\Cache\Cache;
use Pype\Cache\Store;
use Pype\Database\Migrator;
use Pype\Pipeline\Invoker;
use Pype\Queue\FailedJobs;
use Pype\Queue\Queue;
use Pype\Queue\Worker;
use Pype\Routing\Router;
use Pype\Schedule\Schedule;
use Pype\Server\SwooleServer;

/**
 * Minimal CLI: `php bin/pype <command> [args]`.
 */
final class Console
{
    private const SCHEDULE = 'routes/schedule.php';

    /** @var array<string, array{0: string, 1: Closure(list<string>): int}> */
    private array $commands;

    public function __construct(private readonly Application $app)
    {
        $this->commands = [
            'serve' => ['Start the server [--port=8000] [--host=localhost] [--server=fpm|swoole] [--workers=N]', $this->serve(...)],
            'routes' => ['List routes and their compiled pipelines', $this->routes(...)],
            'make:stage' => ['Create app/Stages/<Name>.php', fn (array $a) => $this->make('Stages', $a[0] ?? null, 'stage')],
            'make:outlet' => ['Create app/Outlets/<Name>.php', fn (array $a) => $this->make('Outlets', $a[0] ?? null, 'outlet')],
            'make:provider' => ['Create app/Providers/<Name>.php', fn (array $a) => $this->make('Providers', $a[0] ?? null, 'provider')],
            'make:job' => ['Create app/Jobs/<Name>.php (push it with Pype\\Queue\\Queue)', fn (array $a) => $this->make('Jobs', $a[0] ?? null, 'job')],
            'make:command' => ['Create app/Commands/<Name>.php (register it in a provider)', fn (array $a) => $this->make('Commands', $a[0] ?? null, 'command')],
            'make:migration' => ['Create database/migrations/<timestamp>_<name>.php [--sql for plain SQL]', $this->makeMigration(...)],
            'migrate' => ['Run pending migrations', $this->migrate(...)],
            'migrate:status' => ['Show which migrations have run', $this->migrateStatus(...)],
            'key:generate' => ['Set APP_KEY in .env (for signed URLs) [--show] [--force]', $this->keyGenerate(...)],
            'optimize' => ['Compile the route tables to ' . Application::ROUTE_CACHE . ' (run on deploy)', $this->optimize(...)],
            'optimize:clear' => ['Remove the compiled route tables', $this->optimizeClear(...)],
            'cache:clear' => ['Remove everything in the cache (keys with the cache prefix)', $this->cacheClear(...)],
            'schedule:run' => ['Run the scheduled tasks that are due (call from cron every minute) [--time="Y-m-d H:i"]', $this->scheduleRun(...)],
            'schedule:list' => ['List the scheduled tasks in ' . self::SCHEDULE, $this->scheduleList(...)],
            'schedule:work' => ['Run schedule:run every minute, without cron (Ctrl+C to stop)', $this->scheduleWork(...)],
            'queue:work' => ['Process queued jobs [--queue=default,mail] [--once] [--stop-when-empty] [--max-jobs=N] [--max-time=S] [--memory=128] [--sleep=1] [--timeout=60]', $this->queueWork(...)],
            'queue:restart' => ['Tell running queue workers to stop after their current job (run on deploy)', $this->queueRestart(...)],
            'queue:failed' => ['List failed jobs', $this->queueFailed(...)],
            'queue:retry' => ['Push failed jobs back onto their queue: <id> or all', $this->queueRetry(...)],
            'queue:forget' => ['Delete a failed job: <id>', $this->queueForget(...)],
        ];

        foreach ($app->commands() as $name => [$description, $handler]) {
            $this->commands[$name] = [$description, fn (array $args) => $this->runAppCommand($handler, $args)];
        }
    }

    /**
     * Run a command in this process, as the scheduler does.
     *
     * @param list<string> $args
     */
    public function call(string $name, array $args = []): int
    {
        $command = $this->commands[$name] ?? throw new \InvalidArgumentException("Unknown command [{$name}].");

        return ($command[1])($args);
    }

    /** @param class-string|Closure $handler */
    private function runAppCommand(string|Closure $handler, array $args): int
    {
        $callable = is_string($handler) ? $this->app->container->get($handler) : $handler;
        $result = (new Invoker($this->app->container))->call($callable, new Input($args), static fn () => null);

        return is_int($result) ? $result : 0;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'help';

        if (!isset($this->commands[$name])) {
            return $this->help($name === 'help' ? null : $name);
        }

        return ($this->commands[$name][1])(array_slice($argv, 2));
    }

    private function help(?string $unknown): int
    {
        if ($unknown !== null) {
            fwrite(STDERR, "Unknown command: {$unknown}\n\n");
        }

        echo "pype — available commands:\n\n";
        foreach ($this->commands as $name => [$description]) {
            printf("  %-18s %s\n", $name, $description);
        }

        return $unknown === null ? 0 : 1;
    }

    private function serve(array $args): int
    {
        $opts = $this->options($args);

        // APP_SERVER=swoole: a long-running OpenSwoole server (the app boots once).
        if (($opts['server'] ?? $this->app->config('app.server', 'fpm')) === 'swoole') {
            $workers = $opts['workers'] ?? $this->app->config('app.swoole.workers');
            (new SwooleServer($this->app, $opts['host'] ?? '0.0.0.0', (int) ($opts['port'] ?? 8000), [
                'worker_num' => $workers === null || $workers === '' ? null : (int) $workers,
                'max_request' => $this->app->config('app.swoole.max_request'),
            ]))->start();

            return 0;
        }

        // Otherwise PHP's built-in server for development; production runs behind nginx + PHP-FPM.
        $address = ($opts['host'] ?? 'localhost') . ':' . ($opts['port'] ?? '8000');

        echo "pype serving on http://{$address}\n";
        passthru(sprintf('%s -S %s -t %s', escapeshellarg(PHP_BINARY), escapeshellarg($address), escapeshellarg($this->app->basePath . '/public')), $code);

        return $code;
    }

    private function routes(): int
    {
        $describe = Router::describe(...);

        foreach ($this->app->router->routes() as $route) {
            $compiled = $this->app->router->compile($route->stages());
            $outlets = array_filter($compiled, Router::isOutlet(...));
            $stages = implode(' → ', array_map($describe, array_diff_key($compiled, $outlets)));
            $outlet = $outlets === [] ? '' : '  ⇒ ' . $describe(end($outlets));

            printf("%-7s %-28s %-18s %s%s\n", $route->method(), $route->path(), $route->name() ?? '', $stages, $outlet);
        }

        return 0;
    }

    private function migrate(): int
    {
        $applied = $this->app->container->get(Migrator::class)->migrate();

        foreach ($applied as $name) {
            echo "Migrated: {$name}\n";
        }

        echo $applied === [] ? "Nothing to migrate.\n" : '';

        return 0;
    }

    private function migrateStatus(): int
    {
        foreach ($this->app->container->get(Migrator::class)->status() as $name => $ran) {
            printf("  [%s] %s\n", $ran ? 'x' : ' ', $name);
        }

        return 0;
    }

    /**
     * A random 32-byte key for signed URLs (and, later, encryption), written to .env as
     * APP_KEY=base64:... Refuses to replace an existing key without --force: links signed
     * with the old key stop working.
     */
    private function keyGenerate(array $args): int
    {
        $key = 'base64:' . base64_encode(random_bytes(32));

        if (in_array('--show', $args, true)) {
            echo $key, "\n";

            return 0;
        }

        $file = $this->app->path('.env');
        $env = is_file($file) ? (string) file_get_contents($file) : '';

        if (preg_match('/^APP_KEY=(\S+)/m', $env) && !in_array('--force', $args, true)) {
            fwrite(STDERR, "APP_KEY is already set. Use --force to replace it (links signed with the old key stop working).\n");

            return 1;
        }

        $env = preg_match('/^APP_KEY=.*$/m', $env)
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $env)
            : rtrim($env, "\n") . ($env === '' ? '' : "\n") . 'APP_KEY=' . $key . "\n";

        file_put_contents($file, $env);
        echo "APP_KEY set in .env\n";

        return 0;
    }

    /**
     * Compile the route matching tables to a PHP file that opcache keeps in memory, so PHP-FPM
     * doesn't rebuild them on every request. Safe to forget: if routes change, the router
     * notices the file no longer matches and builds the tables itself.
     */
    private function optimize(): int
    {
        $file = $this->app->path(Application::ROUTE_CACHE);
        @mkdir(dirname($file), 0775, true);

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "<?php\n\n// Generated by `php bin/pype optimize`. Delete with `php bin/pype optimize:clear`.\nreturn " . var_export($this->app->router->compiled(), true) . ";\n");
        rename($tmp, $file);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        printf("Compiled %d routes to %s\n", count($this->app->router->routes()), Application::ROUTE_CACHE);

        return 0;
    }

    private function cacheClear(): int
    {
        // Store is an interface, so has() is only true once CacheProvider has bound it.
        if (!$this->app->container->has(Store::class)) {
            fwrite(STDERR, "No cache: register Pype\\Cache\\CacheProvider in config/app.php.\n");

            return 1;
        }

        // APCu memory belongs to the process group: the CLI's is not PHP-FPM's or OpenSwoole's.
        if ($this->app->config('cache.driver') === 'apcu') {
            echo "CACHE_DRIVER=apcu: the CLI can't reach the web server's APCu memory.\n"
                . "Clear it by reloading PHP-FPM (e.g. `systemctl reload php8.4-fpm`; deploys usually do) or restarting OpenSwoole.\n";

            return 0;
        }

        $this->app->container->get(Cache::class)->clear();
        echo "Cache cleared.\n";

        return 0;
    }

    /** Loaded only by the schedule commands, never for web requests. */
    private function schedule(): Schedule
    {
        $schedule = new Schedule();
        $file = $this->app->path((string) $this->app->config('app.schedule', self::SCHEDULE));

        if (is_file($file)) {
            (static function (Schedule $schedule, Application $app, string $__file): void {
                require $__file;
            })($schedule, $this->app, $file);
        }

        return $schedule;
    }

    private function scheduleRun(array $args): int
    {
        $time = (new Input($args))->option('time');
        $now = new DateTimeImmutable($time ?? 'now');
        $now = $now->setTime((int) $now->format('G'), (int) $now->format('i'));   // whole minutes
        $results = $this->schedule()->run($now, $this->app->container, $this->call(...));

        if ($results === []) {
            echo "No scheduled tasks are due.\n";
        }

        foreach ($results as ['task' => $task, 'status' => $status, 'ms' => $ms, 'error' => $error]) {
            printf("%-8s %s%s\n", $status, $task->description(), match ($status) {
                'ran' => " ({$ms} ms)",
                'failed' => ": {$error}",
                default => '',
            });
        }

        return in_array('failed', array_column($results, 'status'), true) ? 1 : 0;
    }

    private function scheduleList(): int
    {
        $tasks = $this->schedule()->tasks();

        if ($tasks === []) {
            echo 'No tasks in ' . self::SCHEDULE . ".\n";
        }

        foreach ($tasks as $task) {
            $flags = array_filter([
                $task->overlapMinutes() !== null ? 'without overlapping' : null,
                $task->runsOnOneServer() ? 'one server' : null,
                $task->condition() !== null ? 'conditional' : null,
            ]);
            printf("%-16s %-20s %s%s\n", $task->expression(), $task->timezoneName() ?? date_default_timezone_get(), $task->description(), $flags === [] ? '' : '  [' . implode(', ', $flags) . ']');
        }

        return 0;
    }

    /** For boxes without cron: a fresh `schedule:run` process at the start of every minute. */
    private function scheduleWork(): int
    {
        $stop = false;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
            pcntl_signal(SIGINT, function () use (&$stop) { $stop = true; });
        }

        echo "Running the schedule every minute. Ctrl+C to stop.\n";
        $script = $_SERVER['SCRIPT_FILENAME'] ?? $this->app->path('bin/pype');

        while (!$stop) {
            // Sleep in short steps so a signal stops us quickly.
            $next = (intdiv(time(), 60) + 1) * 60;
            while (!$stop && time() < $next) {
                usleep(250_000);
            }

            if (!$stop) {
                echo '[' . date('Y-m-d H:i') . "]\n";
                passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' schedule:run');
            }
        }

        return 0;
    }

    private function queueWork(array $args): int
    {
        $in = new Input($args);
        $container = $this->app->container;
        $worker = new Worker(
            $container->get(Queue::class),
            $container,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            $container->has(Store::class) ? $container->get(Cache::class) : null,
        );

        $queues = array_values(array_filter(array_map('trim', explode(',', $in->option('queue') ?? (string) $this->app->config('queue.default', 'default')))));
        echo 'Processing jobs on ' . implode(', ', $queues) . ".\n";

        $processed = $worker->run(
            queues: $queues,
            once: $in->flag('once'),
            stopWhenEmpty: $in->flag('stop-when-empty'),
            maxJobs: ($v = $in->option('max-jobs')) === null ? null : (int) $v,
            maxTime: ($v = $in->option('max-time')) === null ? null : (int) $v,
            memory: (int) ($in->option('memory') ?? 128),
            sleep: (float) ($in->option('sleep') ?? 1),
            timeout: (int) ($in->option('timeout') ?? 60),
        );

        echo "Processed {$processed} job(s).\n";

        return 0;
    }

    private function queueRestart(): int
    {
        if (!$this->app->container->has(Store::class)) {
            fwrite(STDERR, "queue:restart signals workers through the cache: register Pype\\Cache\\CacheProvider.\n");

            return 1;
        }

        $this->app->container->get(Cache::class)->forever(Worker::RESTART_KEY, microtime(true));
        echo "Workers will stop after their current job (your supervisor starts them again).\n";

        return 0;
    }

    private function failedJobs(): ?FailedJobs
    {
        $queue = $this->app->container->get(Queue::class);

        if (!$queue instanceof FailedJobs) {
            fwrite(STDERR, 'QUEUE_DRIVER=' . $this->app->config('queue.driver', 'sync') . " keeps no failed jobs.\n");

            return null;
        }

        return $queue;
    }

    private function queueFailed(): int
    {
        if (($failed = $this->failedJobs()) === null) {
            return 1;
        }

        $rows = $failed->failed();
        echo $rows === [] ? "No failed jobs.\n" : '';

        foreach ($rows as $row) {
            printf("%-6s %-19s %-10s %s\n       %s\n", $row['id'], date('Y-m-d H:i:s', $row['failed_at']), $row['queue'], $row['name'], strtok($row['exception'], "\n"));
        }

        return 0;
    }

    private function queueRetry(array $args): int
    {
        $id = (new Input($args))->argument(0);

        if ($id === null || ($failed = $this->failedJobs()) === null) {
            $id === null && fwrite(STDERR, "Usage: queue:retry <id>|all\n");

            return 1;
        }

        $ids = $id === 'all' ? array_column($failed->failed(), 'id') : [$id];
        $retried = array_filter($ids, $failed->retry(...));
        echo count($retried) . " job(s) pushed back onto their queue.\n";

        return count($retried) === count($ids) ? 0 : 1;
    }

    private function queueForget(array $args): int
    {
        $id = (new Input($args))->argument(0);

        if ($id === null || ($failed = $this->failedJobs()) === null) {
            $id === null && fwrite(STDERR, "Usage: queue:forget <id>\n");

            return 1;
        }

        if (!$failed->forget($id)) {
            fwrite(STDERR, "No failed job {$id}.\n");

            return 1;
        }

        echo "Deleted failed job {$id}.\n";

        return 0;
    }

    private function optimizeClear(): int
    {
        @unlink($this->app->path(Application::ROUTE_CACHE));
        echo "Removed " . Application::ROUTE_CACHE . "\n";

        return 0;
    }

    private function makeMigration(array $args): int
    {
        $sql = in_array('--sql', $args, true);
        $name = array_values(array_filter($args, static fn (string $a) => !str_starts_with($a, '--')))[0] ?? '';

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            fwrite(STDERR, "Give a snake_case name, e.g. make:migration create_posts_table\n");

            return 1;
        }

        $dir = $this->app->path($this->app->config('database.migrations', 'database/migrations'));
        $file = date('Y_m_d_His') . "_{$name}." . ($sql ? 'sql' : 'php');

        @mkdir($dir, 0777, true);
        file_put_contents("{$dir}/{$file}", $sql ? "-- {$name}\n\n" : $this->migrationStub($name));
        echo "Created {$file}\n";

        return 0;
    }

    private function migrationStub(string $name): string
    {
        if (preg_match('/^create_(\w+)_table$/', $name, $m)) {
            $body = <<<PHP
                        \$schema->create('{$m[1]}', function (Table \$t) {
                            \$t->id();
                            \$t->timestamps();
                        });
                PHP;
        } else {
            $table = preg_match('/_(?:to|from|in)_(\w+?)(?:_table)?$/', $name, $m) ? $m[1] : 'table_name';
            $body = <<<PHP
                        \$schema->table('{$table}', function (Table \$t) {
                            //
                        });
                PHP;
        }

        return <<<PHP
            <?php

            use Pype\\Database\\Schema\\Migration;
            use Pype\\Database\\Schema\\Schema;
            use Pype\\Database\\Schema\\Table;

            return new class extends Migration {
                public function up(Schema \$schema): void
                {
            {$body}
                }
            };

            PHP;
    }

    private function make(string $dir, ?string $name, string $kind): int
    {
        if ($name === null || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            fwrite(STDERR, "Give a StudlyCase class name, e.g. make:{$kind} LoadAccount\n");

            return 1;
        }

        $path = "{$this->app->basePath}/app/{$dir}/{$name}.php";

        if (file_exists($path)) {
            fwrite(STDERR, "Already exists: {$path}\n");

            return 1;
        }

        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $this->stub($kind, "App\\{$dir}", $name));
        echo "Created app/{$dir}/{$name}.php\n";

        return 0;
    }

    private function stub(string $kind, string $namespace, string $class): string
    {
        $body = match ($kind) {
            'stage' => <<<PHP
                use Closure;
                use Pype\Http\Request;
                use Pype\Pipeline\Stage;

                final class {$class} implements Stage
                {
                    public function handle(Request \$request, Closure \$next): mixed
                    {
                        return \$next(\$request);
                    }
                }
                PHP,
            'outlet' => <<<PHP
                use Pype\Http\Request;
                use Pype\Http\Response;
                use Pype\Outlet\Outlet;

                final class {$class} extends Outlet
                {
                    /** Turn the pipeline's result (or error) into the response body. */
                    public function render(Response \$response, Request \$request): string
                    {
                        if (\$response->isError()) {
                            return \$response->errorDetails()['message'];
                        }

                        \$data = \$response->data();

                        return is_scalar(\$data) || \$data instanceof \\Stringable ? (string) \$data : print_r(\$data, true);
                    }

                    public function contentType(Response \$response): string
                    {
                        return 'text/plain; charset=utf-8';
                    }
                }
                PHP,
            'command' => <<<PHP
                use Pype\Console\Input;

                /**
                 * Register in a provider:
                 *   \$app->command('app:{$this->kebab($class)}', 'What it does', {$class}::class);
                 *
                 * Parameters are injected: Input for the arguments, plus any service (Connection, ...).
                 */
                final class {$class}
                {
                    public function __invoke(Input \$input): int
                    {
                        echo "Hello from {$class}\\n";

                        return 0;
                    }
                }
                PHP,
            'job' => <<<PHP
                /**
                 * A queued job: \$queue->push(new {$class}(...)). Keep only plain data in the
                 * constructor (ids, strings, arrays); handle() gets services injected by type.
                 */
                final class {$class}
                {
                    public int \$tries = 1;          // how often it may run before it fails for good

                    public array \$backoff = [10];   // seconds before each retry

                    public function __construct()
                    {
                    }

                    public function handle(): void
                    {
                    }
                }
                PHP,
            'provider' => <<<PHP
                use Pype\Application;
                use Pype\Provider;

                final class {$class} implements Provider
                {
                    public function register(Application \$app): void
                    {
                    }
                }
                PHP,
        };

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$body}\n";
    }

    /** @return array<string, string> */
    private function kebab(string $class): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $class));
    }

    private function options(array $args): array
    {
        $opts = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([\w-]+)=(.*)$/', $arg, $m)) {
                $opts[$m[1]] = $m[2];
            }
        }

        return $opts;
    }
}

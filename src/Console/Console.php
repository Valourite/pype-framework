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
use Pype\Routing\RouteCheck;
use Pype\Routing\Router;
use Pype\Schedule\Schedule;
use Pype\Server\SwooleServer;

/**
 * Minimal CLI: `php bin/pype <command> [args]`.
 */
final class Console
{
    /** @var array<string, array{0: string, 1: Closure(list<string>): int}> */
    private array $commands;

    public function __construct(private readonly Application $app)
    {
        $this->commands = [
            'serve' => ['Start the server [--port=8000] [--host=localhost] [--server=fpm|swoole] [--workers=N]', $this->serve(...)],
            'routes' => ['List routes and their compiled pipelines', $this->routes(...)],
            'routes:check' => ['Check every route\'s stage order: what a stage #[Requires] must be #[Provides]d before it', $this->routesCheck(...)],
            'make:stage' => ['Create app/Stages/<Name>.php', fn (array $a) => $this->make('Stages', $a[0] ?? null, 'stage')],
            'make:authorize' => ['Create app/Stages/<Name>.php (may this user do this? 403/404 when not)', fn (array $a) => $this->make('Stages', $a[0] ?? null, 'authorize')],
            'make:outlet' => ['Create app/Outlets/<Name>.php', fn (array $a) => $this->make('Outlets', $a[0] ?? null, 'outlet')],
            'make:view' => ['Create app/Views/<Name>.php (a page: reads the context) and its template', $this->makeView(...)],
            'make:provider' => ['Create app/Providers/<Name>.php', fn (array $a) => $this->make('Providers', $a[0] ?? null, 'provider')],
            'make:factory' => ['Create database/factories/<Name>Factory.php', fn (array $a) => $this->makeIn('database/factories', 'Database\\Factories', isset($a[0]) ? preg_replace('/Factory$/', '', $a[0]) . 'Factory' : null, 'factory')],
            'make:seeder' => ['Create database/seeders/<Name>.php', fn (array $a) => $this->makeIn('database/seeders', 'Database\\Seeders', $a[0] ?? null, 'seeder')],
            'make:job' => ['Create app/Jobs/<Name>.php (push it with Pype\\Queue\\Queue)', fn (array $a) => $this->make('Jobs', $a[0] ?? null, 'job')],
            'make:command' => ['Create app/Commands/<Name>.php (register it in a provider)', fn (array $a) => $this->make('Commands', $a[0] ?? null, 'command')],
            'make:routes' => ['Create routes/<name>/web.php and api.php, loaded by $app->routesIn() [--web or --api: only that one]', $this->makeRoutes(...)],
            'make:migration' => ['Create database/migrations/<timestamp>_<name>.php [--sql for plain SQL]', $this->makeMigration(...)],
            'migrate' => ['Run pending migrations', $this->migrate(...)],
            'migrate:rollback' => ['Undo the last batch of migrations [--step=N: the last N instead]', $this->migrateRollback(...)],
            'migrate:fresh' => ['Drop every table and run all migrations (development) [--seed] [--force when APP_DEBUG is off]', $this->migrateFresh(...)],
            'db:seed' => ['Run database/seeders/DatabaseSeeder [--class=Name] [--force when APP_DEBUG is off] [seeder options]', $this->dbSeed(...)],
            'migrate:status' => ['Show which migrations have run', $this->migrateStatus(...)],
            'install' => ['Choose optional packages for a new app: template engine, Faker [--template=php|latte|blade|both] [--faker|--no-faker] [--no-interaction] [--dry-run]', $this->install(...)],
            'key:generate' => ['Set APP_KEY in .env (for signed URLs) [--show] [--force]', $this->keyGenerate(...)],
            'optimize' => ['Compile the route tables to ' . Application::ROUTE_CACHE . ' (run on deploy)', $this->optimize(...)],
            'optimize:clear' => ['Remove the compiled route tables', $this->optimizeClear(...)],
            'cache:clear' => ['Remove everything in the cache (keys with the cache prefix)', $this->cacheClear(...)],
            'schedule:run' => ['Run the scheduled tasks that are due (call from cron every minute) [--time="Y-m-d H:i"]', $this->scheduleRun(...)],
            'schedule:list' => ['List the scheduled tasks in ' . Application::SCHEDULE, $this->scheduleList(...)],
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

        $this->mailNotice(($opts['host'] ?? 'localhost') . ':' . ($opts['port'] ?? '8000'));

        // APP_SERVER=swoole: a long-running OpenSwoole server (the app boots once).
        if (($opts['server'] ?? $this->app->config('app.server', 'fpm')) === 'swoole') {
            SwooleServer::fromApp($this->app, $opts)->start();

            return 0;
        }

        // Otherwise PHP's built-in server for development; production runs behind nginx + PHP-FPM.
        $address = ($opts['host'] ?? 'localhost') . ':' . ($opts['port'] ?? '8000');

        echo "pype serving on http://{$address}\n";
        passthru(sprintf('%s -S %s -t %s', escapeshellarg(PHP_BINARY), escapeshellarg($address), escapeshellarg($this->app->basePath . '/public')), $code);

        return $code;
    }

    /** One line at start-up when mail isn't really delivered, and where to read it. */
    private function mailNotice(string $address): void
    {
        $mailbox = $this->app->router->named('pype.mail');
        $where = $mailbox === null ? 'in the log (/_pype/logs)' : "at http://{$address}{$mailbox->path()}";

        if ($this->app->config('mail.driver', 'log') === 'log') {
            echo "Mail: MAIL_DRIVER=log, so nothing is delivered. Read what was sent {$where}.\n";
        } elseif ($mailbox !== null) {
            echo "Mail: copies of what is sent are at http://{$address}{$mailbox->path()}\n";
        }
    }

    /** Packages install can add: [composer require arguments, what it's for]. */
    private const INSTALLABLE = [
        'latte' => [['latte/latte'], 'Latte templates: write resources/views/<name>.latte'],
        'blade' => [['eftec/bladeone'], 'Blade templates (BladeOne): write resources/views/<name>.blade.php'],
        'faker' => [['--dev', 'fakerphp/faker'], 'Faker for factories and seeders: $faker = Faker\\Factory::create(); (the built-in Fake stays available)'],
    ];

    /**
     * Run by composer create-project (post-create-project-cmd) after the app is in place: asks which
     * optional packages to add and runs composer require for them. Answers come from the options
     * first; without a terminal (CI, --no-interaction) anything not given is "no".
     */
    private function install(array $args): int
    {
        $opts = $this->options($args);
        $interactive = !in_array('--no-interaction', $args, true) && !in_array('-n', $args, true)
            && !filter_var(getenv('COMPOSER_NO_INTERACTION'), FILTER_VALIDATE_BOOLEAN)
            && function_exists('stream_isatty') && @stream_isatty(STDIN);

        $template = $opts['template'] ?? ($interactive ? $this->choose('Templates', [
            'php' => 'plain PHP (no package)',
            'latte' => 'Latte',
            'blade' => 'Blade (BladeOne)',
            'both' => 'Latte and Blade',
        ], 'php') : 'php');

        if (!in_array($template, ['php', 'latte', 'blade', 'both'], true)) {
            fwrite(STDERR, "Unknown --template={$template}: use php, latte, blade or both.\n");

            return 1;
        }

        $faker = match (true) {
            in_array('--faker', $args, true) => true,
            in_array('--no-faker', $args, true) => false,
            default => $interactive && $this->confirm('Add Faker for fake data in factories and seeders?', false),
        };

        $chosen = [...match ($template) { 'latte' => ['latte'], 'blade' => ['blade'], 'both' => ['latte', 'blade'], default => [] }, ...($faker ? ['faker'] : [])];

        if ($chosen === []) {
            echo "Nothing extra to install: plain PHP templates and the built-in Fake. Run php bin/pype install again any time.\n";

            return 0;
        }

        $composer = getenv('COMPOSER_BINARY') ?: 'composer';
        $runtime = array_merge(...array_map(fn (string $k) => self::INSTALLABLE[$k][0], array_values(array_filter($chosen, fn ($k) => $k !== 'faker'))));
        $commands = array_values(array_filter([
            $runtime === [] ? null : [$composer, 'require', ...$runtime],
            $faker ? [$composer, 'require', ...self::INSTALLABLE['faker'][0]] : null,
        ]));

        foreach ($commands as $command) {
            $line = implode(' ', array_map('escapeshellarg', $command));
            // composer passes its own path as COMPOSER_BINARY (a .phar or a script): run it with this PHP.
            $line = str_ends_with((string) $command[0], '.phar') ? escapeshellarg(PHP_BINARY) . ' ' . $line : $line;

            if (in_array('--dry-run', $args, true)) {
                echo "Would run: {$line}\n";
                continue;
            }

            echo "Running: {$line}\n";
            passthru($line, $code);

            if ($code !== 0) {
                fwrite(STDERR, "That failed (exit {$code}). Run it yourself, or php bin/pype install again.\n");

                return $code;
            }
        }

        foreach ($chosen as $key) {
            echo (in_array('--dry-run', $args, true) ? '  would add: ' : '  ✓ ') . self::INSTALLABLE[$key][1] . "\n";
        }

        return 0;
    }

    /** @param array<string, string> $options value => label; the default is chosen on Enter */
    private function choose(string $question, array $options, string $default): string
    {
        $keys = array_keys($options);
        echo "{$question}:\n";
        foreach ($keys as $i => $key) {
            printf("  [%d] %s%s\n", $i + 1, $options[$key], $key === $default ? ' (default)' : '');
        }
        echo '> ';
        $answer = trim((string) fgets(STDIN));

        return $keys[(int) $answer - 1] ?? (in_array($answer, $keys, true) ? $answer : $default);
    }

    private function confirm(string $question, bool $default): bool
    {
        echo $question . ($default ? ' [Y/n] ' : ' [y/N] ');
        $answer = strtolower(trim((string) fgets(STDIN)));

        return $answer === '' ? $default : in_array($answer, ['y', 'yes'], true);
    }

    private function routesCheck(): int
    {
        $check = new RouteCheck($this->app);
        $problems = $check->problems();

        foreach ($problems as $problem) {
            fwrite(STDERR, "✗ {$problem}\n");
        }

        foreach ($check->hints() as $hint) {
            echo "  hint: {$hint}\n";
        }

        printf("%s %d routes checked, %d problem%s.\n", $problems === [] ? '✓' : '✗', count($this->app->router->routes()), count($problems), count($problems) === 1 ? '' : 's');

        return $problems === [] ? 0 : 1;
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
        foreach ($this->app->container->get(Migrator::class)->status() as $name => $batch) {
            printf("  [%s] %s%s\n", $batch !== null ? 'x' : ' ', $name, $batch !== null ? "  (batch {$batch})" : '');
        }

        return 0;
    }

    private function migrateRollback(array $args): int
    {
        $step = (new Input($args))->option('step');

        if ($step !== null && !ctype_digit($step)) {
            fwrite(STDERR, "--step must be a number.\n");

            return 1;
        }

        try {
            $rolledBack = $this->app->container->get(Migrator::class)->rollback($step === null ? null : (int) $step);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        foreach ($rolledBack as $name) {
            echo "Rolled back: {$name}\n";
        }

        echo $rolledBack === [] ? "Nothing to roll back.\n" : '';

        return 0;
    }

    private function migrateFresh(array $args): int
    {
        if (!$this->app->config('app.debug', false) && !(new Input($args))->flag('force')) {
            fwrite(STDERR, "migrate:fresh drops every table. APP_DEBUG is off (production?): add --force if you mean it.\n");

            return 1;
        }

        echo "Dropped all tables.\n";

        foreach ($this->app->container->get(Migrator::class)->fresh() as $name) {
            echo "Migrated: {$name}\n";
        }

        return (new Input($args))->flag('seed') ? $this->dbSeed([...$args, '--force']) : 0;
    }

    private function dbSeed(array $args): int
    {
        $in = new Input($args);

        if (!$this->app->config('app.debug', false) && !$in->flag('force')) {
            fwrite(STDERR, "db:seed writes to the database. APP_DEBUG is off (production?): add --force if you mean it.\n");

            return 1;
        }

        $name = $in->option('class') ?? 'DatabaseSeeder';
        $class = str_contains($name, '\\') ? $name : "Database\\Seeders\\{$name}";

        if (!class_exists($class)) {
            fwrite(STDERR, "No seeder class {$class} (database/seeders/{$name}.php).\n");

            return 1;
        }

        $seeder = $this->app->container->get($class);

        if (!$seeder instanceof \Pype\Database\Seeder) {
            fwrite(STDERR, "{$class} must extend Pype\\Database\\Seeder.\n");

            return 1;
        }

        $start = microtime(true);
        $seeder->setContainer($this->app->container);
        $seeder($in);
        printf("Seeded: %s (%.1f s)\n", $name, microtime(true) - $start);

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
        return Schedule::load($this->app);
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
            echo 'No tasks in ' . Application::SCHEDULE . ".\n";
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

    private function makeRoutes(array $args): int
    {
        $name = array_values(array_filter($args, static fn (string $a) => !str_starts_with($a, '--')))[0] ?? '';

        if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            fwrite(STDERR, "Give a lowercase name, e.g. make:routes quotes\n");

            return 1;
        }

        $only = array_intersect(['--web', '--api'], $args);
        $kinds = $only === [] ? ['web', 'api'] : array_map(static fn (string $a) => substr($a, 2), $only);
        $code = 0;

        foreach ($kinds as $kind) {
            $relative = "routes/{$name}/{$kind}.php";
            $path = $this->app->path($relative);

            if (file_exists($path)) {
                fwrite(STDERR, "Already exists: {$relative}\n");
                $code = 1;
                continue;
            }

            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $this->routesStub($name, $kind));
            echo "Created {$relative}\n";
        }

        return $code;
    }

    private function routesStub(string $name, string $kind): string
    {
        [$about, $stack, $example] = $kind === 'web'
            ? ["Pages for {$name}. 'web' gives sessions, CSRF and validation errors back to the form.", "'web'", "// \$router->get('/', [LoadThings::class, ThingsView::class])->name('index');   // url('{$name}.index')"]
            : ["JSON for {$name}, served under /api/{$name} (api.php files get the /api prefix and api. names). 'auth' takes a bearer token.", "'auth'", "// \$router->get('/{id}', [LoadThing::class])->name('show');   // url('api.{$name}.show')"];

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Pype\Routing\Router;

            /** @var Router \$router */

            // {$about}
            // Loaded by \$app->routesIn() in bootstrap/app.php; nothing to register.
            \$router->group('/{$name}', [{$stack}], function (Router \$router) {
                {$example}
            }, name: '{$name}.');

            PHP;
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
        file_put_contents("{$dir}/{$file}", $sql ? "-- {$name}\n\n\n-- down\n\n" : $this->migrationStub($name));
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
            $down = <<<PHP
                        \$schema->dropIfExists('{$m[1]}');
                PHP;
        } else {
            $table = preg_match('/_(?:to|from|in)_(\w+?)(?:_table)?$/', $name, $m) ? $m[1] : 'table_name';
            $body = <<<PHP
                        \$schema->table('{$table}', function (Table \$t) {
                            //
                        });
                PHP;
            $down = <<<PHP
                        \$schema->table('{$table}', function (Table \$t) {
                            // undo up(), e.g. \$t->dropColumn('...');
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

                public function down(Schema \$schema): void
                {
            {$down}
                }
            };

            PHP;
    }

    /** make:* for files outside app/ (factories, seeders). */
    private function makeIn(string $dir, string $namespace, ?string $name, string $kind): int
    {
        if ($name === null || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            fwrite(STDERR, "Give a StudlyCase class name, e.g. make:{$kind} User\n");

            return 1;
        }

        $path = "{$this->app->basePath}/{$dir}/{$name}.php";

        if (file_exists($path)) {
            fwrite(STDERR, "Already exists: {$path}\n");

            return 1;
        }

        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $this->stub($kind, $namespace, $name));
        echo "Created {$dir}/{$name}.php\n";

        return 0;
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

    /** A page outlet plus its template: QuoteView → app/Views/QuoteView.php and resources/views/quote.php. */
    private function makeView(array $args): int
    {
        $name = $args[0] ?? null;
        $code = $this->make('Views', $name, 'view');

        if ($code !== 0) {
            return $code;
        }

        $relative = $this->app->config('app.views', 'resources/views') . '/' . $this->viewTemplate((string) $name) . '.php';
        $path = $this->app->path($relative);

        if (!file_exists($path)) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, "<?php\n/** Variables come from {$name}::data(); \$csrf, \$old(), \$error() are always there. */\n?>\n<h1>{$name}</h1>\n");
            echo "Created {$relative}\n";
        }

        return 0;
    }

    /** QuoteView → 'quote', InvoiceListView → 'invoice-list'. */
    private function viewTemplate(string $class): string
    {
        return $this->kebab((string) preg_replace('/View$/', '', $class) ?: $class);
    }

    private function stub(string $kind, string $namespace, string $class): string
    {
        $body = match ($kind) {
            'stage' => <<<PHP
                use Closure;
                use Pype\Http\Request;
                use Pype\Pipeline\Stage;

                /**
                 * Say what it reads from and puts in the request context, so `php bin/pype routes:check`
                 * can check the routes that use it (Pype\Pipeline\Requires / Provides):
                 *   #[Requires(User::class)]
                 *   #[Provides(Thing::class)]
                 */
                final class {$class} implements Stage
                {
                    public function handle(Request \$request, Closure \$next): mixed
                    {
                        return \$next(\$request);
                    }
                }
                PHP,
            'authorize' => <<<PHP
                use App\Data\User;
                use Pype\Auth\Authorize;

                /**
                 * allows() gets what earlier stages put in the context (the user, a record a Load stage
                 * loaded) and route params by name. false stops the route. Services go in a constructor.
                 *
                 *   \$router->patch('/things/{id}', ['auth', LoadThing::class, {$class}::class, ...]);
                 */
                final class {$class} extends Authorize
                {
                    protected int \$status = 403;              // 404 hides that the record exists

                    protected ?string \$message = null;        // default: Forbidden / Not Found

                    protected ?string \$redirectTo = null;     // pages: send browsers here instead (route name or path)

                    public function allows(User \$user): bool
                    {
                        return false;
                    }
                }
                PHP,
            'view' => <<<PHP
                use Pype\Http\Request;
                use Pype\View\ViewOutlet;

                /**
                 * A page. The stages before it load what it shows into the request context; data()
                 * hands that to the template:
                 *
                 *   \$router->get('/things/{id}', [LoadThing::class, {$class}::class]);
                 *
                 * Ask for services in a constructor (they're autowired), add methods the template calls
                 * as \$view->method().
                 */
                final class {$class} extends ViewOutlet
                {
                    protected function template(): string
                    {
                        return '{$this->viewTemplate($class)}';
                    }

                    /** Template variables. \$data is what the last stage returned, often nothing. */
                    protected function data(mixed \$data, Request \$request): array
                    {
                        return [
                            // 'thing' => \$request->context()->get(Thing::class),
                        ];
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
            'factory' => <<<PHP
                use Pype\Database\Fake;
                use Pype\Database\Factory;

                /** {$class}::new(\$db)->create(), ->count(10)->create(), ->make(), ->insert() */
                final class {$class} extends Factory
                {
                    protected string \$table = '{$this->snakePlural(substr($class, 0, -7))}';

                    protected ?string \$class = null;   // e.g. \\App\\Data\\Thing::class to get objects back

                    protected function definition(Fake \$fake): array
                    {
                        return [
                            'name' => \$fake->words(2),
                        ];
                    }
                }
                PHP,
            'seeder' => <<<PHP
                use Pype\Database\Connection;
                use Pype\Database\Seeder;

                /** php bin/pype db:seed --class={$class} */
                final class {$class} extends Seeder
                {
                    public function run(Connection \$db): void
                    {
                        // UserFactory::new(\$db)->count(10)->create();
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

    /** OrderItem -> order_items */
    private function snakePlural(string $class): string
    {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));

        return match (true) {
            str_ends_with($snake, 'y') && !preg_match('/[aeiou]y$/', $snake) => substr($snake, 0, -1) . 'ies',
            preg_match('/(s|x|ch|sh)$/', $snake) === 1 => $snake . 'es',
            default => $snake . 's',
        };
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

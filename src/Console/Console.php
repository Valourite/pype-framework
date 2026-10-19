<?php

declare(strict_types=1);

namespace Pype\Console;

use Closure;
use Pype\Application;
use Pype\Database\Migrator;
use Pype\Pipeline\Invoker;
use Pype\Routing\Router;
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
            'make:stage' => ['Create app/Stages/<Name>.php', fn (array $a) => $this->make('Stages', $a[0] ?? null, 'stage')],
            'make:outlet' => ['Create app/Outlets/<Name>.php', fn (array $a) => $this->make('Outlets', $a[0] ?? null, 'outlet')],
            'make:provider' => ['Create app/Providers/<Name>.php', fn (array $a) => $this->make('Providers', $a[0] ?? null, 'provider')],
            'make:command' => ['Create app/Commands/<Name>.php (register it in a provider)', fn (array $a) => $this->make('Commands', $a[0] ?? null, 'command')],
            'make:migration' => ['Create database/migrations/<timestamp>_<name>.php [--sql for plain SQL]', $this->makeMigration(...)],
            'migrate' => ['Run pending migrations', $this->migrate(...)],
            'migrate:status' => ['Show which migrations have run', $this->migrateStatus(...)],
            'key:generate' => ['Set APP_KEY in .env (for signed URLs) [--show] [--force]', $this->keyGenerate(...)],
            'optimize' => ['Compile the route tables to ' . Application::ROUTE_CACHE . ' (run on deploy)', $this->optimize(...)],
            'optimize:clear' => ['Remove the compiled route tables', $this->optimizeClear(...)],
        ];

        foreach ($app->commands() as $name => [$description, $handler]) {
            $this->commands[$name] = [$description, fn (array $args) => $this->runAppCommand($handler, $args)];
        }
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
        $describe = static fn (mixed $stage): string => match (true) {
            is_string($stage) => (str_contains($stage, '\\') ? substr(strrchr($stage, '\\'), 1) : $stage),
            $stage instanceof Closure => 'Closure',
            default => (new \ReflectionClass($stage))->getShortName(),
        };

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

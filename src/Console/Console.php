<?php

declare(strict_types=1);

namespace Pype\Console;

use Closure;
use Pype\Application;
use Pype\Database\Migrator;

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
            'serve' => ['Start the dev server [--port=8000] [--host=localhost]', $this->serve(...)],
            'routes' => ['List routes and their compiled pipelines', $this->routes(...)],
            'make:stage' => ['Create app/Stages/<Name>.php', fn (array $a) => $this->make('Stages', $a[0] ?? null, 'stage')],
            'make:formatter' => ['Create app/Formatters/<Name>.php', fn (array $a) => $this->make('Formatters', $a[0] ?? null, 'formatter')],
            'make:provider' => ['Create app/Providers/<Name>.php', fn (array $a) => $this->make('Providers', $a[0] ?? null, 'provider')],
            'make:migration' => ['Create database/migrations/<timestamp>_<name>.sql', $this->makeMigration(...)],
            'migrate' => ['Run pending SQL migrations', $this->migrate(...)],
            'migrate:status' => ['Show which migrations have run', $this->migrateStatus(...)],
        ];
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
            printf("  %-16s %s\n", $name, $description);
        }

        return $unknown === null ? 0 : 1;
    }

    private function serve(array $args): int
    {
        $opts = $this->options($args);
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
            $stages = implode(' → ', array_map($describe, $compiled['stages']));
            $formatter = $compiled['formatter'] === null ? '' : '  ⇒ ' . $describe($compiled['formatter']);

            printf("%-7s %-28s %s%s\n", $route->method(), $route->path(), $stages, $formatter);
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

    private function makeMigration(array $args): int
    {
        $name = $args[0] ?? '';

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            fwrite(STDERR, "Give a snake_case name, e.g. make:migration create_posts_table\n");

            return 1;
        }

        $dir = $this->app->path($this->app->config('database.migrations', 'database/migrations'));
        $file = date('Y_m_d_His') . "_{$name}.sql";

        @mkdir($dir, 0777, true);
        file_put_contents("{$dir}/{$file}", "-- {$name}\n\n");
        echo "Created {$file}\n";

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
            'formatter' => <<<PHP
                use Pype\Format\Formatter;
                use Pype\Http\Request;
                use Pype\Http\Response;

                final class {$class} implements Formatter
                {
                    public function format(Response \$response, Request \$request): string
                    {
                        return (string) json_encode(\$response->isError() ? \$response->errorDetails() : \$response->data());
                    }

                    public function contentType(): string
                    {
                        return 'text/plain; charset=utf-8';
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

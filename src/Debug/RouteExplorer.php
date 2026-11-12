<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Application;
use Pype\Auth\Auth;
use Pype\Pipeline\StageSpec;
use Pype\Routing\Route;
use Pype\Routing\RouteCheck;
use Pype\Routing\Router;
use ReflectionClass;
use ReflectionFunction;
use Throwable;
use UnitEnum;

/**
 * What the route screens show: every route, and one route taken apart into its stages in the
 * order they run, with where each came from (a global stage, a named stack, the route), the
 * arguments it was given, what it needs from the context and what it puts there.
 *
 * Reads the same declarations as routes:check (RouteCheck::declarations) and builds nothing:
 * no stage is constructed and no request runs.
 */
final class RouteExplorer
{
    /** Routes of the framework's own screens (named pype.*) are left out of the list. */
    private const OWN = 'pype.';

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @return list<array{method: string, path: string, name: ?string, stages: list<string>, outlet: ?string, problems: int}>
     */
    public function routes(): array
    {
        $problems = $this->problems();
        $rows = [];

        foreach ($this->app->router->routes() as $route) {
            if (str_starts_with((string) $route->name(), self::OWN)) {
                continue;
            }

            $stages = [];
            $outlet = null;

            foreach ($this->app->router->expandStacks($route->stages()) as $stage) {
                if (Router::isOutlet($stage)) {
                    $outlet = Router::describe($stage);   // the last one listed is the one that answers
                } else {
                    $stages[] = Router::describe($stage);
                }
            }

            $rows[] = [
                'method' => $route->method(),
                'path' => $route->path(),
                'name' => $route->name(),
                'stages' => $stages,
                'outlet' => $outlet,
                'problems' => count($problems[$route->method() . ' ' . $route->path()] ?? []),
            ];
        }

        return $rows;
    }

    /**
     * One route in full, or null when there is no such route.
     *
     * 'stages' are in the order they run: the app's global stages, then the route's (its groups'
     * stages first, as written). 'outlets' wrap all of those: they are entered first and act on
     * what comes back, the last one listed answering.
     *
     * @return array{method: string, path: string, name: ?string, params: list<string>, stages: list<array<string, mixed>>, outlets: list<array<string, mixed>>, problems: list<string>, hints: list<string>}|null
     */
    public function route(string $method, string $path): ?array
    {
        $route = $this->find($method, $path);

        if ($route === null) {
            return null;
        }

        $where = $route->method() . ' ' . $route->path();
        $have = [Route::class => 'the application'];   // put in when the route is matched
        $stages = [];
        $outlets = [];

        foreach ([['global', $this->app->globalStages()], ['route', $route->stages()]] as [$origin, $list]) {
            foreach ($this->labelled($list) as [$stage, $stack]) {
                $entry = $this->describe($stage) + ['origin' => $origin, 'stack' => $stack];

                if (Router::isOutlet($stage)) {
                    $outlets[] = $entry;
                } else {
                    $stages[] = $entry;
                }
            }
        }

        // Outlets declare what they read too; they read it once everything else has run.
        foreach ([&$stages, &$outlets] as &$group) {
            foreach ($group as &$entry) {
                $entry['before'] = array_keys($have);
                $entry['needs'] = [];

                foreach ($entry['requires'] as $key) {
                    $key = $this->resolve($key);
                    $entry['needs'][] = ['key' => $key, 'from' => $have[$key] ?? null];
                }

                foreach ($entry['provides'] as $i => $key) {
                    $entry['provides'][$i] = $key = $this->resolve($key);
                    $have[$key] ??= $entry['name'];
                }
            }
            unset($entry);
        }
        unset($group);

        preg_match_all('#\{(\w+)\}#', $route->path(), $params);

        return [
            'method' => $route->method(),
            'path' => $route->path(),
            'name' => $route->name(),
            'params' => $params[1],
            'stages' => $stages,
            'outlets' => $outlets,
            'problems' => $this->problems()[$where] ?? [],
            'hints' => array_values(array_filter(
                (new RouteCheck($this->app))->hints(),
                static fn (string $hint) => str_starts_with($hint, $where . ': '),
            )),
        ];
    }

    private function find(string $method, string $path): ?Route
    {
        foreach ($this->app->router->routes() as $route) {
            if ($route->method() === strtoupper($method) && $route->path() === $path) {
                return $route;
            }
        }

        return null;
    }

    /**
     * routes:check's problems, by "METHOD path", without the prefix.
     *
     * @return array<string, list<string>>
     */
    private function problems(): array
    {
        $byRoute = [];

        foreach ((new RouteCheck($this->app))->problems() as $problem) {
            [$where, $what] = array_pad(explode(': ', $problem, 2), 2, '');
            $byRoute[$where][] = $what;
        }

        return $byRoute;
    }

    /**
     * Stack names replaced by their stages, each stage with the stack it came from (the outermost
     * one written in the routes file), or null when it was written there itself.
     *
     * @param list<string|object> $stages
     * @param list<string> $trail
     * @return list<array{0: string|object, 1: ?string}>
     */
    private function labelled(array $stages, ?string $stack = null, array $trail = []): array
    {
        $stacks = $this->app->router->stacks();
        $out = [];

        foreach ($stages as $stage) {
            if (is_string($stage) && isset($stacks[$stage]) && !in_array($stage, $trail, true)) {
                array_push($out, ...$this->labelled($stacks[$stage], $stack ?? $stage, [...$trail, $stage]));
            } else {
                $out[] = [$stage, $stack];
            }
        }

        return $out;
    }

    /**
     * @return array{name: string, class: ?string, kind: string, args: array<string, string>, file: ?string, line: ?int, requires: list<string>, provides: list<string>, missing: bool}
     */
    private function describe(mixed $stage): array
    {
        $class = match (true) {
            $stage instanceof Closure => null,
            $stage instanceof StageSpec => $stage->class,
            is_string($stage) => $stage,
            is_object($stage) => $stage::class,
            default => null,
        };

        $entry = [
            'name' => Router::describe($stage),
            'class' => $class,
            'kind' => match (true) {
                $stage instanceof Closure => 'closure',
                $stage instanceof StageSpec => 'with',      // X::with(...) or stage(X::class, ...)
                is_string($stage) => 'class',               // X::class
                default => 'instance',                      // new X(...)
            },
            'args' => [],
            'file' => null,
            'line' => null,
            'requires' => [],
            'provides' => [],
            'missing' => false,
        ];

        if ($stage instanceof StageSpec) {
            foreach ($stage->args as $name => $value) {
                $entry['args'][(string) $name] = self::show($value);
            }
        }

        try {
            if ($stage instanceof Closure) {
                $reflection = new ReflectionFunction($stage);
            } elseif ($class !== null && class_exists($class)) {
                $reflection = new ReflectionClass($class);
                [$entry['requires'], $entry['provides']] = RouteCheck::declarations($class);
            } else {
                $entry['missing'] = $class !== null;

                return $entry;
            }

            if (($file = $reflection->getFileName()) !== false) {
                $entry['file'] = $this->relative($file);
                $entry['line'] = $reflection->getStartLine() ?: null;
            }
        } catch (Throwable) {
            // A class that can't be reflected is shown by name alone.
        }

        return $entry;
    }

    /** A file as the app's developer would open it: from the app's folder, the framework's as vendor/pype/framework/... */
    private function relative(string $file): string
    {
        $base = rtrim($this->app->basePath, '/') . '/';
        $framework = dirname(__DIR__, 2) . '/';

        return match (true) {
            str_starts_with($file, $base) => substr($file, strlen($base)),
            str_starts_with($file, $framework) => 'vendor/pype/framework/' . substr($file, strlen($framework)),
            default => $file,
        };
    }

    /** Auth::USER stands for the configured user class (as in RouteCheck). */
    private function resolve(string $key): string
    {
        return $key === Auth::USER ? (string) $this->app->config('auth.user', Auth::USER) : $key;
    }

    /** A stage argument as it would be written: 'quote', 5, true, null, [1, 2], Role::Admin. */
    private static function show(mixed $value): string
    {
        return match (true) {
            $value instanceof UnitEnum => (new ReflectionClass($value))->getShortName() . '::' . $value->name,
            is_array($value) => '[' . implode(', ', array_map(
                static fn ($k, $v) => (is_int($k) ? '' : var_export($k, true) . ' => ') . self::show($v),
                array_keys($value),
                $value,
            )) . ']',
            is_string($value) => mb_strlen($value) > 80 ? var_export(mb_substr($value, 0, 77) . '...', true) : var_export($value, true),
            $value === null => 'null',
            default => var_export($value, true),
        };
    }
}

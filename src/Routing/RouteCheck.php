<?php

declare(strict_types=1);

namespace Pype\Routing;

use Closure;
use Pype\Application;
use Pype\Auth\Auth;
use Pype\Pipeline\DeclaresContext;
use Pype\Pipeline\Provides;
use Pype\Pipeline\Requires;
use Pype\Pipeline\StageSpec;
use ReflectionClass;

/**
 * Checks every route's stages in order, without a request: a stage that #[Requires] a context
 * entry must come after one that #[Provides] it (see `php bin/pype routes:check`).
 *
 * Global stages run first, then the route's own (groups and stacks expanded). Outlets act on the
 * result, after every other stage, so they are checked last whatever their position. Closures and
 * stages without declarations are skipped; a class that doesn't exist is reported.
 */
final class RouteCheck
{
    public function __construct(private readonly Application $app)
    {
    }

    /** @return list<string> one line per problem, empty when every route is in order */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->app->router->routes() as $route) {
            $where = $route->method() . ' ' . $route->path();
            $have = [Route::class => true];   // the application puts the matched route in first
            $outlets = [];

            foreach ($this->app->router->expandStacks([...$this->app->globalStages(), ...$route->stages()]) as $stage) {
                if (Router::isOutlet($stage)) {
                    $outlets[] = $stage;
                    continue;
                }
                $this->walk($stage, $where, $have, $problems);
            }

            foreach ($outlets as $outlet) {
                $this->walk($outlet, $where, $have, $problems);
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Stages written as `new X(...)` in a routes file: built while the routes load and shared by
     * every request a long-running worker serves. Not errors; X::with(...) or X::class avoid both.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        $hints = [];
        $seen = [];

        foreach ($this->app->router->routes() as $route) {
            foreach ($this->app->router->expandStacks($route->stages()) as $stage) {
                if (!is_object($stage) || $stage instanceof Closure || $stage instanceof StageSpec || isset($seen[spl_object_id($stage)])) {
                    continue;
                }
                $seen[spl_object_id($stage)] = true;
                $name = Router::describe($stage);
                $instead = method_exists($stage, 'with') ? "{$name}::with(...)" : "{$name}::class";
                $hints[] = "{$route->method()} {$route->path()}: new {$name}(...) is built while the routes load and shared by every request a worker serves; {$instead} builds it per request.";
            }
        }

        return $hints;
    }

    /**
     * @param array<string, true> $have
     * @param list<string> $problems
     */
    private function walk(mixed $stage, string $where, array &$have, array &$problems): void
    {
        $class = match (true) {
            $stage instanceof Closure => null,
            $stage instanceof StageSpec => $stage->class,
            is_string($stage) => $stage,
            is_object($stage) => $stage::class,
            default => null,
        };

        if ($class === null) {
            return;
        }

        if (!class_exists($class)) {
            $problems[] = "{$where}: [{$class}] is neither a class nor a stack name.";

            return;
        }

        [$requires, $provides] = self::declarations($class);
        $name = Router::describe($class);

        foreach ($requires as $key) {
            $key = $this->resolve($key);
            if (!isset($have[$key])) {
                $problems[] = "{$where}: {$name} needs [{$key}]; nothing before it provides it.";
            }
        }

        foreach ($provides as $key) {
            $have[$this->resolve($key)] = true;
        }
    }

    /** Auth::USER stands for the configured user class (Authenticate puts the user under it). */
    private function resolve(string $key): string
    {
        return $key === Auth::USER ? (string) $this->app->config('auth.user', Auth::USER) : $key;
    }

    /**
     * Requires and provides of a class: DeclaresContext's methods, else the attributes on the
     * class and its parents (a Validate subclass provides what Validate does).
     *
     * @param class-string $class
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function declarations(string $class): array
    {
        if (is_subclass_of($class, DeclaresContext::class)) {
            return [$class::requires(), $class::provides()];
        }

        $requires = [];
        $provides = [];

        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getAttributes(Requires::class) as $attribute) {
                array_push($requires, ...$attribute->newInstance()->keys);
            }
            foreach ($reflection->getAttributes(Provides::class) as $attribute) {
                array_push($provides, ...$attribute->newInstance()->keys);
            }
        }

        return [array_values(array_unique($requires)), array_values(array_unique($provides))];
    }
}

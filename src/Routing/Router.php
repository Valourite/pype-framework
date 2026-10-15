<?php

declare(strict_types=1);

namespace Pype\Routing;

use Closure;
use LogicException;
use Pype\Exceptions\HttpException;
use Pype\Outlet\Outlet;
use Pype\Pipeline\Stage;

/**
 * Registers routes and resolves an incoming method + path to a Route.
 *
 *   $router->get('/users/{id}', [Authenticate::class, LoadUser::class, ShowUser::class]);
 *
 *   $router->group('/admin', [Authenticate::class, RequireAdmin::class], function (Router $r) {
 *       $r->get('/stats', ShowStats::class);
 *   });
 *
 *   $router->stack('authed', [Authenticate::class, LoadAccount::class]);
 *   $router->get('/billing', ['authed', ShowBilling::class]);
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var list<array{prefix: string, stages: array}> */
    private array $groupStack = [];

    /** @var array<string, list<string|Stage|Closure>> */
    private array $stacks = [];

    /**
     * Name a reusable sequence of stages. Use the name anywhere a stage is accepted.
     * Stacks may reference other stacks, and may be defined before or after use.
     *
     * @param list<string|object> $stages
     */
    public function stack(string $name, array $stages): void
    {
        $this->stacks[$name] = $stages;
    }

    /**
     * Expand stack names and move outlets to the front, keeping their relative order.
     *
     * Outlets act on the pipeline's result, so they must wrap every other stage. Placed
     * first, the innermost (last listed) outlet claims the response before the outer ones
     * see it — so a route's outlet beats its group's, which beats a global one.
     *
     * @param list<string|object> $stages
     * @return list<string|object>
     */
    public function compile(array $stages): array
    {
        $outlets = [];
        $rest = [];

        foreach ($this->expand($stages) as $stage) {
            if (self::isOutlet($stage)) {
                $outlets[] = $stage;
            } else {
                $rest[] = $stage;
            }
        }

        return [...$outlets, ...$rest];
    }

    public static function isOutlet(mixed $stage): bool
    {
        return $stage instanceof Outlet || (is_string($stage) && is_subclass_of($stage, Outlet::class));
    }

    /** @param list<string> $trail stack names being expanded, for cycle detection */
    private function expand(array $stages, array $trail = []): array
    {
        $out = [];

        foreach ($stages as $stage) {
            if (!is_string($stage) || !isset($this->stacks[$stage])) {
                $out[] = $stage;
                continue;
            }

            if (in_array($stage, $trail, true)) {
                throw new LogicException('Circular stage stack: ' . implode(' -> ', [...$trail, $stage]));
            }

            array_push($out, ...$this->expand($this->stacks[$stage], [...$trail, $stage]));
        }

        return $out;
    }

    /** @param class-string<Stage>|Stage|Closure|array $stages */
    public function get(string $path, string|object|array $stages): Route
    {
        return $this->add('GET', $path, $stages);
    }

    public function post(string $path, string|object|array $stages): Route
    {
        return $this->add('POST', $path, $stages);
    }

    public function put(string $path, string|object|array $stages): Route
    {
        return $this->add('PUT', $path, $stages);
    }

    public function patch(string $path, string|object|array $stages): Route
    {
        return $this->add('PATCH', $path, $stages);
    }

    public function delete(string $path, string|object|array $stages): Route
    {
        return $this->add('DELETE', $path, $stages);
    }

    public function add(string $method, string $path, string|object|array $stages): Route
    {
        $prefix = '';
        $groupStages = [];

        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            array_push($groupStages, ...$group['stages']);
        }

        $route = new Route(
            strtoupper($method),
            $this->normalize($prefix . '/' . ltrim($path, '/')),
            [...$groupStages, ...(is_array($stages) ? $stages : [$stages])],
        );

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Share a path prefix and a set of stages across several routes.
     * Group stages run before each route's own stages. Groups can nest.
     */
    public function group(string $prefix, array $stages, Closure $routes): void
    {
        $this->groupStack[] = ['prefix' => $this->normalize($prefix), 'stages' => $stages];

        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * @return array{0: Route, 1: array<string, string>}
     * @throws HttpException 404 / 405
     */
    public function resolve(string $method, string $path): array
    {
        $path = $this->normalize($path);
        $allowed = [];

        foreach ($this->routes as $route) {
            $params = $route->match($path);

            if ($params === null) {
                continue;
            }

            if ($route->method() === $method || ($method === 'HEAD' && $route->method() === 'GET')) {
                return [$route, $params];
            }

            $allowed[] = $route->method();
        }

        if ($allowed !== []) {
            throw HttpException::methodNotAllowed(array_values(array_unique($allowed)));
        }

        throw HttpException::notFound("No route for {$method} {$path}");
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}

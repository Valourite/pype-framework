<?php

declare(strict_types=1);

namespace Pype\Routing;

use Closure;
use LogicException;
use Pype\Exceptions\HttpException;
use Pype\Format\Formatter;
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
     * @param list<string|Stage|Closure|Formatter> $stages
     */
    public function stack(string $name, array $stages): void
    {
        $this->stacks[$name] = $stages;
    }

    /**
     * Expand stack names and lift out any Formatter (last one wins, so a route's
     * formatter beats its group's, which beats the global one).
     *
     * @param list<string|Stage|Closure|Formatter> $stages
     * @return array{stages: list<string|Stage|Closure>, formatter: string|Formatter|null}
     */
    public function compile(array $stages): array
    {
        $compiled = [];
        $formatter = null;

        foreach ($this->expand($stages) as $stage) {
            if ($stage instanceof Formatter || (is_string($stage) && is_subclass_of($stage, Formatter::class))) {
                $formatter = $stage;
            } else {
                $compiled[] = $stage;
            }
        }

        return ['stages' => $compiled, 'formatter' => $formatter];
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

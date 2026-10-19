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

    /** @var list<array{prefix: string, stages: array, name: string}> */
    private array $groupStack = [];

    /** @var array<string, Route>|null name => route, built on first lookup by name */
    private ?array $named = null;

    /** @var array<string, list<string|Stage|Closure>> */
    private array $stacks = [];

    /**
     * Lookup tables built from $routes on first use (dropped when routes are added): static
     * paths in a hash, paths with {params} in combined regexes. Each half is built only when
     * a request needs it, or read from the file `bin/pype optimize` writes.
     */
    private ?array $static = null;
    private ?array $dynamic = null;

    private ?string $cacheFile = null;

    /** Lookups since the routes last changed; tells a one-request process (PHP-FPM) from a long-running one. */
    private int $resolves = 0;

    /** "METHOD path" of every route, one per line, built as routes are added (for signature()). */
    private string $fingerprint = '';

    /** Dynamic patterns per combined regex; keeps each regex well inside PCRE's limits. */
    private const CHUNK = 50;

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
        $namePrefix = '';
        $groupStages = [];

        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $namePrefix .= $group['name'];
            array_push($groupStages, ...$group['stages']);
        }

        $route = new Route(
            strtoupper($method),
            $this->normalize($prefix . '/' . ltrim($path, '/')),
            [...$groupStages, ...(is_array($stages) ? $stages : [$stages])],
            $namePrefix,
        );

        $this->routes[] = $route;
        $this->resolves = 0;
        $this->fingerprint .= $route->method() . ' ' . $route->path() . "\n";
        $this->static = $this->dynamic = $this->named = null;

        return $route;
    }

    /**
     * Share a path prefix and a set of stages across several routes.
     * Group stages run before each route's own stages. Groups can nest.
     * $name prefixes the names of routes inside: name: 'admin.' turns ->name('users') into 'admin.users'.
     */
    public function group(string $prefix, array $stages, Closure $routes, string $name = ''): void
    {
        $this->groupStack[] = ['prefix' => $this->normalize($prefix), 'stages' => $stages, 'name' => $name];

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
        $lookup = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];
        $this->static ?? $this->load();

        // Fast path: a static path, or the first dynamic pattern that matches, has this method.
        if (isset($this->static[$path])) {
            foreach ($lookup as $verb) {
                if (isset($this->static[$path][$verb])) {
                    return [$this->routes[$this->static[$path][$verb]], []];
                }
            }
        } elseif ($this->dynamic === null && $this->resolves++ === 0) {
            // Building the combined regexes costs more than one plain scan, so it only pays
            // off for a second lookup: PHP-FPM (one request per Router) scans unless
            // `optimize` cached the tables; a long-running server compiles once and reuses.
            return $this->scan($method, $path);
        } else {
            $this->dynamic ??= $this->compileDynamic();

            foreach ($this->dynamic['regexes'] as $regex) {
                if (!preg_match($regex, $path, $matches)) {
                    continue;
                }

                $pattern = $this->dynamic['patterns'][$matches['MARK']];

                if ($pattern['overlaps']) {
                    break;   // another pattern could match this path too: registration order decides
                }

                foreach ($lookup as $verb) {
                    if (isset($pattern['routes'][$verb])) {
                        $values = array_slice($matches, 1, count($pattern['params'][$verb]));
                        unset($values['MARK']);

                        return [$this->routes[$pattern['routes'][$verb]], array_combine($pattern['params'][$verb], array_values($values))];
                    }
                }
                break;
            }
        }

        // Rare cases (wrong method, overlapping patterns): the plain scan decides, in
        // registration order, exactly as before.
        return $this->scan($method, $path);
    }

    /** @return array{0: Route, 1: array<string, string>} */
    private function scan(string $method, string $path): array
    {
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

    /**
     * Read compiled tables from the optimize cache when they still describe these routes,
     * else build the static table (cheap; the dynamic one waits until a request needs it).
     */
    private function load(): void
    {
        if ($this->cacheFile !== null && is_file($this->cacheFile)) {
            $cached = require $this->cacheFile;

            if (is_array($cached) && ($cached['signature'] ?? null) === $this->signature()) {
                $this->static = $cached['static'];
                $this->dynamic = $cached['dynamic'];

                return;
            }
        }

        $this->static = $this->compileStatic();
    }

    /** @return array<string, array<string, int>> path => method => route index */
    private function compileStatic(): array
    {
        $static = [];

        foreach ($this->routes as $i => $route) {
            if (!str_contains($route->path(), '{')) {
                $static[$route->path()][$route->method()] ??= $i;
            }
        }

        return $static;
    }

    /**
     * Paths with {params} become one regex per CHUNK patterns, each alternative tagged with
     * (*MARK:n) so a single preg_match says which pattern matched. (?|...) restarts group
     * numbers in every alternative, so the params are always groups 1..k. Routes that differ
     * only in param names share a pattern.
     *
     * @return array{regexes: list<string>, patterns: array<string, array{params: array<string, list<string>>, routes: array<string, int>, overlaps: bool}>}
     */
    private function compileDynamic(): array
    {
        $patterns = [];
        $sources = [];
        $keys = [];

        foreach ($this->routes as $i => $route) {
            $path = $route->path();

            if (!str_contains($path, '{')) {
                continue;
            }

            preg_match_all('#\{(\w+)\}#', $path, $names);
            $shape = preg_replace('#\{\w+\}#', '{}', $path);

            if (!isset($keys[$shape])) {
                $keys[$shape] = (string) count($keys);
                $sources[$keys[$shape]] = str_replace('\\{\\}', '([^/]+)', preg_quote($shape, '#'));
                $patterns[$keys[$shape]] = ['params' => [], 'routes' => [], 'overlaps' => false];
            }

            $id = $keys[$shape];
            if (!isset($patterns[$id]['routes'][$route->method()])) {
                $patterns[$id]['routes'][$route->method()] = $i;
                $patterns[$id]['params'][$route->method()] = $names[1];
            }
        }

        // Shapes that can match the same path (/a/{} and /{}/b both match /a/b) are left to the
        // plain scan, so the answer is always the registration-order one, compiled or not.
        $bySegments = [];
        foreach ($keys as $shape => $id) {
            $bySegments[substr_count($shape, '/')][$id] = explode('/', $shape);
        }
        foreach ($bySegments as $group) {
            foreach (self::overlapping($group, 1) as $id) {
                $patterns[$id]['overlaps'] = true;
            }
        }

        $regexes = [];
        foreach (array_chunk($sources, self::CHUNK, true) as $chunk) {
            $alternatives = [];
            foreach ($chunk as $id => $source) {
                $alternatives[] = $source . '(*MARK:' . $id . ')';
            }
            $regexes[] = '#^(?|' . implode('|', $alternatives) . ')$#';
        }

        return ['regexes' => $regexes, 'patterns' => $patterns];
    }

    /**
     * Ids of shapes (same segment count) that can match a common path. Splits segment by
     * segment: shapes with different fixed words at a position can't overlap, a {} at that
     * position can overlap with any of them. Near-linear for real route sets.
     *
     * @param array<string, list<string>> $shapes id => segments
     * @return list<string>
     */
    private static function overlapping(array $shapes, int $position): array
    {
        if (count($shapes) < 2) {
            return [];
        }

        if ($position >= count(reset($shapes))) {
            return array_map('strval', array_keys($shapes));   // identical shapes can't happen; these all overlap
        }

        $fixed = [];
        $wild = [];
        foreach ($shapes as $id => $segments) {
            if ($segments[$position] === '{}') {
                $wild[$id] = $segments;
            } else {
                $fixed[$segments[$position]][$id] = $segments;
            }
        }

        $found = [];
        foreach ($fixed as $group) {
            array_push($found, ...self::overlapping($group + $wild, $position + 1));
        }
        if ($fixed === []) {
            array_push($found, ...self::overlapping($wild, $position + 1));
        }

        return array_values(array_unique($found));
    }

    /** Identifies the route list (methods and paths, in order) the compiled tables belong to. */
    private function signature(): string
    {
        return hash('xxh128', $this->fingerprint);
    }

    /**
     * Use the route tables `bin/pype optimize` wrote to $file. Ignored when missing or when
     * the routes have changed since, so a stale cache is never wrong, only slower.
     */
    public function cacheFrom(string $file): void
    {
        $this->cacheFile = $file;
        $this->static = $this->dynamic = null;
    }

    /** Build both tables now (a long-running server calls this once before forking workers). */
    public function warm(): void
    {
        $this->static ?? $this->load();
        $this->dynamic ??= $this->compileDynamic();
    }

    /** Fully compiled tables, for `bin/pype optimize` to write out. */
    public function compiled(): array
    {
        return ['signature' => $this->signature(), 'static' => $this->compileStatic(), 'dynamic' => $this->compileDynamic()];
    }

    /** The route with this name, or null. */
    public function named(string $name): ?Route
    {
        // Names are set after add() (->name() is chained), so a miss rebuilds the index once.
        if (!isset($this->named[$name])) {
            $this->named = [];
            foreach ($this->routes as $route) {
                $routeName = $route->name();
                if ($routeName === null) {
                    continue;
                }
                if (isset($this->named[$routeName])) {
                    throw new LogicException("Two routes are named [{$routeName}]: {$this->named[$routeName]->method()} {$this->named[$routeName]->path()} and {$route->method()} {$route->path()}.");
                }
                $this->named[$routeName] = $route;
            }
        }

        return $this->named[$name] ?? null;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    private function normalize(string $path): string
    {
        if (str_contains($path, '//')) {
            $path = (string) preg_replace('#/+#', '/', $path);
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}

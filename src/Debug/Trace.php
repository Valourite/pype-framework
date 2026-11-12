<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Exceptions\HttpException;
use Pype\Http\AfterResponse;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\StageSpec;
use Pype\Routing\Route;
use Pype\Routing\Router;
use Throwable;

/**
 * What one request did, stage by stage: kept in that request's context while it runs (so two
 * requests never share one), then written out by the request recorder.
 *
 * Stages nest (each calls the next), so the ones still running form a stack; a query belongs to
 * the innermost of them.
 */
final class Trace
{
    private const MAX_QUERIES = 300;
    private const MAX_TEXT = 500;
    private const SECRET_SQL = '/pass|token|secret|payload|session/i';

    /** @var list<array<string, mixed>> every stage entered, in order */
    private array $stages = [];

    /** @var list<int> indexes into $stages of the ones still running, outermost first */
    private array $open = [];

    /** @var list<array<string, mixed>> queries run outside any recorded stage */
    private array $loose = [];

    private int $queries = 0;
    private float $queryMs = 0.0;

    /** The request as the innermost stage got it: a stage may hand on a copy, and only that one has the route's parameters. */
    private ?Request $request = null;

    private ?Response $response = null;

    public function __construct(private readonly float $started)
    {
    }

    /** No stage is running: the request is through, or hasn't begun. */
    public function idle(): bool
    {
        return $this->open === [];
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    /** What the outermost stage handed back: the response, before its outlet renders it. */
    public function answered(?Response $response): void
    {
        $this->response = $response ?? $this->response;
    }

    public function enter(mixed $stage, Request $request): void
    {
        $now = hrtime(true);
        $this->request = $request;
        $classes = $request->context()->classes();

        if ($this->open !== []) {
            $parent = &$this->stages[end($this->open)];
            $parent['calledNext'] = true;
            $parent['put'] ??= array_values(array_diff($classes, $parent['had']));   // what it put before handing on

            if ($parent['request'] !== spl_object_id($request)) {
                $parent['replacedRequest'] = true;
            }
            unset($parent);
        }

        $this->open[] = count($this->stages);
        $this->stages[] = [
            'name' => Router::describe($stage),
            'class' => match (true) {
                $stage instanceof Closure => null,
                $stage instanceof StageSpec => $stage->class,
                is_string($stage) => $stage,
                default => $stage::class,
            },
            'kind' => match (true) {
                Router::isOutlet($stage) => 'outlet',
                $request->context()->has(Route::class) => 'route',
                default => 'global',
            },
            'inRoute' => $request->context()->has(Route::class),   // put there when the route is matched
            'depth' => count($this->open) - 1,
            'at' => ($now - $this->started) / 1e6,
            'ms' => null,
            'inner' => 0.0,
            'calledNext' => false,
            'replacedRequest' => false,
            'had' => $classes,
            'put' => null,
            'request' => spl_object_id($request),
            'status' => null,
            'location' => null,
            'exception' => null,
            'queries' => [],
            'started' => $now,
        ];
    }

    public function threw(Throwable $e): void
    {
        if ($this->open !== []) {
            $this->stages[end($this->open)]['exception'] = ['http' => $e instanceof HttpException, 'class' => $e::class, 'message' => self::cut($e->getMessage()), 'file' => $e->getFile(), 'line' => $e->getLine()];
        }
    }

    public function leave(Request $request, mixed $result): void
    {
        if ($this->open === []) {
            return;
        }

        $index = array_pop($this->open);
        $stage = &$this->stages[$index];
        $stage['ms'] = (hrtime(true) - $stage['started']) / 1e6;
        $stage['put'] ??= array_values(array_diff($request->context()->classes(), $stage['had']));

        if ($result instanceof Response) {
            $stage['status'] = $result->status();
            $stage['location'] = $result->isRedirect() ? $result->header('Location') : null;
        }

        if ($this->open !== []) {
            $this->stages[end($this->open)]['inner'] += $stage['ms'];
        }
    }

    /** How many of the route's own stages (its outlets too) were entered: the rest never ran. */
    public function enteredInRoute(): int
    {
        return count(array_filter($this->stages, static fn (array $stage) => $stage['inRoute']));
    }

    /** @param array<int|string, mixed> $bindings */
    public function query(string $sql, array $bindings, float $ms): void
    {
        $this->queries++;
        $this->queryMs += $ms;

        if ($this->queries > self::MAX_QUERIES) {
            return;
        }

        // A query that names a secret (a session's payload, a password, a token) keeps its SQL but not its values.
        $query = ['sql' => self::cut($sql, 2000), 'ms' => $ms, 'bindings' => preg_match(self::SECRET_SQL, $sql) === 1
            ? ($bindings === [] ? [] : ['•••• hidden'])
            : array_values(array_map(self::show(...), array_slice($bindings, 0, 30, true)))];

        if ($this->open === []) {
            $this->loose[] = $query;
        } else {
            $this->stages[end($this->open)]['queries'][] = $query;
        }
    }

    /**
     * The finished record, ready to be stored as JSON.
     *
     * @param list<string> $unreached names of the stages that never ran (a stage before them answered)
     * @return array<string, mixed>
     */
    public function finish(array $unreached): array
    {
        $request = $this->request ?? Request::create('GET', '/');
        $response = $this->response;
        $route = $request->context()->find(Route::class);
        $stages = [];

        foreach ($this->stages as $stage) {
            $total = $stage['ms'] ?? 0.0;
            $stages[] = [
                'name' => $stage['name'],
                'class' => $stage['class'],
                'kind' => $stage['kind'],
                'depth' => $stage['depth'],
                'at' => round($stage['at'], 3),
                'ms' => round($total, 3),
                'own' => round(max(0.0, $total - $stage['inner']), 3),
                'calledNext' => $stage['calledNext'],
                'replacedRequest' => $stage['replacedRequest'],
                'put' => array_values(array_diff($stage['put'] ?? [], [AfterResponse::class, self::class, Route::class])),   // the application puts the route in, not a stage
                'status' => $stage['status'],
                'location' => $stage['location'],
                'exception' => $stage['exception'],
                'queries' => array_map(static fn (array $q) => ['ms' => round($q['ms'], 3)] + $q, $stage['queries']),
            ];
        }

        return [
            'at' => date(DATE_ATOM),
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => Redact::fields((array) $request->query()),
            'input' => $request->method() === 'GET' ? [] : Redact::fields((array) $request->input()),
            'headers' => Redact::headers($request->headers()),
            'ip' => $request->ip(),
            'route' => $route === null ? null : ['method' => $route->method(), 'path' => $route->path(), 'name' => $route->name(), 'params' => $request->params()],
            'status' => $response?->status(),
            'location' => $response?->isRedirect() ? $response->header('Location') : null,
            'ms' => round((hrtime(true) - $this->started) / 1e6, 3),
            'queries' => $this->queries,
            'queryMs' => round($this->queryMs, 3),
            'context' => array_values(array_diff($request->context()->classes(), [self::class])),
            'stages' => $stages,
            'unreached' => $unreached,
            'loose' => $this->loose,
        ];
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "'" . self::cut($value, 80) . "'",
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }

    private static function cut(string $text, int $max = self::MAX_TEXT): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}

<?php

declare(strict_types=1);

namespace Pype\Testing;

use Pype\Application;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Pipeline;

/**
 * Runs a list of stages the way a route would, without HTTP, routing or the error page, so a
 * test can check one stage or a few in a row:
 *
 *   $result = Chain::of([LoadQuote::class, PrepareQuote::class], $this->app)
 *       ->with($user)                         // what earlier stages would have put in the context
 *       ->params(['id' => 7])                 // route params
 *       ->run('GET /quotes/7');               // or a Request
 *
 *   $result->context()->get(Quote::class);    // what the stages put there
 *   $result->reachedEnd();                    // every stage called $next
 *   $result->response()->status();            // what came back (a stage may stop with redirect())
 *
 * Stages are built as in a route: class-strings and stage() specs through the container (pass
 * your app so its services are there), stacks expanded, outlets moved to the front. Exceptions
 * are not turned into error responses: they reach the test, so expectException() works. For the
 * whole request (sessions, CSRF, error pages) use TestClient instead.
 */
final class Chain
{
    /** @var list<array{0: object, 1: string|null}> */
    private array $context = [];

    /** @var array<string, string> */
    private array $params = [];

    /** @param list<string|object> $stages */
    private function __construct(private readonly array $stages, private readonly Application $app)
    {
    }

    /**
     * @param list<string|object> $stages
     * @param Application|null $app  for its container (services, config) and stacks; a bare one if null
     */
    public static function of(array $stages, ?Application $app = null): self
    {
        return new self($stages, $app ?? new Application(sys_get_temp_dir()));
    }

    /** Put something in the context before the first stage runs (as put() would: keyed by its class, or $as). */
    public function with(object $item, ?string $as = null): self
    {
        $clone = clone $this;
        $clone->context[] = [$item, $as];

        return $clone;
    }

    /** @param array<string, scalar> $params  route params, as {id} in the path would give */
    public function params(array $params): self
    {
        $clone = clone $this;
        $clone->params = [...$this->params, ...array_map('strval', $params)];

        return $clone;
    }

    /** @param Request|string $request  a Request, or 'METHOD /path?query' */
    public function run(Request|string $request = 'GET /'): ChainResult
    {
        if (is_string($request)) {
            [$method, $uri] = array_pad(explode(' ', trim($request), 2), 2, '/');
            $request = Request::create($method, $uri);
        }

        if ($this->params !== []) {
            $request->setParams([...$request->params(), ...$this->params]);
        }

        foreach ($this->context as [$item, $as]) {
            $request->context()->put($item, $as);
        }

        $reachedEnd = false;
        $response = (new Pipeline($this->app->container))
            ->mapResult(Response::from(...))
            ->send($request)
            ->through($this->app->router->compile($this->stages))
            ->then(function () use (&$reachedEnd) {
                $reachedEnd = true;

                return null;
            });

        return new ChainResult($request, $response, $reachedEnd);
    }
}

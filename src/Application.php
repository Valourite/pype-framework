<?php

declare(strict_types=1);

namespace Pype;

use Closure;
use Psr\Container\ContainerInterface;
use Pype\Container\Container;
use Pype\Container\ContainerAware;
use Pype\Debug\ErrorPage;
use Pype\Exceptions\ErrorLogReporter;
use Pype\Exceptions\HttpException;
use Pype\Exceptions\Reporter;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Outlet\JsonOutlet;
use Pype\Outlet\Outlet;
use Pype\Pipeline\Pipeline;
use Pype\Routing\Router;
use Pype\Routing\Urls;
use Throwable;

/**
 * The request lifecycle:
 *
 *   Request ─▶ [global stages] ─▶ route lookup ─▶ [group + route stages] ─▶ Response ─▶ Outlet ─▶ bytes
 *
 * Inside every pipeline:
 *   - each stage's return value is normalised with Response::from(), so $next() always returns a Response
 *   - each stage's exceptions are rendered into an error Response, so outer stages still run their "after" logic
 */
final class Application
{
    /** Where `bin/pype optimize` writes the compiled route tables. */
    public const ROUTE_CACHE = 'bootstrap/cache/routes.php';

    public readonly Container $container;
    public readonly Router $router;

    /** @var list<string|object> stages that run for every request */
    private array $globalStages = [];

    private string|Outlet $outlet = JsonOutlet::class;

    /** @var list<class-string<Reporter>|Reporter|Closure> */
    private array $reporters = [];

    /** @var list<array{0: class-string<Throwable>, 1: Closure}> */
    private array $renderers = [];

    /** @var array<string, array{0: string, 1: string|Closure}> */
    private array $commands = [];

    /**
     * @param array<string, mixed> $config  keyed by file name: ['app' => [...], 'database' => [...]]
     */
    public function __construct(
        public readonly string $basePath,
        private readonly array $config = [],
    ) {
        $this->container = new Container();
        $this->router = new Router();

        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->router->cacheFrom($this->path(self::ROUTE_CACHE));   // read lazily, only if `optimize` wrote it

        // Links to named routes: url(), signed_url(), or inject Urls.
        $urls = new Urls($this->router, (string) $this->config('app.url', ''), Urls::decodeKey((string) $this->config('app.key', '')));
        $this->container->instance(Urls::class, $urls);
        Urls::setCurrent($urls);

        foreach ($this->config('app.providers', []) as $provider) {
            $this->register($provider);
        }
    }

    /** Load every config/*.php file into an array keyed by file name. */
    public static function loadConfig(string $directory): array
    {
        $config = [];

        foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
            $config[basename($file, '.php')] = require $file;
        }

        return $config;
    }

    /** Read config with dot notation: config('database.connections.sqlite.path'). */
    public function config(string $key, mixed $default = null): mixed
    {
        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** Absolute path inside the app: $app->path('resources/views'). */
    public function path(string $relative = ''): string
    {
        return rtrim($this->basePath . '/' . ltrim($relative, '/'), '/');
    }

    /** @param class-string<Provider>|Provider $provider */
    public function register(string|Provider $provider): self
    {
        $provider = is_string($provider) ? $this->container->make($provider) : $provider;
        $provider->register($this);

        return $this;
    }

    /** Register stages that wrap every route (CORS, request IDs, logging, ...). */
    public function through(string|object ...$stages): self
    {
        array_push($this->globalStages, ...$stages);

        return $this;
    }

    /** Name a reusable sequence of stages; use the name in any stage list. */
    public function stack(string $name, array $stages): self
    {
        $this->router->stack($name, $stages);

        return $this;
    }

    /** Set the app-wide default outlet (JsonOutlet unless changed). */
    public function outlet(string|Outlet $outlet): self
    {
        $this->outlet = $outlet;

        return $this;
    }

    /**
     * Add an error reporter. Errors that end up as a 5xx go to every registered
     * reporter; with none registered, they go to error_log().
     *
     * @param class-string<Reporter>|Reporter|Closure(Throwable, Request): void $reporter
     */
    public function reportUsing(string|Reporter|Closure $reporter): self
    {
        $this->reporters[] = $reporter;

        return $this;
    }

    /**
     * Decide how an exception type becomes a response. First matching renderer wins;
     * returning null falls through to the next one (and finally the default).
     *
     *   $app->renderUsing(DomainException::class, fn (DomainException $e) => Response::error(409, $e->getMessage()));
     *
     * @template T of Throwable
     * @param class-string<T> $type
     * @param Closure(T, Request): mixed $renderer
     */
    public function renderUsing(string $type, Closure $renderer): self
    {
        $this->renderers[] = [$type, $renderer];

        return $this;
    }

    /**
     * Add a `php bin/pype <name>` command. The handler is a closure or an invokable class,
     * with parameters injected like a stage's: Pype\Console\Input for the arguments, plus
     * any service. Return an exit code (null = 0).
     *
     *   $app->command('expenses:recur', 'Create due recurring expenses', RecurExpenses::class);
     *
     * @param class-string|Closure $handler
     */
    public function command(string $name, string $description, string|Closure $handler): self
    {
        $this->commands[$name] = [$description, $handler];

        return $this;
    }

    /** @return array<string, array{0: string, 1: string|Closure}> */
    public function commands(): array
    {
        return $this->commands;
    }

    /** Load a routes file; the file receives $router (and $app) in scope. */
    public function routes(string $file): self
    {
        (static function (Router $router, Application $app, string $__file): void {
            require $__file;
        })($this->router, $this, $file);

        return $this;
    }

    public function handle(Request $request): Response
    {
        $this->container->instance(Request::class, $request);

        $response = $this->pipeline()
            ->send($request)
            ->through($this->router->compile($this->globalStages))
            ->then(fn (Request $request) => $this->dispatch($request));

        // No route/group/global outlet claimed it (or the route was never reached): use the default.
        if ($response->outlet() === null) {
            $response = $response->withOutlet($this->resolveOutlet($this->outlet));
        }

        // Render now, so a failing outlet/template is still reported and answered with a 500.
        try {
            $response->render($request);
        } catch (Throwable $e) {
            $this->report($e, $request);
            $response = $this->debugPage($e, $request) ?? $this->defaultRender($e)->withOutlet(new JsonOutlet());
        }

        // Don't hold on to the request: in a long-running server the next one replaces it,
        // and until then it would keep its body and upload temp files alive.
        $this->container->forget(Request::class);

        return $response;
    }

    public function run(): void
    {
        $request = Request::fromGlobals();

        $this->handle($request)->send($request);
    }

    private function dispatch(Request $request): Response
    {
        [$route, $params] = $this->router->resolve($request->method(), $request->path());

        $request->setParams($params);
        $request->context()->put($route);   // for the request log and the error page

        return $this->pipeline()
            ->send($request)
            ->through($this->router->compile($route->stages()))
            ->then(static fn () => null);
    }

    private function pipeline(): Pipeline
    {
        return (new Pipeline($this->container))
            ->mapResult(Response::from(...))
            ->onException(fn (Throwable $e, Request $request) => $this->renderException($e, $request));
    }

    private function resolveOutlet(string|Outlet $outlet): Outlet
    {
        $outlet = is_string($outlet) ? $this->container->make($outlet) : $outlet;

        if ($outlet instanceof ContainerAware) {
            $outlet->setContainer($this->container);
        }

        return $outlet;
    }

    private function renderException(Throwable $e, Request $request): Response
    {
        $custom = $this->customRender($e, $request);
        $response = $custom ?? $this->defaultRender($e);

        if ($response->status() >= 500) {
            $this->report($e, $request);

            // Debug mode: browsers get the error page instead of the route's error view.
            if ($custom === null && ($page = $this->debugPage($e, $request)) !== null) {
                return $page;
            }
        }

        return $response;
    }

    /** The development error page, for unexpected errors in debug mode when a browser asked. */
    private function debugPage(Throwable $e, Request $request): ?Response
    {
        // Only for browsers (Accept: text/html): API clients and curl keep getting JSON.
        if (!$this->config('app.debug') || $e instanceof HttpException || !str_contains((string) $request->header('accept'), 'text/html')) {
            return null;
        }

        return Response::error(500, $e->getMessage())->withOutlet(new ErrorPage($e, $this));
    }

    private function customRender(Throwable $e, Request $request): ?Response
    {
        foreach ($this->renderers as [$type, $renderer]) {
            if (!$e instanceof $type) {
                continue;
            }

            try {
                $result = $renderer($e, $request);
            } catch (Throwable $failure) {
                $this->report($failure, $request);

                return null;
            }

            if ($result !== null) {
                return Response::from($result);
            }
        }

        return null;
    }

    private function defaultRender(Throwable $e): Response
    {
        if ($e instanceof HttpException) {
            $response = Response::error($e->status(), $e->getMessage(), $e->details());

            foreach ($e->headers() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        }

        if (!$this->config('app.debug')) {
            return Response::error(500, 'Server Error');
        }

        return Response::error(500, $e->getMessage(), [
            'exception' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
        ]);
    }

    private function report(Throwable $e, Request $request): void
    {
        foreach ($this->reporters ?: [ErrorLogReporter::class] as $reporter) {
            try {
                if (is_string($reporter)) {
                    $reporter = $this->container->make($reporter);
                }

                $reporter instanceof Reporter ? $reporter->report($e, $request) : $reporter($e, $request);
            } catch (Throwable $failure) {
                // A broken reporter must never take the response down with it.
                error_log('[pype] reporter failed: ' . $failure->getMessage() . ' while reporting: ' . $e);
            }
        }
    }
}

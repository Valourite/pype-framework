<?php

declare(strict_types=1);

namespace Pype\Log;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Pype\Container\ContainerAware;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;
use Pype\Routing\Route;

/**
 * One log line per request: "GET /expenses 200 3.1 ms", at info level (warning for 4xx, error
 * for 5xx), with the route name. Registered globally by LogProvider when log.requests is on.
 */
final class LogRequests implements Stage, ContainerAware
{
    use WithArguments;

    private ?LoggerInterface $log = null;

    /** @param list<string> $except path prefixes not to log (the log viewer itself) */
    public function __construct(private readonly array $except = [])
    {
    }

    /** Whatever LoggerInterface is bound, so request lines go wherever the app's logs go. */
    public function setContainer(ContainerInterface $container): void
    {
        $this->log = $container->get(LoggerInterface::class);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        foreach ($this->except as $prefix) {
            if (str_starts_with($request->path(), $prefix)) {
                return $next($request);
            }
        }

        $start = hrtime(true);
        $response = Response::from($next($request));
        $ms = round((hrtime(true) - $start) / 1e6, 1);
        $status = $response->status();

        ($this->log ?? throw new \LogicException('LogRequests needs the container.'))->log(
            $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'),
            sprintf('%s %s %d %.1f ms', $request->method(), $request->path(), $status, $ms),
            array_filter([
                'status' => $status,
                'ms' => $ms,
                'route' => $request->context()->find(Route::class)?->name(),
            ], static fn ($v) => $v !== null),
        );

        return $response;
    }
}

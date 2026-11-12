<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Application;
use Pype\Database\Connection;
use Pype\Http\AfterResponse;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\StageSpec;
use Pype\Routing\Route;
use Pype\Routing\Router;
use Pype\Stages\RequestIdValue;

/**
 * Records what each request did, for /_pype/requests. DebugProvider hands observer() to
 * Application::observeStages(), so it is told about every stage of every request.
 *
 * At a request's first stage it starts that request's Trace (kept in the request's context:
 * nothing about a request is held here) and listens to the database; when the outermost stage is
 * left it stops listening; once the response is on its way the record is written. The
 * development screens' own requests are not recorded.
 */
final class Recorder
{
    public function __construct(
        private readonly Application $app,
        private readonly RequestLog $log,
        private readonly string $except,
    ) {
    }

    public function observer(): Closure
    {
        return function (string $event, mixed $stage, mixed $request, mixed $value): void {
            if (!$request instanceof Request) {
                return;
            }

            $trace = $request->context()->find(Trace::class);

            if ($trace === null) {
                if ($event !== 'enter' || $request->path() === $this->except || str_starts_with($request->path(), $this->except . '/')) {
                    return;
                }

                $trace = $this->start($request);
            }

            // RecordRequest only makes sure there is a stage to start at; it is not part of the app.
            $own = $stage instanceof StageSpec && $stage->class === RecordRequest::class;

            if (!$own) {
                match ($event) {
                    'enter' => $trace->enter($stage, $request),
                    'threw' => $trace->threw($value),
                    default => $trace->leave($request, $value),
                };
            }

            if ($event === 'leave' && $trace->idle()) {
                $trace->answered($value instanceof Response ? $value : null);
                $this->database()?->listen(null);
            }
        };
    }

    private function start(Request $request): Trace
    {
        $trace = new Trace(hrtime(true));
        $request->context()->put($trace);
        $this->database()?->listen($trace->query(...));

        AfterResponse::of($request)->push(function () use ($trace): void {
            $request = $trace->request();
            $record = $trace->finish($this->unreached($request, $trace));
            $record['requestId'] = $request?->context()->find(RequestIdValue::class)?->value;
            $this->log->store($record);
        });

        return $trace;
    }

    /** Only a database the app set up (the Connection object connects when first queried, so asking for it costs nothing). */
    private function database(): ?Connection
    {
        return $this->app->container->bound(Connection::class) ? $this->app->container->get(Connection::class) : null;
    }

    /**
     * The route's stages that never ran, by name: a stage before them answered (a redirect, a
     * 404, validation errors) or threw.
     *
     * @return list<string>
     */
    private function unreached(?Request $request, Trace $trace): array
    {
        $route = $request?->context()->find(Route::class);

        if ($route === null) {
            return [];
        }

        $planned = array_map(Router::describe(...), $this->app->router->compile($route->stages()));

        return array_slice($planned, $trace->enteredInRoute());
    }
}

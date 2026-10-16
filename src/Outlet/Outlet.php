<?php

declare(strict_types=1);

namespace Pype\Outlet;

use Closure;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Stage;

/**
 * The stage where the flow leaves the pipeline: it decides how the result is written out.
 *
 *   $router->get('/report', [LoadReport::class, CsvOutlet::class]);                 // per route
 *   $router->group('/admin', ['auth', new View('admin/page')], fn ...);              // per group
 *   $app->outlet(JsonOutlet::class);                                                 // app default
 *
 * An outlet can sit anywhere in a stage list — pype moves outlets to the front so they wrap
 * every other stage, and the innermost one wins: route beats group beats global beats default.
 *
 * Extend it for your own formats: implement render() and contentType().
 */
abstract class Outlet implements Stage
{
    /** Turn the response into the bytes sent to the client. */
    abstract public function render(Response $response, Request $request): string;

    /** The Content-Type header for this response. It may differ for errors (e.g. JSON errors from a CSV outlet). */
    abstract public function contentType(Response $response): string;

    /** Run the rest of the pipeline, then claim the response unless a closer outlet already did. */
    public function handle(Request $request, Closure $next): mixed
    {
        $response = Response::from($next($request));

        return $response->outlet() === null ? $response->withOutlet($this) : $response;
    }
}

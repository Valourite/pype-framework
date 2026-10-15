<?php

declare(strict_types=1);

namespace Pype\Pipeline;

use Closure;
use Pype\Http\Request;

/**
 * A single stage in a pipeline.
 *
 * Call $next($request) to pass the request further down the stage,
 * or return a value directly to short-circuit (e.g. auth failure, cached result).
 */
interface Stage
{
    public function handle(Request $request, Closure $next): mixed;
}

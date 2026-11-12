<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/**
 * A global stage that does nothing: DebugProvider adds it so every request goes through at least
 * one stage, also one no route matches in an app with no global stages of its own. The recorder
 * (Recorder, the pipeline's observer) starts at a request's first stage, whichever that is.
 */
final class RecordRequest implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}

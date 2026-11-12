<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/** Every route and its stages (/_pype/routes). Registered by DebugProvider. */
final class ShowRoutes implements Stage
{
    public function __construct(private readonly DebugPages $pages)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $this->pages->routes($request);
    }
}

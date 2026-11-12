<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/** The front page of the development screens (/_pype): where each one is. Registered by DebugProvider. */
final class ShowDebugIndex implements Stage
{
    public function __construct(private readonly DebugPages $pages)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $this->pages->index($request);
    }
}

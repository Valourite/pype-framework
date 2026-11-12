<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/** One route taken apart into its stages (/_pype/routes/show?m=GET&p=/path). Registered by DebugProvider. */
final class ShowRoute implements Stage
{
    public function __construct(private readonly DebugPages $pages)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $this->pages->route($request);
    }
}

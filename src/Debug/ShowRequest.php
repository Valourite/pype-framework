<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/** One recorded request, stage by stage (/_pype/requests/show?id=...). Registered by DebugProvider. */
final class ShowRequest implements Stage
{
    public function __construct(private readonly DebugPages $pages)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $this->pages->request($request);
    }
}

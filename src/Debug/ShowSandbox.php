<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/** The sandbox: its state and the form to send a request into it (/_pype/sandbox). Registered by DebugProvider. */
final class ShowSandbox implements Stage
{
    public function __construct(private readonly DebugPages $pages)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $this->pages->sandbox($request);
    }
}

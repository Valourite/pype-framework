<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/** Builds or resets the sandbox, or runs a request against it and shows what it did (POST /_pype/sandbox). Registered by DebugProvider. */
final class RunSandbox implements Stage
{
    public function __construct(private readonly DebugPages $pages)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $this->pages->sandboxAction($request);
    }
}

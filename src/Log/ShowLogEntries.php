<?php

declare(strict_types=1);

namespace Pype\Log;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/** The entries the log viewer page polls for (/_pype/logs/entries). Registered by LogProvider. */
final class ShowLogEntries implements Stage
{
    public function __construct(private readonly LogViewer $viewer)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $this->viewer->entries($request);
    }
}

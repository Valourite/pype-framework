<?php

declare(strict_types=1);

namespace Pype\Log;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;
use Pype\Routing\Urls;

/** The log viewer page (/_pype/logs). Registered by LogProvider. */
final class ShowLogs implements Stage
{
    public function __construct(private readonly LogViewer $viewer, private readonly Urls $urls)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $this->viewer->page($request, $this->urls);
    }
}

<?php

declare(strict_types=1);

namespace Pype\Exceptions;

use Pype\Http\Request;
use Throwable;

/**
 * Receives unexpected errors (anything that isn't a 4xx HttpException) for
 * logging/alerting — send them to a file, Sentry, Slack, etc.
 *
 *   $app->reportUsing(SentryReporter::class);
 *   $app->reportUsing(fn (Throwable $e, Request $request) => $logger->error($e));
 */
interface Reporter
{
    public function report(Throwable $e, Request $request): void;
}

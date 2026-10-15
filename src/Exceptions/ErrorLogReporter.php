<?php

declare(strict_types=1);

namespace Pype\Exceptions;

use Pype\Http\Request;
use Throwable;

/**
 * Default reporter, used when no other is registered: PHP's error_log().
 */
final class ErrorLogReporter implements Reporter
{
    public function report(Throwable $e, Request $request): void
    {
        error_log(sprintf('[pype] %s %s — %s', $request->method(), $request->path(), $e));
    }
}

<?php

declare(strict_types=1);

namespace Pype\Log;

use Psr\Log\LoggerInterface;
use Pype\Exceptions\Reporter;
use Pype\Http\Request;
use Throwable;

/** Sends unexpected errors (5xx) to the log, with the exception and the request that hit it. */
final class LogReporter implements Reporter
{
    public function __construct(private readonly LoggerInterface $log)
    {
    }

    public function report(Throwable $e, Request $request): void
    {
        $this->log->error($e->getMessage() !== '' ? $e->getMessage() : $e::class, [
            'exception' => $e,
            'method' => $request->method(),
            'path' => $request->path(),
        ]);
    }
}

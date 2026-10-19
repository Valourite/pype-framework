<?php

declare(strict_types=1);

namespace Pype\Log;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Writes the same JSON lines as Logger to a stream: php://stderr (log.forward 'stderr') or
 * php://stdout ('stdout'), where Docker, Kubernetes and platform log collectors pick them up,
 * or any file path. Under PHP-FPM, worker stderr only reaches the logs with
 * `catch_workers_output = yes` (the official Docker images set it).
 */
final class StreamLogger extends AbstractLogger
{
    /** @var resource|null opened once per process */
    private $stream = null;

    public function __construct(private readonly string $target = 'php://stderr')
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->stream ??= fopen($this->target, 'ab') ?: throw new \RuntimeException("Cannot open {$this->target} for logging.");
        fwrite($this->stream, Logger::line((string) $level, $message, $context) . "\n");
    }
}

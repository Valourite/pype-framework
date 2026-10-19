<?php

declare(strict_types=1);

namespace Pype\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Sends entries to the system log with PHP's syslog() (log.forward 'syslog'), where rsyslog,
 * journald or a log agent can ship them on. The message is the same JSON line as the file.
 */
final class SyslogLogger extends AbstractLogger
{
    private const PRIORITIES = [
        LogLevel::DEBUG => LOG_DEBUG, LogLevel::INFO => LOG_INFO, LogLevel::NOTICE => LOG_NOTICE,
        LogLevel::WARNING => LOG_WARNING, LogLevel::ERROR => LOG_ERR, LogLevel::CRITICAL => LOG_CRIT,
        LogLevel::ALERT => LOG_ALERT, LogLevel::EMERGENCY => LOG_EMERG,
    ];

    private bool $opened = false;

    public function __construct(private readonly string $ident = 'pype', private readonly int $facility = LOG_USER)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!$this->opened) {
            openlog($this->ident, LOG_PID | LOG_ODELAY, $this->facility);
            $this->opened = true;
        }

        syslog(self::PRIORITIES[strtolower((string) $level)] ?? LOG_INFO, Logger::line((string) $level, $message, $context));
    }
}

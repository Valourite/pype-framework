<?php

declare(strict_types=1);

namespace Pype\Log;

use Closure;
use DateTimeImmutable;
use JsonSerializable;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * PSR-3 logger writing one JSON object per line to storage/logs/pype-YYYY-MM-DD.log:
 *
 *   {"time":"2026-10-19T12:31:04.512+00:00","level":"error","message":"...","request_id":"7f3a...","context":{...}}
 *
 * Inject it as Psr\Log\LoggerInterface (or Logger):
 *
 *   fn (LoggerInterface $log) => $log->warning('Card declined for {user}', ['user' => $user->id]);
 *
 * Each write is a single append with an exclusive lock, so concurrent workers never interleave
 * lines. Entries carry the current request's id, so every line from one request can be found
 * together. Files older than $days are deleted, checked once a day per process.
 *
 * Entries at $forwardLevel or above are also passed to every $forward logger (stderr, syslog,
 * Monolog, your own PSR-3 class...), with the request id added to the context. The file is
 * always written first, so the log viewer and error page keep working; a failing forward
 * logger is reported to error_log() and never breaks the request.
 */
final class Logger extends AbstractLogger
{
    public const LEVELS = [
        LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3,
        LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
    ];

    private readonly int $minimum;

    /** Day the old files were last pruned in this process (app-level, not request state). */
    private string $prunedOn = '';

    private readonly int $forwardMinimum;

    /**
     * @param Closure(): ?string|null $requestId  the id of the request being handled, if any
     * @param list<LoggerInterface>   $forward    also send entries here
     */
    public function __construct(
        private readonly string $directory,
        string $level = LogLevel::DEBUG,
        private readonly int $days = 14,
        private readonly ?Closure $requestId = null,
        private readonly array $forward = [],
        string $forwardLevel = LogLevel::DEBUG,
    ) {
        $this->minimum = self::LEVELS[strtolower($level)] ?? throw new InvalidArgumentException("Unknown log level [{$level}].");
        $this->forwardMinimum = self::LEVELS[strtolower($forwardLevel)] ?? throw new InvalidArgumentException("Unknown log level [{$forwardLevel}].");
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = strtolower((string) $level);
        $rank = self::LEVELS[$level] ?? throw new InvalidArgumentException("Unknown log level [{$level}].");

        if ($rank < $this->minimum) {
            return;
        }

        $now = new DateTimeImmutable();
        $id = $this->requestId?->__invoke();
        $line = self::line($level, $message, $id === null ? $context : ['request_id' => $id] + $context, $now);
        $file = $this->file($now);

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('[pype] could not write to ' . $file . ': ' . $line);
        }

        if ($this->prunedOn !== $now->format('Y-m-d')) {
            $this->prunedOn = $now->format('Y-m-d');
            $this->prune($now);
        }

        if ($this->forward !== [] && $rank >= $this->forwardMinimum) {
            $forwarded = $id === null ? $context : $context + ['request_id' => $id];

            foreach ($this->forward as $logger) {
                try {
                    $logger->log($level, $message, $forwarded);
                } catch (Throwable $e) {
                    error_log('[pype] log forwarding to ' . $logger::class . ' failed: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * The JSON line for an entry; shared with StreamLogger so every destination sees the same shape.
     *
     * @param array<string, mixed> $context  a 'request_id' key is lifted to the top level
     */
    public static function line(string $level, string|Stringable $message, array $context, ?DateTimeImmutable $time = null): string
    {
        $entry = [
            'time' => ($time ?? new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            'level' => strtolower($level),
            'message' => self::interpolate((string) $message, $context),
        ];

        if (isset($context['request_id']) && is_string($context['request_id'])) {
            $entry['request_id'] = $context['request_id'];
            unset($context['request_id']);
        }

        if ($context !== []) {
            $entry['context'] = self::normalize($context);
        }

        return (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function file(?DateTimeImmutable $day = null): string
    {
        return $this->directory . '/pype-' . ($day ?? new DateTimeImmutable())->format('Y-m-d') . '.log';
    }

    /** "Card declined for {user}" + ['user' => 5] → "Card declined for 5" (PSR-3 placeholders). */
    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $replace['{' . $key . '}'] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return strtr($message, $replace);
    }

    /** Context made JSON-friendly: exceptions as class/message/file/trace, objects by class. */
    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        return match (true) {
            $value instanceof Throwable => self::exception($value),
            $depth > 5 => '…',
            is_array($value) => array_map(static fn ($v) => self::normalize($v, $depth + 1), $value),
            $value instanceof JsonSerializable => self::normalize($value->jsonSerialize(), $depth + 1),
            $value instanceof Stringable => (string) $value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            is_object($value) => ['class' => $value::class] + self::normalize(get_object_vars($value), $depth + 1),
            is_resource($value) => 'resource(' . get_resource_type($value) . ')',
            is_float($value) && !is_finite($value) => (string) $value,
            default => $value,
        };
    }

    /** @return array<string, mixed> */
    public static function exception(Throwable $e): array
    {
        $out = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
        ];

        if ($e->getPrevious() !== null) {
            $out['previous'] = self::exception($e->getPrevious());
        }

        return $out;
    }

    private function prune(DateTimeImmutable $now): void
    {
        if ($this->days <= 0) {
            return;
        }

        $cutoff = $now->modify("-{$this->days} days")->format('Y-m-d');

        foreach (glob($this->directory . '/pype-*.log') ?: [] as $file) {
            if (preg_match('/pype-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m) && $m[1] < $cutoff) {
                @unlink($file);
            }
        }
    }
}

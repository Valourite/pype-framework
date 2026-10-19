<?php

declare(strict_types=1);

namespace Pype\Log;

use InvalidArgumentException;

/**
 * Reads the JSON-lines log files the Logger writes: the newest entries, entries written after
 * a byte offset (for live polling), and every entry of one request. Never reads a line that is
 * still being written: only up to the last newline.
 */
final class LogReader
{
    private const CHUNK = 65536;

    public function __construct(private readonly string $directory)
    {
    }

    /** @return list<string> log file names, newest first */
    public function files(): array
    {
        $files = array_map('basename', glob($this->directory . '/pype-*.log') ?: []);
        rsort($files);

        return $files;
    }

    /** Today's file name, whether or not it exists yet. */
    public function today(): string
    {
        return 'pype-' . date('Y-m-d') . '.log';
    }

    /**
     * The last $limit entries of $file before byte $before (default: the end), filters applied,
     * oldest first. `next` is where live polling continues; `before` is where "load older"
     * continues (0 = start of the file reached).
     *
     * @return array{entries: list<array>, next: int, before: int, file: string}
     */
    public function tail(string $file, int $limit = 200, array $filters = [], ?int $before = null): array
    {
        $path = $this->path($file);
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;
        $handle = $size > 0 ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            return ['entries' => [], 'next' => 0, 'before' => 0, 'file' => $file];
        }

        // Only whole lines: end just after the last "\n" (a line still being written waits).
        $end = $this->lineEnd($handle, $before === null ? $size : max(0, min($before, $size)));
        $next = $before === null ? $end : $this->lineEnd($handle, $size);

        $entries = [];
        $pos = $end;          // $buffer holds the bytes [$pos, $oldest): whole lines + maybe a partial first one
        $oldest = $end;
        $buffer = '';

        while (count($entries) < $limit && $oldest > 0) {
            $content = substr($buffer, 0, -1);                 // drop the "\n" ending the last line
            $nl = $buffer === '' ? false : strrpos($content, "\n");

            if ($buffer === '' || ($nl === false && $pos > 0)) {
                // Need more bytes: the newest unread line starts further back.
                $read = min(self::CHUNK, $pos);
                $pos -= $read;
                fseek($handle, $pos);
                $buffer = (string) fread($handle, $read) . $buffer;
                continue;
            }

            $line = $nl === false ? $content : substr($content, $nl + 1);
            $buffer = $nl === false ? '' : substr($content, 0, $nl + 1);
            $oldest = $pos + strlen($buffer);

            if ($line !== '' && ($entry = self::decode($line)) !== null && self::matches($entry, $filters)) {
                $entries[] = $entry;
            }
        }

        fclose($handle);

        return ['entries' => array_reverse($entries), 'next' => $next, 'before' => $oldest, 'file' => $file];
    }

    /** The offset just after the last "\n" at or before $offset (0 if there is none). */
    private function lineEnd($handle, int $offset): int
    {
        while ($offset > 0) {
            $read = min(self::CHUNK, $offset);
            fseek($handle, $offset - $read);
            $chunk = (string) fread($handle, $read);
            $nl = strrpos($chunk, "\n");
            if ($nl !== false) {
                return $offset - $read + $nl + 1;
            }
            $offset -= $read;
        }

        return 0;
    }

    /**
     * Entries written to $file after byte $offset, up to $maxBytes, and where to continue.
     * If the file shrank (cleared or rotated), reading starts again from the beginning.
     *
     * @return array{entries: list<array>, next: int, file: string, reset: bool}
     */
    public function after(string $file, int $offset, array $filters = [], int $maxBytes = 262144): array
    {
        $path = $this->path($file);
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;
        $reset = $offset > $size;
        $offset = $reset ? 0 : $offset;

        if ($size === $offset) {
            return ['entries' => [], 'next' => $offset, 'file' => $file, 'reset' => $reset];
        }

        $handle = fopen($path, 'rb');
        fseek($handle, $offset);
        $chunk = (string) fread($handle, min($maxBytes, $size - $offset));
        fclose($handle);

        // Stop at the last complete line; the rest is picked up next time.
        $complete = strrpos($chunk, "\n");
        if ($complete === false) {
            return ['entries' => [], 'next' => $offset, 'file' => $file, 'reset' => $reset];
        }

        $entries = [];
        foreach (explode("\n", substr($chunk, 0, $complete)) as $line) {
            if ($line !== '' && ($entry = self::decode($line)) !== null && self::matches($entry, $filters)) {
                $entries[] = $entry;
            }
        }

        return ['entries' => $entries, 'next' => $offset + $complete + 1, 'file' => $file, 'reset' => $reset];
    }

    /**
     * Every entry for one request id in today's file (searching the last $maxBytes).
     *
     * @return list<array>
     */
    public function forRequest(string $requestId, int $maxBytes = 1048576): array
    {
        $path = $this->path($this->today());
        if (!is_file($path)) {
            return [];
        }

        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        $from = max(0, $size - $maxBytes);
        fseek($handle, $from);
        $chunk = (string) fread($handle, $size - $from);
        fclose($handle);

        $entries = [];
        $needle = '"request_id":' . json_encode($requestId);
        foreach (explode("\n", $chunk) as $line) {
            if (str_contains($line, $needle) && ($entry = self::decode($line)) !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** Only file names the Logger writes, so a request can't read anything else on disk. */
    private function path(string $file): string
    {
        if (!preg_match('/^pype-\d{4}-\d{2}-\d{2}\.log$/', $file)) {
            throw new InvalidArgumentException('Not a log file name.');
        }

        return $this->directory . '/' . $file;
    }

    private static function decode(string $line): ?array
    {
        $entry = json_decode($line, true);

        return is_array($entry) ? $entry : null;
    }

    /** Filters: level (minimum), request (id), q (text, case-insensitive, searched in the raw entry). */
    private static function matches(array $entry, array $filters): bool
    {
        if (($filters['level'] ?? '') !== '' && (Logger::LEVELS[$entry['level'] ?? ''] ?? -1) < (Logger::LEVELS[$filters['level']] ?? 0)) {
            return false;
        }

        if (($filters['request'] ?? '') !== '' && ($entry['request_id'] ?? null) !== $filters['request']) {
            return false;
        }

        if (($filters['q'] ?? '') !== '' && stripos(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', (string) $filters['q']) === false) {
            return false;
        }

        return true;
    }
}

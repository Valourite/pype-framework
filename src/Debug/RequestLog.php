<?php

declare(strict_types=1);

namespace Pype\Debug;

/**
 * The recorded requests, one JSON file each, for /_pype/requests. Files, so it works under
 * PHP-FPM (every request a fresh process). Keeps the newest $keep.
 */
final class RequestLog
{
    public function __construct(private readonly string $directory, private readonly int $keep = 100)
    {
    }

    /** @param array<string, mixed> $record */
    public function store(array $record): string
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        $id = sprintf('%s-%s', (new \DateTimeImmutable())->format('Ymd-His-u'), bin2hex(random_bytes(3)));
        $file = "{$this->directory}/{$id}.json";
        $json = json_encode(['id' => $id] + $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json !== false && @file_put_contents("{$file}.tmp", $json) !== false) {
            @rename("{$file}.tmp", $file);
            $this->prune();
        }

        return $id;
    }

    /** @return list<array<string, mixed>> newest first, without the stages */
    public function all(): array
    {
        $out = [];

        foreach ($this->files() as $file) {
            if (($record = self::read($file)) !== null) {
                $record['problem'] = self::problem($record);
                unset($record['stages'], $record['headers'], $record['input'], $record['loose'], $record['context']);
                $out[] = $record;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return preg_match('/^[0-9a-f-]+$/', $id) === 1 ? self::read("{$this->directory}/{$id}.json") : null;
    }

    public function clear(): void
    {
        foreach ($this->files() as $file) {
            @unlink($file);
        }
    }

    /** Whether a stage threw something unexpected (not a 404 or a 401: those are answers): worth a mark on the list. */
    private static function problem(array $record): bool
    {
        foreach ($record['stages'] ?? [] as $stage) {
            if (($stage['exception'] ?? null) !== null && !($stage['exception']['http'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> newest first */
    private function files(): array
    {
        $files = glob("{$this->directory}/*.json") ?: [];
        rsort($files);

        return $files;
    }

    private function prune(): void
    {
        foreach (array_slice($this->files(), $this->keep) as $old) {
            @unlink($old);
        }
    }

    /** @return array<string, mixed>|null */
    private static function read(string $file): ?array
    {
        $record = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($record) ? $record : null;
    }
}

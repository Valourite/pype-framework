<?php

declare(strict_types=1);

namespace Pype\Mail;

/**
 * Copies of sent mail, one JSON file each, for the dev mailbox at /_pype/mail. Files, so it works
 * under PHP-FPM (every request a fresh process) and from queue workers. Keeps the newest $keep.
 */
final class Mailbox
{
    public function __construct(private readonly string $directory, private readonly int $keep = 100)
    {
    }

    /** Keep a copy of $message (as delivered by $driver); returns its id. */
    public function store(Message $message, string $driver): string
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        $id = sprintf('%s-%s', (new \DateTimeImmutable())->format('Ymd-His-u'), bin2hex(random_bytes(3)));
        $html = $message->getHtml();
        $text = $message->getText();

        $record = [
            'id' => $id,
            'at' => date(DATE_ATOM),
            'driver' => $driver,
            'subject' => $message->getSubject(),
            'from' => $message->sender(),
            'to' => $message->recipients('to'),
            'cc' => $message->recipients('cc'),
            'bcc' => $message->recipients('bcc'),
            'html' => $html,
            'text' => $text,
            'attachments' => array_column($message->attachments(), 'name'),
            'links' => self::links((string) $html . "\n" . (string) $text),
        ];

        $file = "{$this->directory}/{$id}.json";
        file_put_contents("{$file}.tmp", json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename("{$file}.tmp", $file);

        $this->prune();

        return $id;
    }

    /** @return list<array<string, mixed>> newest first, without the bodies */
    public function all(): array
    {
        return array_map(static function (string $file): array {
            $record = self::read($file) ?? [];
            unset($record['html'], $record['text']);

            return $record;
        }, $this->files());
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

    /** @return list<string> URLs in the message, in order, once each */
    public static function links(string $body): array
    {
        preg_match_all('#https?://[^\s"\'<>]+#', html_entity_decode($body, ENT_QUOTES), $matches);

        // A link at the end of a sentence or in brackets: "(https://…)." is the link without ")."
        $links = array_map(static fn (string $url) => rtrim($url, '.,;:!?)]}'), $matches[0]);

        return array_values(array_unique($links));
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
        foreach (array_slice($this->files(), $this->keep) as $file) {
            @unlink($file);
        }
    }

    /** @return array<string, mixed>|null */
    private static function read(string $file): ?array
    {
        $json = is_file($file) ? file_get_contents($file) : false;
        $record = $json === false ? null : json_decode($json, true);

        return is_array($record) ? $record : null;
    }
}

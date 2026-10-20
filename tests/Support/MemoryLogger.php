<?php

declare(strict_types=1);

namespace Pype\Tests\Support;

use Psr\Log\AbstractLogger;

/** Keeps log records in memory, for assertions. */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> "level: message" with {placeholders} filled from the context */
    public function lines(): array
    {
        return array_map(static function (array $r): string {
            $replace = [];
            foreach ($r['context'] as $k => $v) {
                if (is_scalar($v)) {
                    $replace['{' . $k . '}'] = (string) $v;
                }
            }

            return $r['level'] . ': ' . strtr($r['message'], $replace);
        }, $this->records);
    }
}

<?php

declare(strict_types=1);

namespace Pype\Schedule;

use Cron\CronExpression;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A standard 5-field cron expression: minute hour day-of-month month day-of-week, matched by
 * dragonmantank/cron-expression. Each field takes *, n, a-b, *\/n, a-b/n, n/step and comma lists;
 * months and days may be named (JAN, MON); day-of-week 0 and 7 are both Sunday. Also L, W, #, ?
 * and the @daily-style macros (expanded to 5 fields, so Task's helpers can still edit them).
 *
 * pype keeps classic cron's rules where the package differs:
 * - when both day fields are restricted a day matching either is due, but a field starting with
 *   '*' ('*\/2') counts as unrestricted, so then both must match;
 * - '5/20' means 5-59/20; a descending range ('5-1') is an error;
 * - daylight saving: a time skipped when clocks go forward runs an hour later (02:30 → 03:30);
 *   when clocks go back, a task with a fixed minute and hour runs in the first pass of the
 *   repeated hour only (hourly and every-minute tasks run in both).
 */
final class Cron
{
    private const MAX = [59, 23, 31, 12, 6];

    private const NAMES = [
        3 => ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12],
        4 => ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6],
    ];

    /** @var list<string> the 5 fields as matched (macros expanded, 'n/step' rewritten) */
    public readonly array $fields;

    /** @var list<CronExpression> all must be due (two when the day fields are ANDed) */
    private readonly array $checks;

    public function __construct(public readonly string $expression)
    {
        $trimmed = trim($expression);
        $parts = preg_split('/\s+/', CronExpression::MAPPINGS[strtolower($trimmed)] ?? $trimmed);

        if (count($parts) !== 5) {
            throw new InvalidArgumentException("Cron expression [{$expression}] must have 5 fields.");
        }

        $fields = [];
        foreach ($parts as $i => $part) {
            $fields[] = $this->normalise(strtoupper($part), $i);
        }
        $this->fields = $fields;

        [$minute, $hour, $dom, $month, $dow] = $fields;
        $restricted = static fn (string $f) => $f !== '*' && $f !== '?';

        try {
            // Both day fields set, one written as '*...': cron ANDs them, the package would OR them.
            $this->checks = $restricted($dom) && $restricted($dow) && (str_starts_with($dom, '*') || str_starts_with($dow, '*'))
                ? [new CronExpression("{$minute} {$hour} {$dom} {$month} *"), new CronExpression("{$minute} {$hour} * {$month} {$dow}")]
                : [new CronExpression(implode(' ', $fields))];
        } catch (InvalidArgumentException) {
            throw $this->invalid();
        }
    }

    public function isDue(DateTimeInterface $time): bool
    {
        $zone = $time->getTimezone()->getName();

        foreach ($this->checks as $check) {
            if (!$check->isDue($time, $zone)) {
                return false;
            }
        }

        return !$this->isRepeatedHour($time);
    }

    /**
     * Clocks went back and this wall-clock minute already happened an hour ago: a task with a
     * fixed minute and hour ran then, so it doesn't run again.
     */
    private function isRepeatedHour(DateTimeInterface $time): bool
    {
        if (str_starts_with($this->fields[0], '*') || str_starts_with($this->fields[1], '*')) {
            return false;
        }

        $hourAgo = (new \DateTimeImmutable('@' . ($time->getTimestamp() - 3600)))->setTimezone($time->getTimezone());

        return $hourAgo->format('Y-m-d H:i') === $time->format('Y-m-d H:i');
    }

    /** 'n/step' → 'n-max/step'; descending ranges are rejected (the package would accept '5-1'). */
    private function normalise(string $field, int $index): string
    {
        $items = explode(',', $field);

        foreach ($items as $k => $item) {
            if (preg_match('#^(\d+|[A-Z]+)/(\d+)$#', $item, $m)) {
                $items[$k] = $this->value($m[1], $index) . '-' . self::MAX[$index] . "/{$m[2]}";
            } elseif (preg_match('#^(\d+|[A-Z]+)-(\d+|[A-Z]+)(?:/\d+)?$#', $item, $m)
                && $this->value($m[1], $index) > $this->value($m[2], $index)) {
                throw $this->invalid();
            }
        }

        return implode(',', $items);
    }

    private function value(string $token, int $index): int
    {
        return ctype_digit($token) ? (int) $token : (self::NAMES[$index][$token] ?? throw $this->invalid());
    }

    private function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException("Invalid cron expression [{$this->expression}].");
    }
}

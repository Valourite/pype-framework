<?php

declare(strict_types=1);

namespace Pype\Schedule;

use DateTimeInterface;

/**
 * A standard 5-field cron expression: minute hour day-of-month month day-of-week.
 * Each field takes *, n, a-b, *\/n, a-b/n and comma lists; months and days may be named
 * (JAN, MON); day-of-week 0 and 7 are both Sunday. As in cron, when both day-of-month and
 * day-of-week are restricted, a day matching either one is due.
 */
final class Cron
{
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    private const NAMES = [
        3 => ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12],
        4 => ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6],
    ];

    /** @var list<array<int, true>> allowed values per field */
    private readonly array $fields;

    /** @var list<bool> whether a field is '*' (unrestricted) */
    private readonly array $any;

    public function __construct(public readonly string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("Cron expression [{$expression}] must have 5 fields.");
        }

        $fields = $any = [];

        foreach ($parts as $i => $part) {
            $fields[] = $this->parse(strtoupper($part), $i);
            $any[] = str_starts_with($part, '*');   // as in cron: '*' and '*/n' count as unrestricted
        }

        // 7 is Sunday too.
        if (isset($fields[4][7])) {
            $fields[4][0] = true;
        }

        $this->fields = $fields;
        $this->any = $any;
    }

    public function isDue(DateTimeInterface $time): bool
    {
        [$minute, $hour, $day, $month, $weekday] = array_map('intval', explode(' ', $time->format('i G j n w')));

        if (!isset($this->fields[0][$minute], $this->fields[1][$hour], $this->fields[3][$month])) {
            return false;
        }

        $dom = isset($this->fields[2][$day]);
        $dow = isset($this->fields[4][$weekday]);

        // Both restricted: either may match. Otherwise the restricted one decides.
        return match (true) {
            !$this->any[2] && !$this->any[4] => $dom || $dow,
            default => $dom && $dow,
        };
    }

    /** @return array<int, true> */
    private function parse(string $field, int $index): array
    {
        [$min, $max] = self::RANGES[$index];
        $values = [];

        foreach (explode(',', $field) as $item) {
            if (!preg_match('#^(\*|[0-9A-Z]+(?:-[0-9A-Z]+)?)(?:/(\d+))?$#', $item, $m)) {
                throw $this->invalid();
            }

            $step = isset($m[2]) ? (int) $m[2] : 1;

            if ($m[1] === '*') {
                [$from, $to] = [$min, $max === 7 ? 6 : $max];
            } else {
                $bounds = explode('-', $m[1]);
                $from = $this->value($bounds[0], $index);
                $to = isset($bounds[1]) ? $this->value($bounds[1], $index) : (isset($m[2]) ? ($max === 7 ? 6 : $max) : $from);
            }

            if ($step < 1 || $from < $min || $to > $max || $from > $to) {
                throw $this->invalid();
            }

            for ($v = $from; $v <= $to; $v += $step) {
                $values[$v] = true;
            }
        }

        return $values;
    }

    private function value(string $token, int $index): int
    {
        if (ctype_digit($token)) {
            return (int) $token;
        }

        return self::NAMES[$index][$token] ?? throw $this->invalid();
    }

    private function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException("Invalid cron expression [{$this->expression}].");
    }
}

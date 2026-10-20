<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Pype\Schedule\Cron;

final class CronTest extends TestCase
{
    private static function due(string $expression, string $time): bool
    {
        return (new Cron($expression))->isDue(new DateTimeImmutable($time));
    }

    public function test_every_field_form(): void
    {
        $this->assertTrue(self::due('* * * * *', '2026-10-20 13:37'));
        $this->assertTrue(self::due('37 13 * * *', '2026-10-20 13:37'));
        $this->assertFalse(self::due('38 13 * * *', '2026-10-20 13:37'));
        $this->assertTrue(self::due('*/5 * * * *', '2026-10-20 13:35'));
        $this->assertFalse(self::due('*/5 * * * *', '2026-10-20 13:37'));
        $this->assertTrue(self::due('0 9-17 * * *', '2026-10-20 17:00'));
        $this->assertFalse(self::due('0 9-17 * * *', '2026-10-20 18:00'));
        $this->assertTrue(self::due('0 8-18/2 * * *', '2026-10-20 12:00'));
        $this->assertFalse(self::due('0 8-18/2 * * *', '2026-10-20 13:00'));
        $this->assertTrue(self::due('5/20 * * * *', '2026-10-20 13:45'));   // 5, 25, 45
        $this->assertTrue(self::due('0,15,30 * * * *', '2026-10-20 13:30'));
        $this->assertFalse(self::due('0,15,30 * * * *', '2026-10-20 13:31'));
        $this->assertTrue(self::due('0 0 1 jan *', '2026-01-01 00:00'));
        $this->assertTrue(self::due('0 0 1 1-3 *', '2026-03-01 00:00'));
        $this->assertFalse(self::due('0 0 1 1-3 *', '2026-04-01 00:00'));
    }

    public function test_days_of_the_week(): void
    {
        // 2026-10-18 is a Sunday.
        $this->assertTrue(self::due('0 0 * * 0', '2026-10-18 00:00'));
        $this->assertTrue(self::due('0 0 * * 7', '2026-10-18 00:00'));
        $this->assertTrue(self::due('0 0 * * SUN', '2026-10-18 00:00'));
        $this->assertTrue(self::due('0 9 * * mon-fri', '2026-10-20 09:00'));   // Tuesday
        $this->assertFalse(self::due('0 9 * * 1-5', '2026-10-18 09:00'));
        $this->assertTrue(self::due('0 9 * * 5-7', '2026-10-18 09:00'));
    }

    public function test_day_of_month_or_day_of_week_when_both_are_set(): void
    {
        // The 1st, or any Monday (as cron does).
        $this->assertTrue(self::due('0 0 1 * 1', '2026-10-01 00:00'));   // Thursday the 1st
        $this->assertTrue(self::due('0 0 1 * 1', '2026-10-19 00:00'));   // a Monday
        $this->assertFalse(self::due('0 0 1 * 1', '2026-10-20 00:00'));
        // Only one restricted: it alone decides.
        $this->assertFalse(self::due('0 0 * * 1', '2026-10-01 00:00'));
        $this->assertTrue(self::due('0 0 31 * *', '2026-10-31 00:00'));
        $this->assertFalse(self::due('0 0 31 * *', '2026-11-30 00:00'));
        // A '*/n' field counts as unrestricted, like '*', so both must match (odd days AND Mondays).
        $this->assertTrue(self::due('0 0 */2 * 1', '2026-10-19 00:00'));    // the 19th, a Monday
        $this->assertFalse(self::due('0 0 */2 * 1', '2026-10-12 00:00'));   // a Monday, but the 12th
        $this->assertFalse(self::due('0 0 */2 * 1', '2026-10-21 00:00'));   // the 21st, but a Wednesday
    }

    public function test_invalid_expressions_throw(): void
    {
        foreach (['* * * *', '60 * * * *', '* 24 * * *', '* * 0 * *', '* * * 13 *', '* * * * 8', '*/0 * * * *', '5-1 * * * *', 'x * * * *', '* * * FOO *'] as $expression) {
            try {
                new Cron($expression);
                $this->fail("[{$expression}] was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

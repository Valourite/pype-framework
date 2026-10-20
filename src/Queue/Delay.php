<?php

declare(strict_types=1);

namespace Pype\Queue;

use DateInterval;
use DateTimeImmutable;

/** @internal */
final class Delay
{
    public static function seconds(int|DateInterval $delay, int $now): int
    {
        if ($delay instanceof DateInterval) {
            $start = (new DateTimeImmutable())->setTimestamp($now);

            return $start->add($delay)->getTimestamp() - $now;
        }

        return $delay;
    }
}

<?php

declare(strict_types=1);

namespace Pype\RateLimit;

/**
 * Counts hits per key in fixed windows. Used by the Throttle stage.
 * Built in: DatabaseRateLimiter (default), ApcuRateLimiter (in memory, one server), ArrayRateLimiter (tests).
 */
interface RateLimiter
{
    /**
     * Record one hit for $key and return the count in the current window and when it resets.
     *
     * @return array{0: int, 1: int}  [hits, resetAt unix time]
     */
    public function hit(string $key, int $decaySeconds): array;
}

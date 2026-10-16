<?php

declare(strict_types=1);

namespace Pype\RateLimit;

/** In-memory counters for the life of the process. For tests. */
final class ArrayRateLimiter implements RateLimiter
{
    /** @var array<string, array{0: int, 1: int}> */
    private array $counters = [];

    public function hit(string $key, int $decaySeconds): array
    {
        $now = time();
        [$hits, $resetAt] = $this->counters[$key] ?? [0, 0];

        if ($resetAt <= $now) {
            [$hits, $resetAt] = [0, $now + $decaySeconds];
        }

        return $this->counters[$key] = [$hits + 1, $resetAt];
    }
}

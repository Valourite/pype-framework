<?php

declare(strict_types=1);

namespace Pype\RateLimit;

/**
 * Counters in APCu shared memory: no database round trip at all. Counts are per server
 * (fine for one box; use the database limiter behind a load balancer) and are lost on restart.
 */
final class ApcuRateLimiter implements RateLimiter
{
    public function __construct(private readonly string $prefix = 'pype:rl:')
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new \RuntimeException('RATE_LIMIT_DRIVER=apcu needs the apcu extension enabled (apc.enable_cli=1 for the CLI).');
        }
    }

    public function hit(string $key, int $decaySeconds): array
    {
        // Fixed windows aligned to the clock: the bucket id is part of the key, so an old
        // window's counter is simply never read again and expires on its own.
        $window = intdiv(time(), $decaySeconds);
        $hits = apcu_inc($this->prefix . $key . ':' . $window, 1, $ok, $decaySeconds);

        return [$ok ? (int) $hits : 1, ($window + 1) * $decaySeconds];
    }
}

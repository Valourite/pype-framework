<?php

declare(strict_types=1);

namespace Pype\Cache;

/**
 * Values in APCu shared memory: no disk or network at all. Per server and per PHP process
 * group (PHP-FPM pool, OpenSwoole server; the CLI has its own), lost on restart, and the
 * oldest entries are evicted when apc.shm_size is full.
 */
final class ApcuStore implements Store
{
    public function __construct()
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new \RuntimeException('CACHE_DRIVER=apcu needs the apcu extension enabled (apc.enable_cli=1 for the CLI).');
        }
    }

    public function fetch(string $key): array
    {
        $value = apcu_fetch($key, $found);

        return $found ? [true, $value] : [false, null];
    }

    public function put(string $key, mixed $value, ?int $seconds): bool
    {
        return apcu_store($key, $value, $seconds ?? 0);
    }

    public function add(string $key, mixed $value, ?int $seconds): bool
    {
        return apcu_add($key, $value, $seconds ?? 0);
    }

    public function increment(string $key, int $by): int
    {
        $value = apcu_inc($key, $by, $ok);

        // Older APCu versions don't create a missing key; add it, or increment whoever won.
        if (!$ok && apcu_add($key, $by)) {
            return $by;
        }

        if (!$ok) {
            $value = apcu_inc($key, $by, $ok);
        }

        if (!$ok) {
            throw new \RuntimeException("Could not increment cache key [{$key}]: its value is not an integer.");
        }

        return (int) $value;
    }

    public function forget(string $key): bool
    {
        apcu_delete($key);   // false when the key was already gone, which is fine

        return true;
    }

    public function flush(string $prefix): bool
    {
        apcu_delete(new \APCUIterator('/^' . preg_quote($prefix, '/') . '/', APC_ITER_KEY));

        return true;
    }
}

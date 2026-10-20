<?php

declare(strict_types=1);

namespace Pype\Cache;

/** Caching off: nothing is kept, every read misses. Locks are always granted. */
final class NullStore implements Store
{
    public function fetch(string $key): array
    {
        return [false, null];
    }

    public function put(string $key, mixed $value, ?int $seconds): bool
    {
        return true;
    }

    public function add(string $key, mixed $value, ?int $seconds): bool
    {
        return true;
    }

    public function increment(string $key, int $by): int
    {
        return $by;
    }

    public function forget(string $key): bool
    {
        return true;
    }

    public function flush(string $prefix): bool
    {
        return true;
    }
}

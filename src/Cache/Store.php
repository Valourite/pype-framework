<?php

declare(strict_types=1);

namespace Pype\Cache;

/**
 * Where a Cache keeps its values. Keys arrive already prefixed and validated by Cache.
 * Implement this for a backend of your own and set CACHE_DRIVER to its class name.
 */
interface Store
{
    /**
     * Found or missing, separately from the value, so null and false are cacheable.
     *
     * @return array{0: bool, 1: mixed}
     */
    public function fetch(string $key): array;

    /** @param int|null $seconds  null = no expiry */
    public function put(string $key, mixed $value, ?int $seconds): bool;

    /** Store only if the key is missing (or expired). Atomic: of two racing adds, one wins. */
    public function add(string $key, mixed $value, ?int $seconds): bool;

    /** Add $by (may be negative) to an integer value; a missing key starts from 0, with no expiry. */
    public function increment(string $key, int $by): int;

    public function forget(string $key): bool;

    /** Remove every key that starts with $prefix ('' = everything). */
    public function flush(string $prefix): bool;
}

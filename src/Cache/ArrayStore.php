<?php

declare(strict_types=1);

namespace Pype\Cache;

use Closure;

/** In memory for the life of the process. For tests. */
final class ArrayStore implements Store
{
    /** @var array<string, array{0: int|null, 1: mixed}> key => [expiresAt, value] */
    private array $items = [];

    /** @param (Closure(): int)|null $now  the clock, for tests */
    public function __construct(private readonly ?Closure $now = null)
    {
    }

    public function fetch(string $key): array
    {
        $item = $this->items[$key] ?? null;

        if ($item === null || ($item[0] !== null && $item[0] <= $this->now())) {
            return [false, null];
        }

        return [true, $item[1]];
    }

    public function put(string $key, mixed $value, ?int $seconds): bool
    {
        $this->items[$key] = [$seconds === null ? null : $this->now() + $seconds, $value];

        return true;
    }

    public function add(string $key, mixed $value, ?int $seconds): bool
    {
        return !$this->fetch($key)[0] && $this->put($key, $value, $seconds);
    }

    public function increment(string $key, int $by): int
    {
        [$found, $value] = $this->fetch($key);
        $value = ($found ? (int) $value : 0) + $by;
        $this->items[$key] = [$found ? $this->items[$key][0] : null, $value];

        return $value;
    }

    public function forget(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush(string $prefix): bool
    {
        foreach (array_keys($this->items) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($this->items[$key]);
            }
        }

        return true;
    }

    private function now(): int
    {
        return $this->now === null ? time() : ($this->now)();
    }
}

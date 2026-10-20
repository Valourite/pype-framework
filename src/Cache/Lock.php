<?php

declare(strict_types=1);

namespace Pype\Cache;

use Closure;

/**
 * A lock shared by every process using the same cache store, held for at most $seconds
 * (so a crashed holder can't block forever). Built on the store's atomic add().
 *
 *   $cache->lock('send-invoices', 120)->run(fn () => $invoices->send());   // null if busy
 *
 * Only the owner (this object) can release it. With the apcu or file store the lock is per
 * server; use the database store to lock across servers.
 */
final class Lock
{
    public readonly string $owner;

    public function __construct(
        private readonly Store $store,
        private readonly string $key,
        private readonly int $seconds,
        ?string $owner = null,
    ) {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('A lock must be held for at least 1 second.');
        }

        $this->owner = $owner ?? bin2hex(random_bytes(16));
    }

    /** Take the lock; false if someone else holds it. */
    public function get(): bool
    {
        return $this->store->add($this->key, $this->owner, $this->seconds);
    }

    /** Let go, if this object still holds it (it may have expired and been taken by someone else). */
    public function release(): bool
    {
        [$found, $owner] = $this->store->fetch($this->key);

        return $found && $owner === $this->owner && $this->store->forget($this->key);
    }

    /**
     * Run $callback while holding the lock, then release it. Returns the callback's result,
     * or null without running it if the lock is busy.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T|null
     */
    public function run(Closure $callback): mixed
    {
        if (!$this->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }
}

<?php

declare(strict_types=1);

namespace Pype\Cache;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Pype\Http\AfterResponse;
use Pype\Http\Request;

/**
 * The cache (PSR-16, plus the helpers apps actually reach for). Type-hint Cache (or
 * Psr\SimpleCache\CacheInterface) in a handler or stage.
 *
 *   $totals = $cache->remember("totals.{$id}", 300, fn () => $repo->totals($id));
 *   $cache->group("user.{$id}")->flush();                  // everything cached for that user
 *   $cache->lock('send-invoices', 120)->run(fn () => ...);
 *
 * Keys follow PSR-16: {}()/\@: are reserved, so use dots ("totals.5.2026-10"). Every key gets
 * the configured prefix, and clear() only removes keys with it. TTLs are seconds, a
 * DateInterval, or null for no expiry; a TTL of 0 or less deletes. null is cacheable.
 */
final class Cache implements CacheInterface
{
    private const RESERVED = '{}()/\@:';

    /**
     * @param (Closure(): int)|null $now  the clock, for tests
     * @param (Closure(): ?Request)|null $request  the request being handled, if any (for stale())
     */
    public function __construct(
        private readonly Store $store,
        private string $prefix = 'pype.',
        private readonly ?Closure $now = null,
        private readonly ?Closure $request = null,
        private readonly ?string $groupKey = null,
    ) {
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->store->fetch($this->key($key));

        return $found ? $value : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $seconds = $this->seconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        return $this->store->put($this->key($key), $value, $seconds);
    }

    public function delete(string $key): bool
    {
        return $this->store->forget($this->key($key));
    }

    /** Removes only this cache's keys (those with its prefix, or this group's). */
    public function clear(): bool
    {
        return $this->store->flush($this->prefix);
    }

    /** @return array<string, mixed> */
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;

        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;

        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        return $this->store->fetch($this->key($key))[0];
    }

    /**
     * The cached value, or $callback's result cached for $ttl.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function remember(string $key, null|int|DateInterval $ttl, Closure $callback): mixed
    {
        [$found, $value] = $this->store->fetch($this->key($key));

        if ($found) {
            return $value;
        }

        $this->set($key, $value = $callback(), $ttl);

        return $value;
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value);
    }

    /** Get and delete. */
    public function pull(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->store->fetch($k = $this->key($key));

        if (!$found) {
            return $default;
        }

        $this->store->forget($k);

        return $value;
    }

    /** Store only if the key is missing; atomic, so of two racing adds exactly one returns true. */
    public function add(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $seconds = $this->seconds($ttl);

        return ($seconds === null || $seconds > 0) && $this->store->add($this->key($key), $value, $seconds);
    }

    /** Atomic. A missing key starts from 0 (and never expires). Returns the new value. */
    public function increment(string $key, int $by = 1): int
    {
        return $this->store->increment($this->key($key), $by);
    }

    public function decrement(string $key, int $by = 1): int
    {
        return $this->store->increment($this->key($key), -$by);
    }

    /** A lock named $name, held for at most $seconds once taken. See Lock. */
    public function lock(string $name, int $seconds): Lock
    {
        return new Lock($this->store, $this->key('lock.' . $name), $seconds);
    }

    /**
     * Serve a value that may be a little old, and refresh it in the background:
     *  - younger than $fresh seconds: the cached value;
     *  - then, until $fresh + $stale seconds: still the cached value, and the callback runs
     *    after the response has been sent (one process at a time, guarded by a lock);
     *  - missing or older than that: the callback runs now.
     * Outside a request (CLI, queue) the refresh runs immediately.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function stale(string $key, int $fresh, int $stale, Closure $callback): mixed
    {
        [$found, $entry] = $this->store->fetch($this->key($key));

        if (!$found || !is_array($entry) || !array_key_exists('pype.stale', $entry)) {
            return $this->putStale($key, $fresh, $stale, $callback);
        }

        if ($entry['fresh'] > $this->now()) {
            return $entry['pype.stale'];
        }

        $lock = $this->lock('refresh.' . $key, max(1, $stale));

        if ($lock->get()) {
            $refresh = function () use ($lock, $key, $fresh, $stale, $callback): void {
                try {
                    $this->putStale($key, $fresh, $stale, $callback);
                } finally {
                    $lock->release();
                }
            };

            $request = $this->request === null ? null : ($this->request)();
            $request === null ? $refresh() : AfterResponse::of($request)->push($refresh);
        }

        return $entry['pype.stale'];
    }

    /**
     * A cache whose keys belong to the group $name, with the same API. flush() empties the
     * whole group with one write: the group's version number goes up, so its old keys are
     * simply never read again and expire on their own. A group object keeps the version it
     * was made with, so call group() again rather than holding on to one across requests.
     *
     *   $cache->group("user.{$id}")->remember('totals', 300, fn () => ...);
     *   $cache->group("user.{$id}")->flush();
     */
    public function group(string $name): self
    {
        $versionKey = $this->key('groupver.' . $name);
        [$found, $version] = $this->store->fetch($versionKey);

        if (!$found) {
            // Start anywhere, not at 0: if the version key is ever evicted (APCu memory full),
            // the group must not land back on a version whose keys are still cached.
            $version = random_int(1, 2 ** 52);

            if (!$this->store->add($versionKey, $version, null)) {
                [, $version] = $this->store->fetch($versionKey);
            }
        }

        return new self($this->store, $this->groupPrefix($name, (int) $version), $this->now, $this->request, $versionKey);
    }

    /** Empty this group (see group()). On the root cache it is the same as clear(). */
    public function flush(): bool
    {
        if ($this->groupKey === null) {
            return $this->clear();
        }

        $version = $this->store->increment($this->groupKey, 1);
        $this->prefix = preg_replace('/\.v\d+\.$/', ".v{$version}.", $this->prefix);

        return true;
    }

    private function groupPrefix(string $name, int $version): string
    {
        return $this->prefix . "group.{$name}.v{$version}.";
    }

    private function putStale(string $key, int $fresh, int $stale, Closure $callback): mixed
    {
        $value = $callback();
        $this->store->put($this->key($key), ['pype.stale' => $value, 'fresh' => $this->now() + $fresh], $fresh + $stale);

        return $value;
    }

    private function key(string $key): string
    {
        if ($key === '' || strpbrk($key, self::RESERVED) !== false) {
            throw new InvalidArgument("Invalid cache key [{$key}]: it must be non-empty and not contain " . self::RESERVED);
        }

        return $this->prefix . $key;
    }

    private function seconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl instanceof DateInterval) {
            $now = (new DateTimeImmutable())->setTimestamp($this->now());

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }

    private function now(): int
    {
        return $this->now === null ? time() : ($this->now)();
    }
}

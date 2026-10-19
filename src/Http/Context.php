<?php

declare(strict_types=1);

namespace Pype\Http;

use InvalidArgumentException;
use RuntimeException;

/**
 * Typed, per-request store that stages use to hand data to each other.
 *
 * Entries are keyed by class (or interface) name, so what you get back is
 * always the type you asked for — no stringly-typed keys, no guessing:
 *
 *   $request->context()->put($user);                     // keyed by User::class
 *   $request->context()->put($user, Authenticatable::class);
 *
 *   $user = $request->context()->get(User::class);       // User, or throws
 *   $user = $request->context()->find(User::class);      // ?User
 */
final class Context
{
    /** @var array<class-string, object> */
    private array $items = [];

    /**
     * @template T of object
     * @param T $value
     * @param class-string<T>|null $as  store under an interface/parent instead of the concrete class
     */
    public function put(object $value, ?string $as = null): self
    {
        $as ??= $value::class;

        if (!$value instanceof $as) {
            throw new InvalidArgumentException(
                sprintf('Cannot store %s in context as %s: type mismatch.', $value::class, $as)
            );
        }

        $this->items[$as] = $value;

        return $this;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function get(string $type): object
    {
        return $this->items[$type] ?? throw new RuntimeException(
            "Context has no [{$type}]. Did an earlier stage forget to put() it?"
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T|null
     */
    public function find(string $type): ?object
    {
        return $this->items[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->items[$type]);
    }

    /** @return list<class-string> what's in the context (for debugging) */
    public function classes(): array
    {
        return array_keys($this->items);
    }

    /** @param class-string $type */
    public function forget(string $type): self
    {
        unset($this->items[$type]);

        return $this;
    }
}

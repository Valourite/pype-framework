<?php

declare(strict_types=1);

namespace Pype\Database;

/**
 * Fake::unique(): the same methods, but never a value this proxy has returned before.
 *
 * @mixin Fake
 */
final class UniqueFake
{
    /** @var array<string, array<string, true>> method => seen values */
    private array $seen = [];

    public function __construct(private readonly Fake $fake)
    {
    }

    public function __call(string $method, array $args): mixed
    {
        for ($try = 0; $try < 1000; $try++) {
            $value = $this->fake->{$method}(...$args);
            $key = serialize($value);

            if (!isset($this->seen[$method][$key])) {
                $this->seen[$method][$key] = true;

                return $value;
            }
        }

        throw new \OverflowException("Fake::unique() ran out of new values for {$method}() after 1000 tries.");
    }
}

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

    /** Random tries before numbering a value instead (word() has ~500 words; a seeder may want more). */
    private const TRIES = 50;

    public function __construct(private readonly Fake $fake)
    {
    }

    public function __call(string $method, array $args): mixed
    {
        for ($try = 0; $try < self::TRIES; $try++) {
            $value = $this->fake->{$method}(...$args);

            if ($this->fresh($method, $value)) {
                return $value;
            }
        }

        // The list is (nearly) used up: number a string value (desk2, ada.smith2@example.test).
        if (is_string($value)) {
            for ($n = 2; ; $n++) {
                $numbered = str_contains($value, '@') ? preg_replace('/@/', "{$n}@", $value, 1) : "{$value}{$n}";

                if ($this->fresh($method, $numbered)) {
                    return $numbered;
                }
            }
        }

        for ($try = 0; $try < 1000; $try++) {
            if ($this->fresh($method, $value = $this->fake->{$method}(...$args))) {
                return $value;
            }
        }

        throw new \OverflowException("Fake::unique() ran out of new values for {$method}().");
    }

    private function fresh(string $method, mixed $value): bool
    {
        $key = serialize($value);

        if (isset($this->seen[$method][$key])) {
            return false;
        }

        return $this->seen[$method][$key] = true;
    }
}

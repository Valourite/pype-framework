<?php

// No strict_types here, on purpose: the constructor call below then uses PHP's coercive typing,
// which turns SQLite's 0/1 into bool, numeric strings into int/float and numbers into string in
// C, ~2.5x faster than casting each value in PHP (20-row feed: 8.6 -> 3.6 µs, twelve bench).

namespace Pype\Database;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Rows into data classes: `new $class(...$row)` with only the columns the constructor takes,
 * so extra columns (a password hash, a join's leftovers) never leak in. Used by
 * QueryBuilder::as() and Factory.
 *
 * Values become the parameter's declared type when the database hands back another one:
 * SQLite's 0/1 into `bool`, MySQL/Postgres numeric strings into `int`/`float`, a number into
 * `string` (PHP's own coercion), a date string into `DateTimeImmutable`/`DateTimeInterface`, and a
 * value into a backed enum (`Status::from()`). Untyped, union-typed and `mixed` parameters get the
 * value as is. A value PHP can't coerce (e.g. 'abc' into `int`) is a TypeError.
 */
final class Hydrator
{
    /** @var array<class-string, array<string, true>> the constructor's parameter names */
    private static array $params = [];

    /** @var array<class-string, array<string, string>> parameter name => 'date' or an enum class, for those that need a conversion PHP doesn't do */
    private static array $convert = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $row
     * @return T
     */
    public static function make(string $class, array $row): object
    {
        $params = self::$params[$class] ?? self::inspect($class);
        $args = array_intersect_key($row, $params);

        foreach (self::$convert[$class] as $name => $to) {
            if (isset($args[$name])) {
                $args[$name] = self::convert($to, $args[$name]);
            }
        }

        return new $class(...$args);
    }

    private static function convert(string $to, mixed $value): mixed
    {
        if ($to === 'date') {
            return is_string($value) ? new DateTimeImmutable($value) : $value;
        }

        return $value instanceof BackedEnum ? $value : $to::from($value);
    }

    /** @return array<string, true> */
    private static function inspect(string $class): array
    {
        $params = $convert = [];

        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $p) {
            $params[$p->getName()] = true;
            $type = $p->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            if ($name === DateTimeImmutable::class || $name === DateTimeInterface::class) {
                $convert[$p->getName()] = 'date';
            } elseif ($name !== null && is_subclass_of($name, BackedEnum::class)) {
                $convert[$p->getName()] = $name;
            }
        }

        self::$convert[$class] = $convert;

        return self::$params[$class] = $params;
    }
}

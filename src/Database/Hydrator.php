<?php

declare(strict_types=1);

namespace Pype\Database;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Rows into data classes: `new $class(...$row)` with only the columns the constructor takes,
 * so extra columns (a password hash, a join's leftovers) never leak in. Used by
 * QueryBuilder::as() and Factory.
 *
 * Values are cast to the parameter's declared type when the database hands back another one:
 * SQLite's 0/1 into `bool`, MySQL/Postgres numeric strings into `int`/`float`, a number into
 * `string`, a date string into `DateTimeImmutable`/`DateTimeInterface`, and a value into a backed
 * enum (`Status::from()`). Untyped, union-typed and `mixed` parameters get the value as is.
 */
final class Hydrator
{
    /** @var array<class-string, array<string, string|null>> parameter name => cast ('bool', 'int', 'float', 'string', 'date', an enum class) or null */
    private static array $params = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $row
     * @return T
     */
    public static function make(string $class, array $row): object
    {
        $params = self::$params[$class] ??= self::params($class);
        $args = array_intersect_key($row, $params);

        foreach ($args as $name => $value) {
            if ($value !== null && ($cast = $params[$name]) !== null) {
                $args[$name] = self::cast($cast, $value);
            }
        }

        return new $class(...$args);
    }

    private static function cast(string $cast, mixed $value): mixed
    {
        return match ($cast) {
            'bool' => is_bool($value) ? $value : (bool) $value,
            'int' => is_int($value) || !is_numeric($value) ? $value : (int) $value,
            'float' => is_float($value) || !is_numeric($value) ? $value : (float) $value,
            'string' => is_int($value) || is_float($value) ? (string) $value : $value,
            'date' => is_string($value) ? new DateTimeImmutable($value) : $value,
            default => $value instanceof BackedEnum ? $value : $cast::from($value),
        };
    }

    /** @return array<string, string|null> */
    private static function params(string $class): array
    {
        $params = [];

        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $p) {
            $type = $p->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            $params[$p->getName()] = match (true) {
                $name === null => null,
                in_array($name, ['bool', 'int', 'float', 'string'], true) => $name,
                $name === DateTimeImmutable::class, $name === DateTimeInterface::class => 'date',
                is_subclass_of($name, BackedEnum::class) => $name,
                default => null,
            };
        }

        return $params;
    }
}

<?php

declare(strict_types=1);

namespace Pype\Database;

/**
 * Rows into data classes: `new $class(...$row)` with only the columns the constructor takes,
 * so extra columns (a password hash, a join's leftovers) never leak in. Used by
 * QueryBuilder::as() and Factory.
 */
final class Hydrator
{
    /** @var array<class-string, array<string, true>> constructor parameter names per class */
    private static array $params = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $row
     * @return T
     */
    public static function make(string $class, array $row): object
    {
        $params = self::$params[$class] ??= array_fill_keys(array_map(
            static fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [],
        ), true);

        return new $class(...array_intersect_key($row, $params));
    }
}

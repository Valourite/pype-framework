<?php

declare(strict_types=1);

namespace Pype\Database;

use InvalidArgumentException;

/**
 * Small fluent query builder. Every value is bound; identifiers are validated and quoted.
 *
 *   $db->table('users')->where('active', 1)->orderBy('name')->limit(10)->get();
 *   $db->table('users')->where('id', 5)->as(User::class)->first();   // hydrate via named constructor args
 *   $id = $db->table('users')->insert(['name' => 'Ada']);
 *   $db->table('users')->where('id', $id)->update(['name' => 'Ada L.']);
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<array{0: 'AND'|'OR', 1: string}> */
    private array $wheres = [];

    /** @var list<mixed> */
    private array $bindings = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;

    /** @var class-string|null */
    private ?string $as = null;

    public function __construct(private readonly Connection $db, private readonly string $table)
    {
        $this->identifier($table);
    }

    public function select(string ...$columns): self
    {
        array_map($this->identifier(...), $columns);
        $this->columns = $columns;

        return $this;
    }

    /** where('id', 5) or where('age', '>=', 18) */
    public function where(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND'): self
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }

        $operator = strtolower((string) $operator);

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid operator [{$operator}].");
        }

        if ($value === null) {
            return $this->whereNull($column, $operator !== '=', $boolean);
        }

        $this->wheres[] = [$boolean, $this->col($column) . " {$operator} ?"];
        $this->bindings[] = $value;

        return $this;
    }

    public function orWhere(string $column, mixed $operator, mixed $value = null): self
    {
        return func_num_args() === 2
            ? $this->where($column, '=', $operator, 'OR')
            : $this->where($column, $operator, $value, 'OR');
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values, bool $not = false): self
    {
        if ($values === []) {
            $this->wheres[] = ['AND', $not ? '1 = 1' : '1 = 0'];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = ['AND', $this->col($column) . ($not ? ' NOT IN ' : ' IN ') . "({$placeholders})"];
        array_push($this->bindings, ...array_values($values));

        return $this;
    }

    public function whereNull(string $column, bool $not = false, string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, $this->col($column) . ($not ? ' IS NOT NULL' : ' IS NULL')];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Invalid order direction [{$direction}].");
        }

        $this->orders[] = $this->col($column) . ' ' . $direction;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * Hydrate rows into objects using named constructor arguments (column name = parameter name).
     * Columns without a matching parameter are ignored.
     *
     * @param class-string $class
     */
    public function as(string $class): self
    {
        $this->as = $class;

        return $this;
    }

    /** @return list<array<string, mixed>|object> */
    public function get(): array
    {
        return array_map($this->hydrate(...), $this->db->select($this->toSql(), $this->bindings));
    }

    /** @return array<string, mixed>|object|null */
    public function first(): array|object|null
    {
        $row = $this->db->first((clone $this)->limit(1)->toSql(), $this->bindings);

        return $row === null ? null : $this->hydrate($row);
    }

    public function value(string $column): mixed
    {
        $query = (clone $this)->select($column)->limit(1);

        return $this->db->scalar($query->toSql(), $this->bindings);
    }

    public function count(): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->col($this->table) . $this->whereSql();

        return (int) $this->db->scalar($sql, $this->bindings);
    }

    public function exists(): bool
    {
        $sql = 'SELECT EXISTS(SELECT 1 FROM ' . $this->col($this->table) . $this->whereSql() . ')';

        return (bool) $this->db->scalar($sql, $this->bindings);
    }

    /** @param array<string, mixed> $values  @return string the new row id */
    public function insert(array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }

        $columns = implode(', ', array_map($this->col(...), array_keys($values)));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->db->execute(
            'INSERT INTO ' . $this->col($this->table) . " ({$columns}) VALUES ({$placeholders})",
            array_values($values),
        );

        return $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $values  @return int affected rows */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $sets = implode(', ', array_map(fn (string $c) => $this->col($c) . ' = ?', array_keys($values)));

        return $this->db->execute(
            'UPDATE ' . $this->col($this->table) . " SET {$sets}" . $this->whereSql(),
            [...array_values($values), ...$this->bindings],
        );
    }

    /** @return int affected rows */
    public function delete(): int
    {
        return $this->db->execute('DELETE FROM ' . $this->col($this->table) . $this->whereSql(), $this->bindings);
    }

    public function toSql(): string
    {
        $sql = 'SELECT ' . implode(', ', array_map($this->col(...), $this->columns))
            . ' FROM ' . $this->col($this->table)
            . $this->whereSql();

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            // SQLite/MySQL need a LIMIT before OFFSET; use the max to mean "no limit".
            $sql .= ($this->limit === null && $this->db->driver() !== 'pgsql' ? ' LIMIT ' . PHP_INT_MAX : '')
                . ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /** @return list<mixed> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    private function whereSql(): string
    {
        $sql = '';

        foreach ($this->wheres as $i => [$boolean, $clause]) {
            $sql .= ($i === 0 ? ' WHERE ' : " {$boolean} ") . $clause;
        }

        return $sql;
    }

    private function hydrate(array $row): array|object
    {
        if ($this->as === null) {
            return $row;
        }

        // Pass only columns the constructor accepts, so extra columns (e.g. password) never leak in.
        return new ($this->as)(...array_intersect_key($row, self::constructorParams($this->as)));
    }

    /** @return array<string, true> */
    private static function constructorParams(string $class): array
    {
        static $cache = [];

        return $cache[$class] ??= array_fill_keys(array_map(
            static fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [],
        ), true);
    }

    private function col(string $identifier): string
    {
        return $this->db->quote($this->identifier($identifier));
    }

    private function identifier(string $identifier): string
    {
        if ($identifier !== '*' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.([A-Za-z_][A-Za-z0-9_]*|\*))?$/', $identifier)) {
            throw new InvalidArgumentException("Invalid identifier [{$identifier}].");
        }

        return $identifier;
    }
}

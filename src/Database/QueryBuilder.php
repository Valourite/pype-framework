<?php

declare(strict_types=1);

namespace Pype\Database;

use InvalidArgumentException;
use LogicException;

/**
 * Small fluent query builder. Every value is bound; identifiers are validated and quoted.
 *
 *   $db->table('users')->where('active', 1)->orderBy('name')->limit(10)->get();
 *   $db->table('users')->where('id', 5)->as(User::class)->first();   // hydrate via named constructor args
 *   $id = $db->table('users')->insert(['name' => 'Ada']);
 *   $db->table('users')->where('id', $id)->update(['name' => 'Ada L.']);
 *
 * Joins, aliases and aggregates:
 *
 *   $db->table('expenses as e')
 *       ->join('categories as c', 'c.id', '=', 'e.category_id')
 *       ->select('c.name as category')
 *       ->selectRaw('SUM(e.amount_cents) AS total')
 *       ->where('e.user_id', $userId)
 *       ->groupBy('c.id')
 *       ->orderBy('total', 'desc')
 *       ->get();
 *
 *   $db->table('expenses')->where('user_id', $userId)->sum('amount_cents');
 *
 * selectRaw()/whereRaw() take SQL as written: never put user input in the string, bind it.
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];

    /** "name", "table.name", "table.*" — optionally followed by "as alias". */
    private const IDENTIFIER = '/^([A-Za-z_][A-Za-z0-9_]*(?:\.(?:[A-Za-z_][A-Za-z0-9_]*|\*))?)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$/i';

    /** @var list<string> compiled select expressions */
    private array $columns = [];

    /** @var list<mixed> */
    private array $selectBindings = [];

    /** @var list<string> */
    private array $joins = [];

    /** @var list<array{0: 'AND'|'OR', 1: string}> */
    private array $wheres = [];

    /** @var list<mixed> */
    private array $bindings = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<array{0: 'AND'|'OR', 1: string}> */
    private array $havings = [];

    /** @var list<mixed> */
    private array $havingBindings = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;

    /** @var class-string|null */
    private ?string $as = null;

    /** @param string $table  "users" or "users as u" */
    public function __construct(private readonly Connection $db, private readonly string $table)
    {
        $this->col($table);
    }

    /** Replace the selected columns. Each may be aliased: select('id', 'c.name as category'). */
    public function select(string ...$columns): self
    {
        $this->columns = [];
        $this->selectBindings = [];

        return $this->addSelect(...$columns);
    }

    public function addSelect(string ...$columns): self
    {
        array_push($this->columns, ...array_map($this->col(...), $columns));

        return $this;
    }

    /** Add a raw select expression: selectRaw('COUNT(*) AS n'), selectRaw('price * ? AS gross', [1.15]). */
    public function selectRaw(string $sql, array $bindings = []): self
    {
        $this->columns[] = $sql;
        array_push($this->selectBindings, ...array_values($bindings));

        return $this;
    }

    /** join('categories as c', 'c.id', '=', 'e.category_id') */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $type = strtoupper($type);

        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
            throw new InvalidArgumentException("Invalid join type [{$type}].");
        }

        $this->joins[] = "{$type} JOIN " . $this->col($table) . ' ON ' . $this->col($first) . ' ' . $this->operator($operator) . ' ' . $this->col($second);

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /** where('id', 5) or where('age', '>=', 18) */
    public function where(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND'): self
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }

        $operator = $this->operator((string) $operator);

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

    /** whereRaw('date(created_at) = ?', ['2026-10-15']) */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, "({$sql})"];
        array_push($this->bindings, ...array_values($bindings));

        return $this;
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

    public function groupBy(string ...$columns): self
    {
        array_push($this->groups, ...array_map($this->col(...), $columns));

        return $this;
    }

    /** having('total', '>', 100) — column or select alias. */
    public function having(string $column, string $operator, mixed $value, string $boolean = 'AND'): self
    {
        $this->havings[] = [$boolean, $this->col($column) . ' ' . $this->operator($operator) . ' ?'];
        $this->havingBindings[] = $value;

        return $this;
    }

    /** havingRaw('SUM(amount) > ?', [100]) */
    public function havingRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->havings[] = [$boolean, "({$sql})"];
        array_push($this->havingBindings, ...array_values($bindings));

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
        return array_map($this->hydrate(...), $this->db->select($this->toSql(), $this->bindings()));
    }

    /** @return array<string, mixed>|object|null */
    public function first(): array|object|null
    {
        $row = $this->db->first((clone $this)->limit(1)->toSql(), $this->bindings());

        return $row === null ? null : $this->hydrate($row);
    }

    public function value(string $column): mixed
    {
        $query = (clone $this)->select($column)->limit(1);

        return $this->db->scalar($query->toSql(), $query->bindings());
    }

    public function count(): int
    {
        return (int) $this->aggregate('COUNT', '*');
    }

    public function exists(): bool
    {
        $query = (clone $this)->select()->selectRaw('1')->limit(1);
        $query->orders = [];

        return (bool) $this->db->scalar('SELECT EXISTS(' . $query->toSql() . ')', $query->bindings());
    }

    public function sum(string $column): int|float
    {
        return $this->aggregate('SUM', $column) ?? 0;
    }

    public function avg(string $column): int|float|null
    {
        return $this->aggregate('AVG', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column, numeric: false);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column, numeric: false);
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
        $this->assertSimple('update');

        if ($values === []) {
            return 0;
        }

        $sets = implode(', ', array_map(fn (string $c) => $this->col($c) . ' = ?', array_keys($values)));

        return $this->db->execute(
            'UPDATE ' . $this->col($this->table) . " SET {$sets}" . $this->clauses($this->wheres, 'WHERE'),
            [...array_values($values), ...$this->bindings],
        );
    }

    /** @return int affected rows */
    public function delete(): int
    {
        $this->assertSimple('delete');

        return $this->db->execute('DELETE FROM ' . $this->col($this->table) . $this->clauses($this->wheres, 'WHERE'), $this->bindings);
    }

    public function toSql(): string
    {
        $sql = 'SELECT ' . ($this->columns === [] ? '*' : implode(', ', $this->columns))
            . ' FROM ' . $this->col($this->table)
            . ($this->joins === [] ? '' : ' ' . implode(' ', $this->joins))
            . $this->clauses($this->wheres, 'WHERE');

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        $sql .= $this->clauses($this->havings, 'HAVING');

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

    /** @return list<mixed> in placeholder order */
    public function bindings(): array
    {
        return [...$this->selectBindings, ...$this->bindings, ...$this->havingBindings];
    }

    /** Run FN(column) over the matching rows. Grouped queries are aggregated over their groups. */
    private function aggregate(string $function, string $column, bool $numeric = true): mixed
    {
        $expression = $function . '(' . ($column === '*' ? '*' : $this->col($column)) . ')';

        if ($this->groups !== [] || $this->limit !== null || $this->offset !== null) {
            // Aggregate the grouped/limited result, not the raw table.
            $inner = clone $this;

            if ($inner->limit === null && $inner->offset === null) {
                $inner->orders = [];   // order only matters when it decides which rows a limit keeps
            }

            if ($column !== '*') {
                $inner->select($column . ' as aggregate');
                $expression = "{$function}(" . $this->db->quote('aggregate') . ')';
            } elseif ($inner->columns === []) {
                $inner->selectRaw('1');
            }

            $value = $this->db->scalar("SELECT {$expression} FROM (" . $inner->toSql() . ') AS ' . $this->db->quote('sub'), $inner->bindings());
        } else {
            $query = (clone $this)->select()->selectRaw($expression);
            $query->orders = [];
            $value = $this->db->scalar($query->toSql(), $query->bindings());
        }

        return $numeric && is_string($value) && is_numeric($value) ? $value + 0 : $value;
    }

    private function assertSimple(string $action): void
    {
        if ($this->joins !== [] || $this->groups !== [] || $this->havings !== []) {
            throw new LogicException("Cannot {$action} with joins or grouping; use a where on the base table.");
        }
    }

    /** @param list<array{0: string, 1: string}> $clauses */
    private function clauses(array $clauses, string $keyword): string
    {
        $sql = '';

        foreach ($clauses as $i => [$boolean, $clause]) {
            $sql .= ($i === 0 ? " {$keyword} " : " {$boolean} ") . $clause;
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

    private function operator(string $operator): string
    {
        $operator = strtolower($operator);

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid operator [{$operator}].");
        }

        return $operator;
    }

    /** Validate and quote "name", "t.name", "t.*", "*", each optionally "... as alias". */
    private function col(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '*') {
            return '*';
        }

        if (!preg_match(self::IDENTIFIER, $identifier, $m)) {
            throw new InvalidArgumentException("Invalid identifier [{$identifier}].");
        }

        return $this->db->quote($m[1]) . (isset($m[2]) ? ' AS ' . $this->db->quote($m[2]) : '');
    }
}

<?php

declare(strict_types=1);

namespace Pype\Database;

use Closure;
use InvalidArgumentException;
use LogicException;
use Pype\Http\Request;

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

    /** @var array<int, string> join index => alias, for LEFT JOINs on the joined table's id (see count()) */
    private array $countFreeJoins = [];

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

    /** @var list<mixed> */
    private array $orderBindings = [];

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

        // LEFT JOIN users AS a ON a.id = t.assignee_id matches at most one row per base row, so a
        // COUNT(*) doesn't need it (unless a where or another join reads it; count() checks that).
        preg_match(self::IDENTIFIER, trim($table), $t);
        $alias = $t[2] ?? $t[1] ?? '';
        if ($type === 'LEFT' && $operator === '=' && in_array("{$alias}.id", [trim($first), trim($second)], true)) {
            $this->countFreeJoins[array_key_last($this->joins)] = $alias;
        }

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * where('id', 5) or where('age', '>=', 18). A closure groups its conditions in parentheses:
     *
     *   ->where('team_id', $id)->where(fn (QueryBuilder $q) => $q->where('subject', 'like', $s)->orWhere('email', 'like', $s))
     *   // WHERE team_id = ? AND (subject LIKE ? OR email LIKE ?)
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        if ($column instanceof Closure) {
            return $this->whereGroup($column, $boolean);
        }

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

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if ($column instanceof Closure) {
            return $this->whereGroup($column, 'OR');
        }

        return func_num_args() === 2
            ? $this->where($column, '=', $operator, 'OR')
            : $this->where($column, $operator, $value, 'OR');
    }

    /** The conditions the closure adds, in parentheses: an empty group adds nothing. */
    private function whereGroup(Closure $group, string $boolean): self
    {
        $query = new self($this->db, $this->table);
        $group($query);

        if ($query->wheres !== []) {
            $this->wheres[] = [$boolean, '(' . trim($query->clauses($query->wheres, '')) . ')'];
            array_push($this->bindings, ...$query->bindings);
        }

        return $this;
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

    /** having('total', '>', 100): a column, or a select alias on SQLite/MySQL (Postgres needs havingRaw('SUM(x) > ?')). */
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

    /**
     * An ORDER BY expression as written, with bindings:
     * orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 ELSE 3 END"),
     * orderByRaw('ABS(amount - ?) ASC', [100]). Never put user input in $sql; bind it.
     */
    public function orderByRaw(string $sql, array $bindings = []): self
    {
        $this->orders[] = $sql;
        array_push($this->orderBindings, ...array_values($bindings));

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

    /**
     * One page of results plus the total count.
     *
     *   $db->table('quotes')->where('company_id', $id)->latest()->paginate(25, $request)
     *
     * Given the Request, the page number comes from ?page= and the page's links keep the other
     * query parameters. Given an int, links are '?page=N' only. The page is clamped to 1..last:
     * past the end (?page=99999) you get the last page.
     *
     * $count replaces the default COUNT(*) over this whole query (joins and all): a cheaper
     * query that counts the same rows, or a number you already have (cached, say).
     */
    public function paginate(int $perPage = 15, int|Request $page = 1, self|int|null $count = null, string $pageName = 'page'): Page
    {
        $perPage = max(1, $perPage);
        [$number, $urls] = self::pageAndUrls($page, $pageName);

        $total = match (true) {
            is_int($count) => max(0, $count),
            $count instanceof self => $count->count(),
            default => (clone $this)->count(),
        };

        // Past the end (?page=99999, or rows deleted since the link was made): show the last page.
        $number = min($number, max(1, (int) ceil($total / $perPage)));
        $items = $total === 0 ? [] : (clone $this)->limit($perPage)->offset(($number - 1) * $perPage)->get();

        return new Page($items, $total, $perPage, $number, $urls);
    }

    /**
     * A page without a total: fetches $perPage + 1 rows to know whether there is a next page,
     * and runs no COUNT(*). Previous/next links only. A page past the end is simply empty.
     */
    public function simplePaginate(int $perPage = 15, int|Request $page = 1, string $pageName = 'page'): SimplePage
    {
        $perPage = max(1, $perPage);
        [$number, $urls] = self::pageAndUrls($page, $pageName);

        $rows = (clone $this)->limit($perPage + 1)->offset(($number - 1) * $perPage)->get();
        $hasMore = count($rows) > $perPage;

        return new SimplePage($hasMore ? array_slice($rows, 0, $perPage) : $rows, $perPage, $number, $hasMore, $urls);
    }

    /** @return array{0: int, 1: PageUrls|null} */
    private static function pageAndUrls(int|Request $page, string $pageName): array
    {
        return $page instanceof Request
            ? [PageUrls::pageFrom($page, $pageName), PageUrls::from($page, $pageName)]
            : [max(1, $page), null];
    }

    public function value(string $column): mixed
    {
        $query = (clone $this)->select($column)->limit(1);

        return $this->db->scalar($query->toSql(), $query->bindings());
    }

    /**
     * COUNT(*) of the query. LEFT JOINs on the joined table's id (`leftJoin('users as a', 'a.id',
     * '=', 't.assignee_id')`) are left out when nothing else reads them: they can't change the row
     * count, and counting through them cost ~10x on a 15,000-row list. This relies on `id` being
     * the joined table's primary key; if it isn't, pass paginate() a `count:`.
     */
    public function count(): int
    {
        return (int) $this->withoutCountFreeJoins()->aggregate('COUNT', '*');
    }

    private function withoutCountFreeJoins(): self
    {
        if ($this->countFreeJoins === [] || $this->groups !== [] || $this->limit !== null || $this->offset !== null) {
            return $this;
        }

        $query = clone $this;

        foreach ($this->countFreeJoins as $index => $alias) {
            $elsewhere = [
                ...array_column($this->wheres, 1),
                ...array_column($this->havings, 1),
                ...array_values(array_diff_key($query->joins, [$index => true])),
            ];
            $used = preg_match('/(?<![\w.])' . preg_quote($alias, '/') . '\.|' . preg_quote($this->db->quote($alias) . '.', '/') . '/', implode("\n", $elsewhere));

            if (!$used) {
                unset($query->joins[$index], $query->countFreeJoins[$index]);
            }
        }

        $query->joins = array_values($query->joins);
        $query->countFreeJoins = [];

        return $query;
    }

    public function exists(): bool
    {
        $query = (clone $this)->select()->selectRaw('1')->limit(1);
        $query->orders = $query->orderBindings = [];

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

    /** @param array<string, mixed> $values  @return string the new row's id ('' on Postgres when the table has no id column) */
    public function insert(array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }

        $columns = implode(', ', array_map($this->col(...), array_keys($values)));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $sql = 'INSERT INTO ' . $this->col($this->table) . " ({$columns}) VALUES ({$placeholders})";

        // Postgres: PDO's lastInsertId() is LASTVAL(), which fails (and aborts the transaction)
        // when the table has no sequence. RETURNING gives the id without that trap.
        if ($this->db->driver() === 'pgsql') {
            $row = $this->db->first("{$sql} RETURNING *", array_values($values));

            return isset($row['id']) ? (string) $row['id'] : '';
        }

        $this->db->execute($sql, array_values($values));

        return $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $values  @return int affected rows */
    /**
     * Insert a row and get columns back, including ones the database filled (ids, defaults):
     *
     *   $row = $db->table('posts')->insertReturning(['user_id' => 1, 'body' => 'Hi'], ['id', 'created_at']);
     *
     * One INSERT ... RETURNING on SQLite and Postgres. MySQL has no RETURNING: there it's the
     * insert, then a SELECT of the columns by the new auto-increment `id`.
     *
     * @param array<string, mixed> $values
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    public function insertReturning(array $values, array $columns = ['*']): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }

        $select = implode(', ', array_map($this->col(...), $columns));

        if ($this->db->driver() === 'mysql') {
            return $this->db->transaction(function (Connection $db) use ($values, $select) {
                $id = $this->insert($values);

                return $db->first("SELECT {$select} FROM {$this->col($this->table)} WHERE {$this->col('id')} = ?", [(int) $id]) ?? [];
            });
        }

        $columnList = implode(', ', array_map($this->col(...), array_keys($values)));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return $this->db->first(
            'INSERT INTO ' . $this->col($this->table) . " ({$columnList}) VALUES ({$placeholders}) RETURNING {$select}",
            array_values($values),
        ) ?? [];
    }

    /**
     * Insert many rows in as few statements as the databases allow (multi-row VALUES, chunked
     * under their parameter limits), in one transaction. Every row needs the same columns.
     * Returns how many rows were inserted. For seeding and imports; use insert() for one row.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $width = count($columns);

        if ($width === 0) {
            throw new InvalidArgumentException('Cannot insert empty rows.');
        }

        // SQLite allows 32766 parameters per statement, MySQL and Postgres 65535; stay well under.
        $perStatement = max(1, min(1000, intdiv(30000, $width)));
        $into = 'INSERT INTO ' . $this->col($this->table) . ' (' . implode(', ', array_map($this->col(...), $columns)) . ') VALUES ';
        $tuple = '(' . implode(', ', array_fill(0, $width, '?')) . ')';

        return $this->db->transaction(function (Connection $db) use ($rows, $columns, $perStatement, $into, $tuple) {
            $inserted = 0;

            foreach (array_chunk($rows, $perStatement) as $chunk) {
                $bindings = [];

                foreach ($chunk as $row) {
                    if (count($row) !== count($columns) || array_diff_key($row, $rows[0]) !== []) {
                        throw new InvalidArgumentException('insertMany() needs the same columns in every row.');
                    }

                    foreach ($columns as $column) {
                        $bindings[] = $row[$column];
                    }
                }

                $inserted += $db->execute($into . implode(', ', array_fill(0, count($chunk), $tuple)), $bindings);
            }

            return $inserted;
        });
    }

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
        return [...$this->selectBindings, ...$this->bindings, ...$this->havingBindings, ...$this->orderBindings];
    }

    /** Run FN(column) over the matching rows. Grouped queries are aggregated over their groups. */
    private function aggregate(string $function, string $column, bool $numeric = true): mixed
    {
        $expression = $function . '(' . ($column === '*' ? '*' : $this->col($column)) . ')';

        if ($this->groups !== [] || $this->limit !== null || $this->offset !== null) {
            // Aggregate the grouped/limited result, not the raw table.
            $inner = clone $this;

            if ($inner->limit === null && $inner->offset === null) {
                $inner->orders = $inner->orderBindings = [];   // order only matters when it decides which rows a limit keeps
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
            $query->orders = $query->orderBindings = [];
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
        return $this->as === null ? $row : Hydrator::make($this->as, $row);
    }

    /** @return array<string, true> */

    private function operator(string $operator): string
    {
        $operator = strtolower($operator);

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid operator [{$operator}].");
        }

        return $operator;
    }

    /** Validate and quote "name", "t.name", "t.*", "*", each optionally "... as alias". */
    /**
     * Quoted identifiers, remembered per process: '"u"."username" AS "author"' for
     * 'u.username as author'. Identifiers come from code, so this is app-level data (worker-safe)
     * and saves the regex + quoting on every query (~4.5 µs of a ~6 µs query build, measured).
     * Capped, so identifiers built from user input can't grow a long-running worker's memory.
     *
     * @var array<string, string>
     */
    private static array $quoted = [];

    private const QUOTED_MAX = 1000;

    private function col(string $identifier): string
    {
        $key = $this->db->driver() . '|' . $identifier;

        if (isset(self::$quoted[$key])) {
            return self::$quoted[$key];
        }

        $quoted = $this->quoteIdentifier($identifier);

        if (count(self::$quoted) < self::QUOTED_MAX) {
            self::$quoted[$key] = $quoted;
        }

        return $quoted;
    }

    private function quoteIdentifier(string $identifier): string
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

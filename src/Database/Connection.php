<?php

declare(strict_types=1);

namespace Pype\Database;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper: lazy connect, prepared-statement cache, transactions, query builder entry.
 *
 *   $db->select('SELECT * FROM users WHERE active = ?', [1]);
 *   $db->table('users')->where('id', 5)->first();
 *   $db->transaction(fn (Connection $db) => ...);
 */
final class Connection
{
    private ?PDO $pdo = null;

    /** @var array<string, PDOStatement> */
    private array $statements = [];

    /**
     * @param array<string, mixed> $config  one entry from config/database.php 'connections'
     */
    public function __construct(private readonly array $config, private readonly string $basePath = '')
    {
    }

    public static function sqlite(string $path = ':memory:'): self
    {
        return new self(['driver' => 'sqlite', 'database' => $path]);
    }

    public function driver(): string
    {
        return $this->config['driver'];
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /** @return list<array<string, mixed>> */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function first(string $sql, array $bindings = []): ?array
    {
        $statement = $this->run($sql, $bindings);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $row === false ? null : $row;
    }

    /** First column of the first row. */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $statement = $this->run($sql, $bindings);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value === false ? null : $value;
    }

    /** INSERT/UPDATE/DELETE; returns affected rows. */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /** Raw, unprepared SQL — may contain several statements (migrations, pragmas). */
    public function statement(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    public function lastInsertId(): string
    {
        return (string) $this->pdo()->lastInsertId();
    }

    /**
     * Runs $callback in a transaction; commits on return, rolls back on exception.
     * Nested calls join the outer transaction.
     *
     * @template T
     * @param Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($this);

            // MySQL DDL commits implicitly, leaving nothing to commit.
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /** Wrap an identifier in the driver's quote character. */
    public function quote(string $identifier): string
    {
        $q = $this->driver() === 'mysql' ? '`' : '"';

        return implode('.', array_map(
            static fn (string $part) => $part === '*' ? '*' : $q . str_replace($q, $q . $q, $part) . $q,
            explode('.', $identifier),
        ));
    }

    private function run(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->statements[$sql] ??= $this->pdo()->prepare($sql);

        if (!array_is_list($bindings) && is_int(array_key_first($bindings))) {
            $bindings = array_values($bindings);
        }

        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_int($key) ? $key + 1 : $key,
                $value,
                match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                },
            );
        }

        $statement->execute();

        return $statement;
    }

    private function connect(): PDO
    {
        $c = $this->config;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            ...($c['options'] ?? []),
        ];

        return match ($c['driver'] ?? null) {
            'sqlite' => $this->connectSqlite($options),
            'mysql' => new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'] ?? '127.0.0.1', $c['port'] ?? 3306, $c['database'], $c['charset'] ?? 'utf8mb4'),
                $c['username'] ?? null,
                $c['password'] ?? null,
                $options,
            ),
            'pgsql' => new PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'] ?? '127.0.0.1', $c['port'] ?? 5432, $c['database']),
                $c['username'] ?? null,
                $c['password'] ?? null,
                $options,
            ),
            default => throw new InvalidArgumentException('Unsupported database driver: ' . var_export($c['driver'] ?? null, true)),
        };
    }

    private function connectSqlite(array $options): PDO
    {
        $path = $this->config['database'] ?? ':memory:';

        if ($path !== ':memory:' && !str_starts_with($path, '/')) {
            $path = rtrim($this->basePath, '/') . '/' . $path;
        }

        if ($path !== ':memory:' && !is_file($path)) {
            @mkdir(dirname($path), 0777, true);
            touch($path);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, $options);

        // Tuned for a read-heavy web workload. All overridable in config/database.php.
        $pragmas = [
            'journal_mode' => 'WAL',      // readers don't block the writer
            'synchronous' => 'NORMAL',    // safe with WAL, far fewer fsyncs
            'foreign_keys' => 'ON',
            'busy_timeout' => 5000,       // ms to wait on a locked db instead of failing
            'cache_size' => -20000,       // ~20MB page cache
            'temp_store' => 'MEMORY',
            ...($this->config['pragmas'] ?? []),
        ];

        foreach ($pragmas as $name => $value) {
            if ($path === ':memory:' && $name === 'journal_mode') {
                continue;
            }
            $pdo->exec("PRAGMA {$name} = {$value}");
        }

        return $pdo;
    }
}

<?php

declare(strict_types=1);

namespace Pype\Cache;

use Closure;
use Pype\Database\Connection;

/**
 * Values in a table: cache(key VARCHAR(255) PRIMARY KEY, value LONGTEXT, expires_at BIGINT NULL).
 * Shared by every app server using the database. Each write is one atomic statement on SQLite,
 * MySQL and Postgres. Integers are stored as plain digits (so increment() can do the maths in
 * SQL); everything else as base64(serialize()), which keeps NUL bytes out of the text column.
 * Expired rows read as missing; pruneExpired() deletes them (e.g. from a scheduled command).
 */
final class DatabaseStore implements Store
{
    /** @param (Closure(): int)|null $now  the clock, for tests */
    public function __construct(
        private readonly Connection $db,
        private readonly string $table = 'cache',
        private readonly ?Closure $now = null,
    ) {
    }

    public function fetch(string $key): array
    {
        $row = $this->db->first(
            "SELECT {$this->col('value')} AS v, expires_at FROM {$this->db->quote($this->table)} WHERE {$this->col('key')} = ?",
            [self::column($key)],
        );

        if ($row === null || ($row['expires_at'] !== null && (int) $row['expires_at'] <= $this->now())) {
            return [false, null];
        }

        return [true, self::decode((string) $row['v'])];
    }

    public function put(string $key, mixed $value, ?int $seconds): bool
    {
        [$table, $k, $v] = [$this->db->quote($this->table), $this->col('key'), $this->col('value')];

        $this->db->execute(match ($this->db->driver()) {
            'mysql' => "INSERT INTO {$table} ({$k}, {$v}, expires_at) VALUES (?, ?, ?)"
                . " ON DUPLICATE KEY UPDATE {$v} = VALUES({$v}), expires_at = VALUES(expires_at)",
            default => "INSERT INTO {$table} ({$k}, {$v}, expires_at) VALUES (?, ?, ?)"
                . " ON CONFLICT ({$k}) DO UPDATE SET {$v} = excluded.{$v}, expires_at = excluded.expires_at",
        }, [self::column($key), self::encode($value), $this->expiresAt($seconds)]);

        return true;
    }

    public function add(string $key, mixed $value, ?int $seconds): bool
    {
        [$table, $k, $v] = [$this->db->quote($this->table), $this->col('key'), $this->col('value')];
        $now = $this->now();
        $bindings = [self::column($key), self::encode($value), $this->expiresAt($seconds)];

        // Insert, or take over a row that has expired; a live row is left alone (0 rows).
        // MySQL: value is assigned before expires_at, so both IF()s see the old expiry.
        return $this->db->execute(match ($this->db->driver()) {
            'mysql' => "INSERT INTO {$table} ({$k}, {$v}, expires_at) VALUES (?, ?, ?)"
                . " ON DUPLICATE KEY UPDATE {$v} = IF(expires_at <= ?, VALUES({$v}), {$v}),"
                . ' expires_at = IF(expires_at <= ?, VALUES(expires_at), expires_at)',
            default => "INSERT INTO {$table} AS t ({$k}, {$v}, expires_at) VALUES (?, ?, ?)"
                . " ON CONFLICT ({$k}) DO UPDATE SET {$v} = excluded.{$v}, expires_at = excluded.expires_at"
                . ' WHERE t.expires_at <= ?',
        }, $this->db->driver() === 'mysql' ? [...$bindings, $now, $now] : [...$bindings, $now]) > 0;
    }

    public function increment(string $key, int $by): int
    {
        [$table, $k, $v] = [$this->db->quote($this->table), $this->col('key'), $this->col('value')];
        $now = $this->now();
        $column = self::column($key);

        if ($this->db->driver() === 'mysql') {
            // No RETURNING in MySQL: upsert, then read the row back while it's still locked.
            return $this->db->transaction(function (Connection $db) use ($table, $k, $v, $column, $by, $now) {
                $db->execute(
                    "INSERT INTO {$table} ({$k}, {$v}, expires_at) VALUES (?, ?, NULL)"
                    . " ON DUPLICATE KEY UPDATE {$v} = IF(expires_at <= ?, VALUES({$v}), CAST(CAST({$v} AS SIGNED) + ? AS CHAR)),"
                    . ' expires_at = IF(expires_at <= ?, NULL, expires_at)',
                    [$column, (string) $by, $now, $by, $now],
                );

                return (int) $db->scalar("SELECT {$v} FROM {$table} WHERE {$k} = ?", [$column]);
            });
        }

        $int = $this->db->driver() === 'pgsql' ? 'BIGINT' : 'INTEGER';

        return (int) $this->db->scalar(
            "INSERT INTO {$table} AS t ({$k}, {$v}, expires_at) VALUES (?, ?, NULL)"
            . " ON CONFLICT ({$k}) DO UPDATE SET"
            . " {$v} = CASE WHEN t.expires_at <= ? THEN excluded.{$v} ELSE CAST(CAST(t.{$v} AS {$int}) + ? AS TEXT) END,"
            . ' expires_at = CASE WHEN t.expires_at <= ? THEN NULL ELSE t.expires_at END'
            . " RETURNING {$v}",
            [$column, (string) $by, $now, $by, $now],
        );
    }

    public function forget(string $key): bool
    {
        $this->db->table($this->table)->where('key', self::column($key))->delete();

        return true;
    }

    public function flush(string $prefix): bool
    {
        $table = $this->db->quote($this->table);

        if ($prefix === '') {
            $this->db->execute("DELETE FROM {$table}");
        } else {
            $this->db->execute("DELETE FROM {$table} WHERE SUBSTR({$this->col('key')}, 1, ?) = ?", [strlen($prefix), $prefix]);
        }

        return true;
    }

    /** Delete expired rows; returns how many. */
    public function pruneExpired(): int
    {
        return $this->db->table($this->table)->where('expires_at', '<=', $this->now())->delete();
    }

    private static function encode(mixed $value): string
    {
        // base64 output never starts with a digit or '-' (serialize() starts with a letter),
        // so plain integers and encoded values can't be confused.
        return is_int($value) ? (string) $value : base64_encode(serialize($value));
    }

    private static function decode(string $stored): mixed
    {
        return preg_match('/^-?\d+$/', $stored) === 1 ? (int) $stored : unserialize(base64_decode($stored));
    }

    /** Keys longer than the column keep their start (so prefix flushes still work) plus a hash. */
    private static function column(string $key): string
    {
        return strlen($key) <= 255 ? $key : substr($key, 0, 190) . '#' . hash('sha256', $key);
    }

    private function expiresAt(?int $seconds): ?int
    {
        return $seconds === null ? null : $this->now() + $seconds;
    }

    private function col(string $name): string
    {
        return $this->db->quote($name);
    }

    private function now(): int
    {
        return $this->now === null ? time() : ($this->now)();
    }
}

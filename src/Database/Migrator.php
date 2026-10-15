<?php

declare(strict_types=1);

namespace Pype\Database;

use RuntimeException;

/**
 * Forward-only migrations from plain .sql files, applied in filename order:
 *
 *   database/migrations/2026_10_15_120000_create_users_table.sql
 *
 * Each file runs inside a transaction and is recorded in the pype_migrations table.
 */
final class Migrator
{
    private const TABLE = 'pype_migrations';

    public function __construct(private readonly Connection $db, private readonly string $directory)
    {
    }

    /** @return list<string> names of migrations that were applied */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = [];

        foreach ($this->pending() as $name => $file) {
            $sql = (string) file_get_contents($file);

            try {
                $this->db->transaction(function (Connection $db) use ($name, $sql) {
                    $db->statement($sql);
                    $db->table(self::TABLE)->insert(['name' => $name, 'ran_at' => date('Y-m-d H:i:s')]);
                });
            } catch (\Throwable $e) {
                throw new RuntimeException("Migration {$name} failed: {$e->getMessage()}", 0, $e);
            }

            $applied[] = $name;
        }

        return $applied;
    }

    /** @return array<string, bool> every migration file => whether it has run */
    public function status(): array
    {
        $this->ensureTable();
        $ran = array_flip($this->ran());
        $status = [];

        foreach ($this->files() as $name => $_) {
            $status[$name] = isset($ran[$name]);
        }

        return $status;
    }

    /** @return array<string, string> name => path */
    public function pending(): array
    {
        return array_diff_key($this->files(), array_flip($this->ran()));
    }

    /** @return array<string, string> name => path, sorted */
    private function files(): array
    {
        $files = [];

        foreach (glob(rtrim($this->directory, '/') . '/*.sql') ?: [] as $path) {
            $files[basename($path, '.sql')] = $path;
        }

        ksort($files);

        return $files;
    }

    /** @return list<string> */
    private function ran(): array
    {
        return array_column($this->db->select('SELECT name FROM ' . self::TABLE . ' ORDER BY name'), 'name');
    }

    private function ensureTable(): void
    {
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (name VARCHAR(255) PRIMARY KEY, ran_at VARCHAR(32) NOT NULL)'
        );
    }
}

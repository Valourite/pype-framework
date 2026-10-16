<?php

declare(strict_types=1);

namespace Pype\Database;

use Pype\Database\Schema\Migration;
use Pype\Database\Schema\Schema;
use RuntimeException;

/**
 * Forward-only migrations, applied in filename order. Either PHP (schema builder, runs on
 * every database) or plain SQL (exactly what you write):
 *
 *   database/migrations/2026_10_15_120000_create_users_table.php
 *   database/migrations/2026_10_15_130000_add_search_view.sql
 *
 * Each file runs inside a transaction and is recorded in the pype_migrations table by name
 * (without extension), so converting a .sql migration to .php doesn't run it again.
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
            try {
                $this->db->transaction(function (Connection $db) use ($name, $file) {
                    $this->run($file);
                    $db->table(self::TABLE)->insert(['name' => $name, 'ran_at' => date('Y-m-d H:i:s')]);
                });
            } catch (\Throwable $e) {
                throw new RuntimeException("Migration {$name} failed: {$e->getMessage()}", 0, $e);
            }

            $applied[] = $name;
        }

        return $applied;
    }

    private function run(string $file): void
    {
        if (str_ends_with($file, '.sql')) {
            $this->db->statement((string) file_get_contents($file));

            return;
        }

        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new RuntimeException('A .php migration must return a ' . Migration::class . ' (return new class extends Migration { ... };).');
        }

        $migration->up(new Schema($this->db));
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

        $dir = rtrim($this->directory, '/');

        foreach ([...(glob("{$dir}/*.sql") ?: []), ...(glob("{$dir}/*.php") ?: [])] as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);

            if (isset($files[$name])) {
                throw new RuntimeException("Migration {$name} exists as both .sql and .php; keep one.");
            }

            $files[$name] = $path;
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

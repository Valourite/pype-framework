<?php

declare(strict_types=1);

namespace Pype\Database;

use Pype\Database\Schema\Migration;
use Pype\Database\Schema\Schema;
use RuntimeException;

/**
 * Migrations, applied in filename order. Either PHP (schema builder, runs on every database)
 * or plain SQL (exactly what you write):
 *
 *   database/migrations/2026_10_15_120000_create_users_table.php
 *   database/migrations/2026_10_15_130000_add_search_view.sql
 *
 * Each file runs inside a transaction and is recorded in the pype_migrations table by name
 * (without extension), so converting a .sql migration to .php doesn't run it again. Each
 * migrate() call is one batch; rollback() undoes the last batch (or the last N migrations)
 * through down() in PHP migrations, or the part after a `-- down` line in .sql ones. A
 * migration without one is irreversible: rollback stops there.
 *
 * MySQL commits DDL statements immediately, so a migration that fails halfway can leave part
 * of its changes behind there; SQLite and Postgres roll the whole file back.
 */
final class Migrator
{
    private const TABLE = 'pype_migrations';

    /** The line that separates a .sql migration's up part from its down part. */
    private const SQL_DOWN = '/^--\s*down\s*$/mi';

    public function __construct(private readonly Connection $db, private readonly string $directory)
    {
    }

    /** @return list<string> names of migrations that were applied */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = [];
        $batch = (int) $this->db->scalar('SELECT MAX(batch) FROM ' . self::TABLE) + 1;

        foreach ($this->pending() as $name => $file) {
            try {
                $this->db->transaction(function (Connection $db) use ($name, $file, $batch) {
                    $this->run($file, 'up');
                    $db->table(self::TABLE)->insert(['name' => $name, 'ran_at' => date('Y-m-d H:i:s'), 'batch' => $batch]);
                });
            } catch (\Throwable $e) {
                throw new RuntimeException("Migration {$name} failed: {$e->getMessage()}", 0, $e);
            }

            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * Undo the last batch, or the last $steps migrations, newest first. Stops at a migration
     * that can't be undone (no down()); the ones before it stay rolled back.
     *
     * @return list<string> names of migrations that were rolled back
     */
    public function rollback(?int $steps = null): array
    {
        $this->ensureTable();
        $files = $this->files();
        $rolledBack = [];

        foreach ($this->toRollBack($steps) as $name) {
            $file = $files[$name] ?? throw new RuntimeException("Can't roll back {$name}: its file is gone." . self::done($rolledBack));

            if (!$this->reversible($file)) {
                throw new RuntimeException("Can't roll back {$name}: " . (str_ends_with($file, '.sql') ? 'it has no "-- down" section.' : 'it has no down() method.') . self::done($rolledBack));
            }

            try {
                $this->db->transaction(function (Connection $db) use ($name, $file) {
                    $this->run($file, 'down');
                    $db->table(self::TABLE)->where('name', $name)->delete();
                });
            } catch (\Throwable $e) {
                throw new RuntimeException("Rolling back {$name} failed: {$e->getMessage()}" . self::done($rolledBack), 0, $e);
            }

            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /**
     * Drop every table (and view) in the database, then run all migrations. For development:
     * everything in the database is lost.
     *
     * @return list<string> names of migrations that were applied
     */
    public function fresh(): array
    {
        (new Schema($this->db))->dropAllTables();

        return $this->migrate();
    }

    /** @return list<string> newest first */
    private function toRollBack(?int $steps): array
    {
        $table = self::TABLE;

        if ($steps === null) {
            return array_column($this->db->select(
                "SELECT name FROM {$table} WHERE batch = (SELECT MAX(batch) FROM {$table}) ORDER BY name DESC",
            ), 'name');
        }

        $rows = $this->db->select("SELECT name FROM {$table} ORDER BY batch DESC, name DESC");

        return array_slice(array_column($rows, 'name'), 0, max(0, $steps));
    }

    private function reversible(string $file): bool
    {
        if (str_ends_with($file, '.sql')) {
            return preg_match(self::SQL_DOWN, (string) file_get_contents($file)) === 1;
        }

        $migration = $this->load($file);

        return (new \ReflectionMethod($migration, 'down'))->getDeclaringClass()->getName() !== Migration::class;
    }

    /** @param 'up'|'down' $direction */
    private function run(string $file, string $direction): void
    {
        if (str_ends_with($file, '.sql')) {
            $parts = preg_split(self::SQL_DOWN, (string) file_get_contents($file), 2);
            $sql = trim($direction === 'up' ? $parts[0] : ($parts[1] ?? ''));

            if ($sql !== '') {
                $this->db->statement($sql);
            }

            return;
        }

        $this->load($file)->{$direction}(new Schema($this->db));
    }

    private function load(string $file): Migration
    {
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new RuntimeException('A .php migration must return a ' . Migration::class . ' (return new class extends Migration { ... };).');
        }

        return $migration;
    }

    /** @param list<string> $names */
    private static function done(array $names): string
    {
        return $names === [] ? ' Nothing was rolled back.' : ' Rolled back before that: ' . implode(', ', $names) . '.';
    }

    /** @return array<string, ?int> every migration file => the batch it ran in, or null if pending */
    public function status(): array
    {
        $this->ensureTable();
        $batches = array_column($this->db->select('SELECT name, batch FROM ' . self::TABLE), 'batch', 'name');
        $status = [];

        foreach ($this->files() as $name => $_) {
            $status[$name] = isset($batches[$name]) ? (int) $batches[$name] : null;
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
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (name VARCHAR(255) PRIMARY KEY, ran_at VARCHAR(32) NOT NULL, batch INTEGER NOT NULL DEFAULT 0)'
        );

        $this->addBatchColumn();
    }

    /**
     * Tables made before 0.7 have no batch column. Add it and give each migration that already
     * ran its own batch, in the order they ran, so a first rollback undoes only the newest one.
     */
    private function addBatchColumn(): void
    {
        $columns = match ($this->db->driver()) {
            'sqlite' => array_column($this->db->select('PRAGMA table_info(' . self::TABLE . ')'), 'name'),
            'mysql' => array_column($this->db->select('SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [self::TABLE]), 'name'),
            default => array_column($this->db->select('SELECT column_name AS name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?', [self::TABLE]), 'name'),
        };

        if (in_array('batch', $columns, true)) {
            return;
        }

        $this->db->transaction(function (Connection $db) {
            $db->statement('ALTER TABLE ' . self::TABLE . ' ADD COLUMN batch INTEGER NOT NULL DEFAULT 0');

            foreach ($db->select('SELECT name FROM ' . self::TABLE . ' ORDER BY ran_at, name') as $i => $row) {
                $db->table(self::TABLE)->where('name', $row['name'])->update(['batch' => $i + 1]);
            }
        });
    }
}

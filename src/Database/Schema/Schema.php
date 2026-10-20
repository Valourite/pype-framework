<?php

declare(strict_types=1);

namespace Pype\Database\Schema;

use Closure;
use Pype\Database\Connection;

/**
 * Builds tables with the right SQL for the connection's driver (sqlite, mysql, pgsql).
 *
 *   $schema->create('posts', function (Table $t) {
 *       $t->id();
 *       $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
 *       $t->string('title');
 *       $t->text('body')->nullable();
 *       $t->timestamps();
 *       $t->index(['user_id', 'created_at']);
 *   });
 *   $schema->table('posts', fn (Table $t) => $t->boolean('published')->default(false));
 *   $schema->raw('CREATE VIEW ...');   // anything the builder doesn't cover
 */
final class Schema
{
    private readonly Grammar $grammar;

    public function __construct(private readonly Connection $db)
    {
        $this->grammar = new Grammar($db);
    }

    /** @param Closure(Table): mixed $build */
    public function create(string $table, Closure $build): void
    {
        $build($blueprint = new Table($table));
        $this->run($this->grammar->create($blueprint));
    }

    /** Change an existing table: add columns and indexes, drop or rename columns. */
    public function table(string $table, Closure $build): void
    {
        $build($blueprint = new Table($table));
        $this->run($this->grammar->alter($blueprint));
    }

    public function drop(string $table): void
    {
        $this->db->statement('DROP TABLE ' . $this->db->quote($table));
    }

    public function dropIfExists(string $table): void
    {
        $this->db->statement('DROP TABLE IF EXISTS ' . $this->db->quote($table));
    }

    /** Drop every table and view in the database (the current schema on Postgres). Everything in them is lost. */
    public function dropAllTables(): void
    {
        $db = $this->db;
        $driver = $db->driver();

        if ($driver === 'sqlite') {
            $db->statement('PRAGMA foreign_keys = OFF');
            foreach ($db->select("SELECT type, name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'") as $row) {
                $db->statement('DROP ' . strtoupper($row['type']) . ' IF EXISTS ' . $db->quote($row['name']));
            }
            $db->statement('PRAGMA foreign_keys = ON');

            return;
        }

        $schema = $driver === 'mysql' ? 'DATABASE()' : 'current_schema()';
        $rows = $db->select("SELECT table_name AS name, table_type AS type FROM information_schema.tables WHERE table_schema = {$schema}");

        if ($driver === 'mysql') {
            $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        }

        foreach ($rows as $row) {
            $db->statement(($row['type'] === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $db->quote($row['name']) . ($driver === 'pgsql' ? ' CASCADE' : ''));
        }

        if ($driver === 'mysql') {
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function raw(string $sql): void
    {
        $this->db->statement($sql);
    }

    /** The SQL create() would run, without running it. */
    public function toSql(string $table, Closure $build): string
    {
        $build($blueprint = new Table($table));

        return implode(";\n", $this->grammar->create($blueprint));
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    /** @param list<string> $statements */
    private function run(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->db->statement($sql);
        }
    }
}

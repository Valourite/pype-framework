<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Console\Console;
use Pype\Database\Connection;
use Pype\Database\Migrator;
use Pype\Tests\Support\TestDatabase;

/** Rollback, batches, .sql down sections and migrate:fresh, on SQLite, MySQL and Postgres. */
final class MigrationRollbackTest extends TestCase
{
    private string $dir;

    private Connection $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pype-rollback-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->db = TestDatabase::fresh();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        rmdir($this->dir);
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->db, $this->dir);
    }

    /** A create-table migration with a down() that drops it (or none). */
    private function createTable(string $name, string $table, bool $down = true): void
    {
        $downMethod = $down ? "public function down(Schema \$s): void { \$s->dropIfExists('{$table}'); }" : '';
        file_put_contents("{$this->dir}/{$name}.php", <<<PHP
            <?php
            use Pype\\Database\\Schema\\{Migration, Schema, Table};
            return new class extends Migration {
                public function up(Schema \$s): void { \$s->create('{$table}', fn (Table \$t) => \$t->id()); }
                {$downMethod}
            };
            PHP);
    }

    private function tables(): array
    {
        $names = match ($this->db->driver()) {
            'sqlite' => array_column($this->db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name'),
            'mysql' => array_column($this->db->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()'), 'name'),
            default => array_column($this->db->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema()'), 'name'),
        };
        $names = array_values(array_diff($names, ['pype_migrations']));
        sort($names);

        return $names;
    }

    public function test_rollback_undoes_the_last_batch(): void
    {
        $this->createTable('2026_01_01_000000_create_a', 'a');
        $this->createTable('2026_01_02_000000_create_b', 'b');
        $this->assertSame(['2026_01_01_000000_create_a', '2026_01_02_000000_create_b'], $this->migrator()->migrate());

        $this->createTable('2026_01_03_000000_create_c', 'c');
        $this->createTable('2026_01_04_000000_create_d', 'd');
        $this->migrator()->migrate();
        $this->assertSame(['a', 'b', 'c', 'd'], $this->tables());
        $this->assertSame(['2026_01_01_000000_create_a' => 1, '2026_01_02_000000_create_b' => 1, '2026_01_03_000000_create_c' => 2, '2026_01_04_000000_create_d' => 2], $this->migrator()->status());

        $this->assertSame(['2026_01_04_000000_create_d', '2026_01_03_000000_create_c'], $this->migrator()->rollback());
        $this->assertSame(['a', 'b'], $this->tables());
        $this->assertNull($this->migrator()->status()['2026_01_03_000000_create_c']);

        $this->assertSame(['2026_01_02_000000_create_b', '2026_01_01_000000_create_a'], $this->migrator()->rollback());
        $this->assertSame([], $this->tables());
        $this->assertSame([], $this->migrator()->rollback());

        // And forward again, as one batch.
        $this->assertCount(4, $this->migrator()->migrate());
        $this->assertSame([1, 1, 1, 1], array_values($this->migrator()->status()));
    }

    public function test_step_undoes_the_last_n_migrations_across_batches(): void
    {
        $this->createTable('2026_01_01_000000_create_a', 'a');
        $this->createTable('2026_01_02_000000_create_b', 'b');
        $this->migrator()->migrate();
        $this->createTable('2026_01_03_000000_create_c', 'c');
        $this->migrator()->migrate();

        $this->assertSame(['2026_01_03_000000_create_c', '2026_01_02_000000_create_b'], $this->migrator()->rollback(2));
        $this->assertSame(['a'], $this->tables());
    }

    public function test_sql_migrations_run_their_down_section(): void
    {
        $this->createTable('2026_01_01_000000_create_a', 'a');
        file_put_contents("{$this->dir}/2026_01_02_000000_view.sql", "CREATE VIEW a_view AS SELECT id FROM a;\n\n-- down\nDROP VIEW a_view;\n");
        $this->migrator()->migrate();

        $this->assertSame([], $this->db->select('SELECT * FROM a_view'));   // the down part didn't run on the way up

        $this->assertSame(['2026_01_02_000000_view', '2026_01_01_000000_create_a'], $this->migrator()->rollback());
        $this->assertSame([], $this->tables());
    }

    public function test_rollback_stops_at_a_migration_without_down(): void
    {
        $this->createTable('2026_01_01_000000_create_a', 'a');
        $this->createTable('2026_01_02_000000_create_b', 'b', down: false);
        file_put_contents("{$this->dir}/2026_01_03_000000_seed.sql", "INSERT INTO a (id) VALUES (1);");
        $this->createTable('2026_01_04_000000_create_c', 'c');
        $this->migrator()->migrate();

        try {
            $this->migrator()->rollback();
            $this->fail('rolled back past an irreversible migration');
        } catch (\RuntimeException $e) {
            $this->assertSame('Can\'t roll back 2026_01_03_000000_seed: it has no "-- down" section. Rolled back before that: 2026_01_04_000000_create_c.', $e->getMessage());
        }

        $this->assertSame(['a', 'b'], $this->tables());
        $this->assertNull($this->migrator()->status()['2026_01_04_000000_create_c']);
        $this->assertSame(1, $this->migrator()->status()['2026_01_03_000000_seed']);

        unlink("{$this->dir}/2026_01_03_000000_seed.sql");
        file_put_contents("{$this->dir}/2026_01_03_000000_seed.sql", "INSERT INTO a (id) VALUES (1);\n-- down\nDELETE FROM a;");
        $this->expectExceptionMessage("Can't roll back 2026_01_02_000000_create_b: it has no down() method. Rolled back before that: 2026_01_03_000000_seed.");
        $this->migrator()->rollback();
    }

    public function test_alter_migrations_roll_back(): void
    {
        $this->createTable('2026_01_01_000000_create_a', 'a');
        file_put_contents("{$this->dir}/2026_01_02_000000_add_name_to_a.php", <<<'PHP'
            <?php
            use Pype\Database\Schema\{Migration, Schema, Table};
            return new class extends Migration {
                public function up(Schema $s): void { $s->table('a', fn (Table $t) => $t->string('name')->nullable()); }
                public function down(Schema $s): void { $s->table('a', fn (Table $t) => $t->dropColumn('name')); }
            };
            PHP);
        $this->migrator()->migrate();
        $this->db->table('a')->insert(['name' => 'x']);

        $this->migrator()->rollback(1);
        $this->assertSame([['id' => 1]], array_map(fn ($r) => ['id' => (int) $r['id']], $this->db->select('SELECT * FROM a')));
    }

    public function test_tables_from_before_batches_get_one_batch_per_migration(): void
    {
        $this->createTable('2026_01_01_000000_create_a', 'a');
        $this->createTable('2026_01_02_000000_create_b', 'b');
        $this->migrator()->migrate();

        // Turn pype_migrations back into the 0.6 shape: no batch column.
        $this->db->statement('DROP TABLE pype_migrations');
        $this->db->statement('CREATE TABLE pype_migrations (name VARCHAR(255) PRIMARY KEY, ran_at VARCHAR(32) NOT NULL)');
        $this->db->table('pype_migrations')->insert(['name' => '2026_01_01_000000_create_a', 'ran_at' => '2026-01-01 10:00:00']);
        $this->db->table('pype_migrations')->insert(['name' => '2026_01_02_000000_create_b', 'ran_at' => '2026-01-01 10:00:00']);

        $this->assertSame(['2026_01_01_000000_create_a' => 1, '2026_01_02_000000_create_b' => 2], $this->migrator()->status());
        $this->assertSame(['2026_01_02_000000_create_b'], $this->migrator()->rollback());
        $this->assertSame(['a'], $this->tables());
    }

    public function test_fresh_drops_everything_and_migrates(): void
    {
        $this->createTable('2026_01_01_000000_create_a', 'a', down: false);   // fresh doesn't need down()
        $this->migrator()->migrate();
        $this->db->statement('CREATE TABLE stray (id INTEGER PRIMARY KEY, a_id INTEGER REFERENCES a (id))');
        $this->db->statement('CREATE VIEW stray_view AS SELECT id FROM stray');
        $this->db->table('a')->insert(['id' => 5]);

        $this->assertSame(['2026_01_01_000000_create_a'], $this->migrator()->fresh());
        $this->assertSame(['a'], $this->tables());
        $this->assertSame(0, $this->db->table('a')->count());
    }

    public function test_console_commands(): void
    {
        $app = new Application($this->dir, ['database' => ['migrations' => '.']]);
        $app->container->instance(Connection::class, $this->db);
        $app->container->instance(Migrator::class, $this->migrator());
        $run = function (string ...$argv) use ($app): array {
            ob_start();
            $code = (new Console($app))->run(['pype', ...$argv]);

            return [$code, (string) ob_get_clean()];
        };

        // make:migration writes a down() that undoes the stub's up().
        [, $out] = $run('make:migration', 'create_posts_table');
        $this->assertStringStartsWith('Created ', $out);
        $file = glob("{$this->dir}/*_create_posts_table.php")[0];
        $this->assertStringContainsString("\$schema->dropIfExists('posts');", (string) file_get_contents($file));

        $this->assertSame([0, "Migrated: " . basename($file, '.php') . "\n"], $run('migrate'));
        $this->assertStringContainsString('(batch 1)', $run('migrate:status')[1]);
        $this->assertSame([0, 'Rolled back: ' . basename($file, '.php') . "\n"], $run('migrate:rollback'));
        $this->assertSame([0, "Nothing to roll back.\n"], $run('migrate:rollback', '--step=3'));

        // migrate:fresh refuses when APP_DEBUG is off, unless forced.
        $this->assertSame(1, $run('migrate:fresh')[0]);
        [$code, $out] = $run('migrate:fresh', '--force');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Migrated: ', $out);

        // A .sql stub has a down section.
        $run('make:migration', 'add_view', '--sql');
        $this->assertStringContainsString("-- down", (string) file_get_contents(glob("{$this->dir}/*_add_view.sql")[0]));
    }
}

<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pype\Database\Connection;
use Pype\Database\Migrator;
use Pype\Tests\Support\TestDatabase;

final class DatabaseTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::fresh();
        (new Migrator($this->db, __DIR__ . '/../fixtures/migrations'))->migrate();
    }

    public function test_migrator_runs_once(): void
    {
        $migrator = new Migrator($this->db, __DIR__ . '/../fixtures/migrations');

        $this->assertSame([], $migrator->migrate());
        $this->assertSame(['2026_01_01_000000_create_items' => 1], $migrator->status());   // ran in batch 1
    }

    public function test_query_builder_reads(): void
    {
        $items = $this->db->table('items');

        $this->assertSame(3, $items->count());
        $this->assertSame(['pear', 'fig'], array_column(
            $this->db->table('items')->where('price', '>', 3)->orderBy('price')->get(),
            'name',
        ));
        $this->assertSame('apple', $this->db->table('items')->where('id', 1)->value('name'));
        $this->assertTrue($this->db->table('items')->whereIn('name', ['fig', 'kiwi'])->exists());
        $this->assertSame(['fig'], array_column($this->db->table('items')->orderBy('id')->offset(2)->get(), 'name'));
    }

    public function test_insert_update_delete_and_hydration(): void
    {
        $id = $this->db->table('items')->insert(['name' => 'kiwi', 'price' => 2]);

        $this->assertSame(1, $this->db->table('items')->where('id', $id)->update(['price' => 4]));

        $item = $this->db->table('items')->where('id', $id)->as(Item::class)->first();
        $this->assertInstanceOf(Item::class, $item);
        $this->assertSame(4, $item->price);

        $this->assertSame(1, $this->db->table('items')->where('name', 'kiwi')->delete());
        $this->assertNull($this->db->table('items')->where('name', 'kiwi')->first());
    }

    public function test_transaction_rolls_back(): void
    {
        try {
            $this->db->transaction(function (Connection $db) {
                $db->table('items')->insert(['name' => 'ghost', 'price' => 1]);
                throw new \RuntimeException('nope');
            });
        } catch (\RuntimeException) {
        }

        $this->assertFalse($this->db->table('items')->where('name', 'ghost')->exists());
    }

    public function test_sqlite_transactions_take_the_write_lock_up_front(): void
    {
        if (TestDatabase::driver() !== 'sqlite') {
            $this->markTestSkipped('SQLite only.');
        }

        $file = tempnam(sys_get_temp_dir(), 'pype');
        $a = new Connection(['driver' => 'sqlite', 'database' => $file, 'pragmas' => ['busy_timeout' => 50]]);
        $b = new Connection(['driver' => 'sqlite', 'database' => $file, 'pragmas' => ['busy_timeout' => 50]]);
        $a->statement('CREATE TABLE hits (n INTEGER)');

        // While A's transaction is open (even before it writes), B can't write: writers queue
        // instead of A failing later with "database is locked" when it upgrades to a write.
        $blocked = $a->transaction(function (Connection $a) use ($b) {
            $a->scalar('SELECT COUNT(*) FROM hits');
            try {
                $b->execute('INSERT INTO hits VALUES (1)');

                return false;
            } catch (\PDOException) {
                return true;
            }
        });
        $this->assertTrue($blocked);

        // Commit, rollback and nesting work.
        $a->transaction(fn (Connection $a) => $a->transaction(fn () => $a->execute('INSERT INTO hits VALUES (2)')));
        try {
            $a->transaction(function (Connection $a) {
                $a->execute('INSERT INTO hits VALUES (3)');
                throw new \RuntimeException('nope');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame([2], array_column($b->select('SELECT n FROM hits'), 'n'));
        $b->execute('INSERT INTO hits VALUES (4)');   // the lock was released

        unlink($file);
    }

    public function test_identifiers_are_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->table('items')->where('name; DROP TABLE items', 'x')->get();
    }
}

final class Item
{
    public function __construct(public readonly int $id, public readonly string $name, public readonly int $price)
    {
    }
}

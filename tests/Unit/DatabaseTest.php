<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pype\Database\Connection;
use Pype\Database\Migrator;

final class DatabaseTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = Connection::sqlite();
        (new Migrator($this->db, __DIR__ . '/../fixtures/migrations'))->migrate();
    }

    public function test_migrator_runs_once(): void
    {
        $migrator = new Migrator($this->db, __DIR__ . '/../fixtures/migrations');

        $this->assertSame([], $migrator->migrate());
        $this->assertSame(['2026_01_01_000000_create_items' => true], $migrator->status());
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

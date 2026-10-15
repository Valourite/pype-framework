<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Pype\Database\Connection;

final class QueryBuilderTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = Connection::sqlite();
        $this->db->statement(<<<'SQL'
            CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
            CREATE TABLE expenses (id INTEGER PRIMARY KEY, category_id INTEGER NULL, amount INTEGER NOT NULL, note TEXT);
            INSERT INTO categories (id, name) VALUES (1, 'Food'), (2, 'Rent'), (3, 'Unused');
            INSERT INTO expenses (category_id, amount, note) VALUES (1, 10, 'a'), (1, 30, 'b'), (2, 500, 'c'), (NULL, 7, 'd');
            SQL);
    }

    public function test_aliases_and_joins(): void
    {
        $rows = $this->db->table('expenses as e')
            ->join('categories as c', 'c.id', '=', 'e.category_id')
            ->select('e.note', 'c.name as category')
            ->orderBy('e.id')
            ->get();

        $this->assertSame([['note' => 'a', 'category' => 'Food'], ['note' => 'b', 'category' => 'Food'], ['note' => 'c', 'category' => 'Rent']], $rows);

        $left = $this->db->table('expenses as e')->leftJoin('categories as c', 'c.id', '=', 'e.category_id')->whereNull('c.id')->value('e.note');
        $this->assertSame('d', $left);
    }

    public function test_group_by_with_raw_aggregates_and_having(): void
    {
        $rows = $this->db->table('expenses as e')
            ->join('categories as c', 'c.id', '=', 'e.category_id')
            ->select('c.name')
            ->selectRaw('COUNT(*) AS n, SUM(e.amount) AS total')
            ->groupBy('c.id')
            ->having('total', '>', 20)
            ->orderBy('total', 'desc')
            ->get();

        $this->assertSame([['name' => 'Rent', 'n' => 1, 'total' => 500], ['name' => 'Food', 'n' => 2, 'total' => 40]], $rows);
    }

    public function test_bindings_stay_in_placeholder_order(): void
    {
        $q = $this->db->table('expenses')
            ->select('note')
            ->selectRaw('amount * ? AS doubled', [2])
            ->where('amount', '>', 9)
            ->whereRaw('note <> ?', ['c'])
            ->groupBy('note')
            ->havingRaw('SUM(amount) < ?', [100])
            ->orderBy('note');

        $this->assertSame([2, 9, 'c', 100], $q->bindings());
        $this->assertSame([['note' => 'a', 'doubled' => 20], ['note' => 'b', 'doubled' => 60]], $q->get());
    }

    public function test_aggregates(): void
    {
        $expenses = fn () => $this->db->table('expenses');

        $this->assertSame(547, $expenses()->sum('amount'));
        $this->assertSame(40, $expenses()->where('category_id', 1)->sum('amount'));
        $this->assertSame(0, $expenses()->where('amount', '>', 1000)->sum('amount'));
        $this->assertSame(20.0, (float) $expenses()->where('category_id', 1)->avg('amount'));
        $this->assertSame(7, $expenses()->min('amount'));
        $this->assertSame('d', $expenses()->max('note'));
        $this->assertSame(4, $expenses()->count());

        // Grouped and limited queries aggregate over the result, not the table.
        $this->assertSame(2, $expenses()->whereNull('category_id', not: true)->groupBy('category_id')->count());
        $this->assertSame(540, $expenses()->orderBy('amount')->limit(3)->offset(1)->select('amount')->sum('amount'));
        $this->assertTrue($expenses()->groupBy('category_id')->exists());
        $this->assertFalse($expenses()->where('amount', 0)->exists());
    }

    public function test_aliases_are_validated(): void
    {
        foreach (['users as', 'a as b as c', 'name as "x"', 'x as y; DROP TABLE users'] as $bad) {
            try {
                $this->db->table('expenses')->select($bad);
                $this->fail("Accepted [{$bad}]");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->db->table('expenses')->join('categories', 'id', 'OR 1=1 --', 'category_id');
    }

    public function test_update_and_delete_refuse_joins(): void
    {
        $this->expectException(LogicException::class);
        $this->db->table('expenses as e')->join('categories as c', 'c.id', '=', 'e.category_id')->delete();
    }
}

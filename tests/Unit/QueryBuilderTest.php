<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Pype\Database\Connection;
use Pype\Database\Schema\Schema;
use Pype\Database\Schema\Table;
use Pype\Tests\Support\TestDatabase;

final class QueryBuilderTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::fresh();
        $schema = new Schema($this->db);
        $schema->create('categories', function (Table $t) {
            $t->id();
            $t->string('name');
        });
        $schema->create('expenses', function (Table $t) {
            $t->id();
            $t->foreignId('category_id')->nullable();
            $t->integer('amount');
            $t->text('note')->nullable();
        });
        $this->db->execute("INSERT INTO categories (name) VALUES ('Food'), ('Rent'), ('Unused')");
        $this->db->execute("INSERT INTO expenses (category_id, amount, note) VALUES (1, 10, 'a'), (1, 30, 'b'), (2, 500, 'c'), (NULL, 7, 'd')");
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
            ->havingRaw('SUM(e.amount) > ?', [20])   // Postgres can't see select aliases in HAVING
            ->orderBy('total', 'desc')
            ->get();

        // Raw SUM() comes back as a numeric string on MySQL/Postgres (DECIMAL/NUMERIC), an int on SQLite.
        $rows = array_map(fn (array $r) => [...$r, 'total' => (int) $r['total']], $rows);
        $this->assertSame([['name' => 'Rent', 'n' => 1, 'total' => 500], ['name' => 'Food', 'n' => 2, 'total' => 40]], $rows);
    }

    public function test_bindings_stay_in_placeholder_order(): void
    {
        $q = $this->db->table('expenses')
            ->select('note')
            ->selectRaw('MAX(amount) * ? AS doubled', [2])
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

    public function test_paginate(): void
    {
        $page = $this->db->table('expenses')->orderBy('amount')->paginate(perPage: 3, page: 2);

        $this->assertSame([['id' => 3, 'category_id' => 2, 'amount' => 500, 'note' => 'c']], $page->items);
        $this->assertSame(['page' => 2, 'per_page' => 3, 'total' => 4, 'last_page' => 2], $page->meta());
        $this->assertTrue($page->hasPrevious());
        $this->assertFalse($page->hasNext());

        $this->assertSame(1, $this->db->table('expenses')->paginate(3, -5)->page);
        $this->assertSame(2, $this->db->table('expenses')->paginate(3, 9)->page);   // past the end: the last page
        $this->assertCount(1, $this->db->table('expenses')->paginate(3, 9)->items);
        $this->assertSame(1, $this->db->table('expenses')->where('amount', 0)->paginate()->lastPage());

        // Grouped queries count their groups.
        $this->assertSame(3, $this->db->table('expenses')->groupBy('category_id')->select('category_id')->paginate(2)->total);

        $response = $page->toResponse();
        $this->assertSame(['pagination' => $page->meta()], $response->meta());
        $this->assertSame($page->items, $response->data());
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

    public function test_insert_into_a_table_without_an_id_inside_a_transaction(): void
    {
        // Postgres' lastInsertId() is LASTVAL(): it used to fail here and abort the transaction.
        $db = \Pype\Tests\Support\TestDatabase::fresh();
        $db->statement('CREATE TABLE plain (name VARCHAR(20) PRIMARY KEY)');

        $db->transaction(function ($db) {
            $db->table('plain')->insert(['name' => 'a']);
            $db->table('plain')->insert(['name' => 'b']);
        });

        $this->assertSame(2, $db->table('plain')->count());
    }

    public function test_identifiers_are_remembered_and_capped(): void
    {
        $db = \Pype\Tests\Support\TestDatabase::fresh();
        $q = fn () => $db->table('posts as p')->join('users as u', 'u.id', '=', 'p.user_id')->select('p.id', 'u.name as author')->where('p.id', 1);

        $first = $q()->toSql();
        $this->assertSame($first, $q()->toSql());   // second build comes from the memo
        $this->assertStringContainsString($db->quote('u') . '.' . $db->quote('name') . ' AS ' . $db->quote('author'), $first);

        // Past the cap new identifiers still quote correctly, they just aren't remembered.
        for ($i = 0; $i < 1100; $i++) {
            $this->assertStringContainsString($db->quote("c{$i}"), $db->table('t')->select("c{$i}")->toSql());
        }

        // Invalid ones still throw, every time.
        foreach ([1, 2] as $_) {
            try {
                $db->table('t')->select('a; DROP TABLE t')->toSql();
                $this->fail('accepted an invalid identifier');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_insert_returning(): void
    {
        $db = \Pype\Tests\Support\TestDatabase::fresh();
        (new \Pype\Database\Schema\Schema($db))->create('notes', function (\Pype\Database\Schema\Table $t) {
            $t->id();
            $t->string('body');
            $t->string('status')->default('new');
        });

        $row = $db->table('notes')->insertReturning(['body' => 'Hi'], ['id', 'status']);
        $this->assertSame(['id' => 1, 'status' => 'new'], ['id' => (int) $row['id'], 'status' => $row['status']]);

        $all = $db->table('notes')->insertReturning(['body' => 'Again']);
        $this->assertSame([2, 'Again', 'new'], [(int) $all['id'], $all['body'], $all['status']]);
        $this->assertSame(2, $db->table('notes')->count());
    }
}

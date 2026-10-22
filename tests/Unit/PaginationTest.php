<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Database\Connection;
use Pype\Database\Page;
use Pype\Database\PageLink;
use Pype\Database\PageUrls;
use Pype\Database\SimplePage;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Testing\TestClient;
use Pype\Tests\Support\TestDatabase;
use Pype\View\View;
use Pype\View\ViewProvider;

/** paginate() with the request, links(), clamping, simplePaginate(), cheaper counts, $page in views. */
final class PaginationTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::fresh();
        $this->db->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, n INTEGER NOT NULL)');
        $this->db->table('items')->insertMany(array_map(static fn (int $n) => ['id' => $n, 'n' => $n], range(1, 23)));
    }

    /** "1 … 7 8 [9] 10 11 … 9000" */
    private static function show(Page $page, int $window = 2): string
    {
        return implode(' ', array_map(
            static fn (PageLink $l) => $l->gap ? '…' : ($l->current ? "[{$l->number}]" : (string) $l->number),
            $page->links($window),
        ));
    }

    private static function page(int $page, int $last): Page
    {
        return new Page([], $last * 10, 10, $page);
    }

    public function test_the_link_window(): void
    {
        $this->assertSame('1 … 7 8 [9] 10 11 … 9000', self::show(self::page(9, 9000)));
        $this->assertSame('[1] 2 3 … 9000', self::show(self::page(1, 9000)));
        $this->assertSame('1 … 8998 8999 [9000]', self::show(self::page(9000, 9000)));
        $this->assertSame('[1]', self::show(self::page(1, 1)));
        $this->assertSame('1 [2] 3 4 5', self::show(self::page(2, 5)));
        $this->assertSame('1 2 3 [4] 5 6 7', self::show(self::page(4, 7)));     // 1 2 3, not 1 … 3
        $this->assertSame('1 … 4 [5] 6 … 9', self::show(self::page(5, 9), 1));
        $this->assertSame('[1]', self::show(new Page([], 0, 10, 1)));           // nothing at all
    }

    public function test_links_keep_the_other_query_parameters(): void
    {
        $request = Request::create('GET', '/quotes?status=sent&q=acme%20co&page=2');
        $page = $this->db->table('items')->orderBy('id')->paginate(10, $request);

        $this->assertSame(2, $page->page);
        $this->assertSame([11, 12, 13, 14, 15, 16, 17, 18, 19, 20], array_column($page->items, 'n'));
        $this->assertSame('/quotes?status=sent&q=acme%20co', $page->previousUrl());   // page 1 has no ?page=
        $this->assertSame('/quotes?status=sent&q=acme%20co&page=3', $page->nextUrl());
        $this->assertSame('/quotes?status=sent&q=acme%20co&page=3', $page->links()[2]->url);
    }

    public function test_the_page_number_from_the_request_is_cleaned_and_clamped(): void
    {
        $at = fn (string $query) => $this->db->table('items')->paginate(10, Request::create('GET', "/items?{$query}"))->page;

        $this->assertSame(1, $at(''));
        $this->assertSame(1, $at('page=abc'));
        $this->assertSame(1, $at('page=-3'));
        $this->assertSame(1, $at('page[]=2'));
        $this->assertSame(3, $at('page=3'));
        $this->assertSame(3, $at('page=99999'));   // past the end: the last page

        $last = $this->db->table('items')->orderBy('id')->paginate(10, 99);
        $this->assertSame([21, 22, 23], array_column($last->items, 'n'));
        $this->assertSame(1, $this->db->table('items')->where('n', '>', 100)->paginate(10, 5)->page);

        $other = $this->db->table('items')->paginate(10, Request::create('GET', '/items?p=2'), pageName: 'p');
        $this->assertSame(2, $other->page);
        $this->assertSame('/items?p=3', $other->nextUrl());
    }

    public function test_without_a_request_links_are_query_only(): void
    {
        $page = $this->db->table('items')->paginate(10, 2);

        $this->assertSame('?', $page->previousUrl());
        $this->assertSame('?page=3', $page->nextUrl());
        $this->assertSame('/x?a=1&page=2', (new PageUrls('/x', ['a' => '1']))->to(2));
    }

    public function test_a_count_can_be_given(): void
    {
        $page = $this->db->table('items')->orderBy('id')->paginate(10, 1, count: $this->db->table('items')->where('n', '<=', 15));
        $this->assertSame(15, $page->total);
        $this->assertSame(2, $page->lastPage());

        $known = $this->db->table('items')->paginate(10, 1, count: 40);
        $this->assertSame(4, $known->lastPage());
    }

    public function test_simple_paginate_knows_only_whether_there_is_more(): void
    {
        $request = Request::create('GET', '/items?sort=n&page=2');
        $page = $this->db->table('items')->orderBy('id')->simplePaginate(10, $request);

        $this->assertInstanceOf(SimplePage::class, $page);
        $this->assertSame([11, 12, 13, 14, 15, 16, 17, 18, 19, 20], array_column($page->items, 'n'));
        $this->assertTrue($page->hasMore);
        $this->assertSame('/items?sort=n&page=3', $page->nextUrl());
        $this->assertSame('/items?sort=n', $page->previousUrl());
        $this->assertSame(['pagination' => ['page' => 2, 'per_page' => 10, 'has_more' => true, 'next' => '/items?sort=n&page=3', 'previous' => '/items?sort=n']], $page->toResponse()->meta());

        $last = $this->db->table('items')->orderBy('id')->simplePaginate(10, 3);
        $this->assertSame([21, 22, 23], array_column($last->items, 'n'));
        $this->assertFalse($last->hasMore);
        $this->assertNull($last->nextUrl());

        $exact = $this->db->table('items')->orderBy('id')->simplePaginate(23);
        $this->assertCount(23, $exact->items);
        $this->assertFalse($exact->hasMore);
    }

    public function test_json_is_unchanged_and_views_get_page(): void
    {
        $app = new Application(dirname(__DIR__, 2), ['app' => ['views' => 'tests/fixtures/views']]);
        $app->register(ViewProvider::class);
        $app->container->instance(Connection::class, $this->db);
        $list = fn (Request $request, Connection $db) => $db->table('items')->orderBy('id')->select('n')->paginate(5, $request);
        $app->router->get('/items', $list);
        $app->router->get('/pages', [$list, new View('pages')]);
        $client = new TestClient($app);

        $json = $client->get('/items?page=2');
        $this->assertSame([['n' => 6], ['n' => 7], ['n' => 8], ['n' => 9], ['n' => 10]], $json->json('data'));
        $this->assertSame(['page' => 2, 'per_page' => 5, 'total' => 23, 'last_page' => 5, 'next' => '/items?page=3', 'previous' => '/items'], $json->json('meta.pagination'));

        $html = $client->get('/pages?q=x&page=3')->body();
        $this->assertStringContainsString('| 11,12,13,14,15', $html);
        $this->assertStringContainsString('<a href="/pages?q=x">1</a> <a href="/pages?q=x&amp;page=2">2</a> [3] <a href="/pages?q=x&amp;page=4">4</a> <a href="/pages?q=x&amp;page=5">5</a>', $html);

        $this->assertInstanceOf(Page::class, Response::from($this->db->table('items')->paginate())->source());
    }
}

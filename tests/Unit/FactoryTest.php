<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Console\Console;
use Pype\Console\Input;
use Pype\Database\Connection;
use Pype\Database\Fake;
use Pype\Database\Factory;
use Pype\Database\Schema\Schema;
use Pype\Database\Schema\Table;
use Pype\Database\Seeder;
use Pype\Tests\Support\TestDatabase;

/** Factories, insertMany and db:seed, on SQLite, MySQL and Postgres. */
final class FactoryTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::fresh();
        $schema = new Schema($this->db);
        $schema->create('authors', function (Table $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('member');
        });
        $schema->create('books', function (Table $t) {
            $t->id();
            $t->foreignId('author_id')->constrained('authors');
            $t->string('title');
            $t->integer('pages');
        });
    }

    public function test_create_returns_stored_objects_with_ids_and_defaults(): void
    {
        $ada = AuthorFactory::new($this->db)->create(['name' => 'Ada']);

        $this->assertInstanceOf(FactoryAuthor::class, $ada);
        $this->assertSame('Ada', $ada->name);
        $this->assertSame('member', $ada->role);   // the column default, read back
        $this->assertSame(1, $ada->id);
        $this->assertTrue(password_verify('password', $this->db->table('authors')->value('password')));

        $many = AuthorFactory::new($this->db)->count(3)->create();
        $this->assertCount(3, $many);
        $this->assertSame([2, 3, 4], array_map(fn ($a) => $a->id, $many));
        $this->assertSame(4, $this->db->table('authors')->count());
    }

    public function test_make_does_not_insert_and_rows_without_a_class(): void
    {
        $row = AuthorFactory::new($this->db)->make(['role' => 'admin']);

        $this->assertSame('admin', $row['role']);
        $this->assertSame(['name', 'email', 'password', 'role'], array_keys($row));   // role from the override
        $this->assertCount(5, AuthorFactory::new($this->db)->count(5)->make());
        $this->assertSame(0, $this->db->table('authors')->count());

        // Without a $class, create() still returns an object: $book->id, and $book['id'] for old code.
        $book = BookFactory::new($this->db)->create();
        $this->assertInstanceOf(\Pype\Database\Row::class, $book);
        $this->assertSame(1, (int) $book->id);
        $this->assertSame(1, (int) $book['id']);
        $this->assertSame(['id', 'author_id', 'title', 'pages'], array_keys($book->toArray()));
        $this->assertSame($book->toArray(), json_decode((string) json_encode($book), true));
    }

    public function test_states_sequences_lazy_relations_and_after_creating(): void
    {
        // A lazy relation: each book creates its author only when none is given.
        BookFactory::new($this->db)->count(2)->create();
        $this->assertSame(2, $this->db->table('authors')->count());

        $ada = AuthorFactory::new($this->db)->create();
        BookFactory::new($this->db)->count(3)->state(['author_id' => $ada->id])->create();
        $this->assertSame(3, $this->db->table('authors')->count());   // no new authors
        $this->assertSame(3, $this->db->table('books')->where('author_id', $ada->id)->count());

        $roles = AuthorFactory::new($this->db)->count(4)->sequence(['role' => 'admin'], ['role' => 'member'])->make();
        $this->assertSame(['admin', 'member', 'admin', 'member'], array_column($roles, 'role'));

        $titled = BookFactory::new($this->db)->count(2)->state(fn (array $row, int $i) => ['title' => "Book {$i}"])->make(['author_id' => 1]);
        $this->assertSame(['Book 0', 'Book 1'], array_column($titled, 'title'));

        $withBooks = AuthorFactory::new($this->db)->afterCreating(
            fn (FactoryAuthor $a) => BookFactory::new($this->db)->count(2)->state(['author_id' => $a->id])->insert(),
        )->count(2)->create();
        $this->assertSame(2, $this->db->table('books')->where('author_id', $withBooks[1]->id)->count());
    }

    public function test_insert_in_bulk_crosses_chunk_boundaries(): void
    {
        $ada = AuthorFactory::new($this->db)->create();

        $this->assertSame(2500, BookFactory::new($this->db)->count(2500)->state(['author_id' => $ada->id])->insert());
        $this->assertSame(2500, $this->db->table('books')->count());
        $this->assertSame(0, $this->db->table('books')->insertMany([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->db->table('books')->insertMany([['author_id' => 1, 'title' => 'a', 'pages' => 1], ['author_id' => 1, 'title' => 'b']]);
    }

    public function test_a_seeded_fake_makes_the_same_rows(): void
    {
        $a = AuthorFactory::new($this->db, new Fake(seed: 3))->count(3)->make();
        $b = AuthorFactory::new($this->db, new Fake(seed: 3))->count(3)->make();

        $this->assertSame(array_column($a, 'name'), array_column($b, 'name'));
    }

    private function console(array $config, string ...$argv): array
    {
        $app = new Application(__DIR__, ['app' => $config]);
        $app->container->instance(Connection::class, $this->db);
        ob_start();
        $code = (new Console($app))->run(['pype', ...$argv]);

        return [$code, (string) ob_get_clean()];
    }

    public function test_db_seed_command(): void
    {
        [$code] = $this->console(['debug' => false], 'db:seed', '--class=' . TestSeeder::class);
        $this->assertSame(1, $code);   // production: needs --force
        $this->assertSame(0, $this->db->table('authors')->count());

        [$code, $out] = $this->console(['debug' => true], 'db:seed', '--class=' . TestSeeder::class, '--authors=4');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Seeded: ' . TestSeeder::class, $out);
        $this->assertSame(4 + 2, $this->db->table('authors')->count());   // its own 4 + the called seeder's 2

        [$code] = $this->console(['debug' => false], 'db:seed', '--class=' . TestSeeder::class, '--force');
        $this->assertSame(0, $code);
        $this->assertSame(1, $this->console(['debug' => true], 'db:seed', '--class=Nope')[0]);
    }
}

final class FactoryAuthor
{
    public function __construct(public readonly int $id, public readonly string $name, public readonly string $email, public readonly string $role)
    {
    }
}

final class AuthorFactory extends Factory
{
    protected string $table = 'authors';

    protected ?string $class = FactoryAuthor::class;

    protected function definition(Fake $fake): array
    {
        return ['name' => $fake->name(), 'email' => $fake->email(), 'password' => self::password()];
    }
}

final class BookFactory extends Factory
{
    protected string $table = 'books';

    protected function definition(Fake $fake): array
    {
        return [
            'author_id' => fn () => AuthorFactory::new($this->db)->create()->id,
            'title' => $fake->sentence(3),
            'pages' => $fake->int(50, 900),
        ];
    }
}

final class TestSeeder extends Seeder
{
    public function run(Connection $db, Input $input): void
    {
        AuthorFactory::new($db)->count((int) $input->option('authors', '1'))->insert();
        $this->call(OtherSeeder::class);
    }
}

final class OtherSeeder extends Seeder
{
    public function run(Connection $db): void
    {
        AuthorFactory::new($db)->count(2)->create();
    }
}

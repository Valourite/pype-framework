<?php

declare(strict_types=1);

namespace Pype\Database;

use Closure;

/**
 * Builds rows for one table, for tests and seeders. Subclass it per table:
 *
 *   final class UserFactory extends Factory
 *   {
 *       protected string $table = 'users';
 *       protected ?string $class = User::class;    // what create() returns (null: arrays)
 *
 *       protected function definition(Fake $fake): array
 *       {
 *           return ['name' => $fake->name(), 'email' => $fake->email(), 'password' => self::password()];
 *       }
 *   }
 *
 *   $ada = UserFactory::new($db)->create(['name' => 'Ada']);        // inserted, a User
 *   $users = UserFactory::new($db)->count(10)->create();             // list<User>
 *   UserFactory::new($db)->make();                                    // a row (array), not inserted
 *   ExpenseFactory::new($db)->count(500)->state(['user_id' => $ada->id])->insert();   // fast: returns the count
 *
 * Values may be closures, called per row with the row built so far, so related rows are only
 * created when nothing else supplied them: 'user_id' => fn () => UserFactory::new($this->db)->create()->id.
 * The builder methods return copies, so a configured factory can be reused.
 */
abstract class Factory
{
    /** The table rows go into. */
    protected string $table = '';

    /** @var class-string|null the data class create() returns rows as; null = arrays */
    protected ?string $class = null;

    protected readonly Fake $fake;

    private ?int $count = null;

    /** @var list<array|Closure> */
    private array $states = [];

    /** @var list<array> */
    private array $sequence = [];

    /** @var list<Closure> */
    private array $afterCreating = [];

    /** @var array<string, string> */
    private static array $passwords = [];

    public function __construct(protected readonly Connection $db, ?Fake $fake = null)
    {
        $this->fake = $fake ?? new Fake();
    }

    public static function new(Connection $db, ?Fake $fake = null): static
    {
        return new static($db, $fake);
    }

    /** The columns of one made-up row. Closures are called per row with the row so far. */
    abstract protected function definition(Fake $fake): array;

    /** How many rows: make()/create() then return lists. */
    public function count(int $count): static
    {
        $copy = clone $this;
        $copy->count = $count;

        return $copy;
    }

    /** Columns to set on every row: an array, or fn (array $row, int $index): array. */
    public function state(array|Closure $state): static
    {
        $copy = clone $this;
        $copy->states[] = $state;

        return $copy;
    }

    /** Rows take these in turn: sequence(['role' => 'admin'], ['role' => 'member']). */
    public function sequence(array ...$states): static
    {
        $copy = clone $this;
        $copy->sequence = array_values($states);

        return $copy;
    }

    /** Run after each row is created (with the created object or array). */
    public function afterCreating(Closure $callback): static
    {
        $copy = clone $this;
        $copy->afterCreating[] = $callback;

        return $copy;
    }

    /**
     * Rows without inserting them.
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function make(array $overrides = []): array
    {
        $rows = $this->rows($overrides);

        return $this->count === null ? $rows[0] : $rows;
    }

    /**
     * Insert the rows and return them as stored (ids and database defaults filled in), as
     * $class objects or arrays. One INSERT ... RETURNING per row, so ids are known.
     *
     * @return object|array<string, mixed>|list<object|array<string, mixed>>
     */
    public function create(array $overrides = []): object|array
    {
        $created = $this->db->transaction(function (Connection $db) use ($overrides) {
            $created = [];

            foreach ($this->rows($overrides) as $row) {
                $stored = $db->table($this->table)->insertReturning($row);
                $model = $this->class === null ? $stored : Hydrator::make($this->class, $stored);

                foreach ($this->afterCreating as $callback) {
                    $callback($model);
                }

                $created[] = $model;
            }

            return $created;
        });

        return $this->count === null ? $created[0] : $created;
    }

    /** Insert the rows in as few statements as possible and return how many: for volume (seeding). */
    public function insert(array $overrides = []): int
    {
        if ($this->afterCreating !== []) {
            throw new \LogicException('afterCreating() needs create(); insert() returns no rows to pass it.');
        }

        return $this->db->table($this->table)->insertMany($this->rows($overrides));
    }

    /** A password hash made once per process: hashing per row would take minutes for big seeds. */
    public static function password(string $plain = 'password'): string
    {
        return self::$passwords[$plain] ??= password_hash($plain, PASSWORD_DEFAULT);
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $overrides): array
    {
        if ($this->table === '') {
            throw new \LogicException(static::class . ' has no $table.');
        }

        $rows = [];

        for ($i = 0, $n = $this->count ?? 1; $i < $n; $i++) {
            $row = $this->definition($this->fake);

            foreach ($this->states as $state) {
                $row = [...$row, ...($state instanceof Closure ? $state($row, $i) : $state)];
            }

            if ($this->sequence !== []) {
                $row = [...$row, ...$this->sequence[$i % count($this->sequence)]];
            }

            $row = [...$row, ...$overrides];

            foreach ($row as $column => $value) {
                if ($value instanceof Closure) {
                    $row[$column] = $value($row);
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }
}

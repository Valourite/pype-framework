<?php

declare(strict_types=1);

namespace Pype\Database\Schema;

/** One column in a Table. Modifiers chain: $t->string('email')->nullable()->unique(). */
final class Column
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $useCurrent = false;
    public bool $unique = false;
    public bool $primary = false;
    public ?string $index = null;
    public ?string $check = null;

    /** Foreign key: [table, column, on delete action] */
    public ?array $references = null;

    /** Index a foreign key column (SQLite and Postgres don't do it themselves; MySQL does). */
    public bool $indexForeignKey = true;

    /** @param array<string, int> $args  length, precision, scale */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $args = [],
    ) {
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    /** DEFAULT CURRENT_TIMESTAMP */
    public function useCurrent(): self
    {
        $this->useCurrent = true;

        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    /** Plain index on this column (name defaults to <table>_<column>_index). */
    public function index(string $name = ''): self
    {
        $this->index = $name;

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }

    /** CHECK constraint, e.g. ->check('amount_cents > 0') */
    public function check(string $sql): self
    {
        $this->check = $sql;

        return $this;
    }

    /** Foreign key to $table.$column (default: guessed from the name, user_id → users, category_id → categories). */
    public function constrained(?string $table = null, string $column = 'id'): self
    {
        $this->references = [$table ?? $this->guessTable(), $column, null];

        return $this;
    }

    /**
     * Don't add the index constrained() adds on SQLite and Postgres: for a column already
     * covered by a composite index that starts with it, or one you never look rows up by.
     */
    public function withoutIndex(): self
    {
        $this->indexForeignKey = false;

        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    public function restrictOnDelete(): self
    {
        return $this->onDelete('RESTRICT');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('SET NULL');
    }

    private function onDelete(string $action): self
    {
        $this->references ??= [$this->guessTable(), 'id', null];
        $this->references[2] = $action;

        return $this;
    }

    private function guessTable(): string
    {
        $singular = preg_replace('/_id$/', '', $this->name);

        return match (true) {
            (bool) preg_match('/[^aeiou]y$/', $singular) => substr($singular, 0, -1) . 'ies',
            (bool) preg_match('/(s|x|z|ch|sh)$/', $singular) => $singular . 'es',
            default => $singular . 's',
        };
    }
}

<?php

declare(strict_types=1);

namespace Pype\Database\Schema;

/** What to create or change on one table. Filled by the closure passed to Schema. */
final class Table
{
    /** @var list<Column> */
    public array $columns = [];

    /** @var list<array{columns: list<string>, name: string, unique: bool}> */
    public array $indexes = [];

    /** @var list<string> */
    public array $dropColumns = [];

    /** @var array<string, string> from => to */
    public array $renameColumns = [];

    /** @var list<string> */
    public array $dropIndexes = [];

    public function __construct(public readonly string $name)
    {
    }

    /** Auto-incrementing BIGINT primary key (INTEGER on SQLite, so it aliases the rowid). */
    public function id(string $name = 'id'): Column
    {
        return $this->add($name, 'id');
    }

    /** Integer column sized to hold another table's id(). Chain ->constrained() for the foreign key. */
    public function foreignId(string $name): Column
    {
        return $this->add($name, 'foreignId');
    }

    public function string(string $name, int $length = 255): Column
    {
        return $this->add($name, 'string', ['length' => $length]);
    }

    public function text(string $name): Column
    {
        return $this->add($name, 'text');
    }

    public function integer(string $name): Column
    {
        return $this->add($name, 'integer');
    }

    public function bigInteger(string $name): Column
    {
        return $this->add($name, 'bigInteger');
    }

    public function boolean(string $name): Column
    {
        return $this->add($name, 'boolean');
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): Column
    {
        return $this->add($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function date(string $name): Column
    {
        return $this->add($name, 'date');
    }

    public function timestamp(string $name): Column
    {
        return $this->add($name, 'timestamp');
    }

    /** created_at (set by the database on insert) and updated_at (nullable). */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->useCurrent();
        $this->timestamp('updated_at')->nullable();
    }

    public function json(string $name): Column
    {
        return $this->add($name, 'json');
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): self
    {
        return $this->addIndex((array) $columns, $name, false);
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): self
    {
        return $this->addIndex((array) $columns, $name, true);
    }

    public function dropColumn(string $name): self
    {
        $this->dropColumns[] = $name;

        return $this;
    }

    public function renameColumn(string $from, string $to): self
    {
        $this->renameColumns[$from] = $to;

        return $this;
    }

    public function dropIndex(string $name): self
    {
        $this->dropIndexes[] = $name;

        return $this;
    }

    private function add(string $name, string $type, array $args = []): Column
    {
        return $this->columns[] = new Column($name, $type, $args);
    }

    private function addIndex(array $columns, ?string $name, bool $unique): self
    {
        $this->indexes[] = [
            'columns' => $columns,
            'name' => $name ?? $this->name . '_' . implode('_', $columns) . ($unique ? '_unique' : '_index'),
            'unique' => $unique,
        ];

        return $this;
    }
}

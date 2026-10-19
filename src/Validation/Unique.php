<?php

declare(strict_types=1);

namespace Pype\Validation;

/**
 * The value isn't in $table.$column yet. String form: 'unique:users,email' or, to skip the row
 * being edited, 'unique:users,email,{id}' ({id} = the route param). Object form for more:
 *
 *   new Unique('categories', 'name', where: ['user_id' => $user->id], ignore: $category->id)
 */
final class Unique extends DatabaseRule
{
    /** @param array<string, scalar|null> $where extra column => value conditions (scope, e.g. per user) */
    public function __construct(
        private readonly string $table,
        private readonly ?string $column = null,
        private readonly int|string|null $ignore = null,
        private readonly string $idColumn = 'id',
        private readonly array $where = [],
    ) {
    }

    public function check(string $field, mixed $value, array $data): ?string
    {
        $query = $this->db()->table($this->table)->where($this->column ?? $field, $value);

        if ($this->ignore !== null && $this->ignore !== '') {
            $query->where($this->idColumn, '!=', $this->ignore);
        }

        foreach ($this->where as $column => $is) {
            $is === null ? $query->whereNull($column) : $query->where($column, $is);
        }

        return $query->exists() ? "The {$field} has already been taken." : null;
    }
}

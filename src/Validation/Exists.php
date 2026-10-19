<?php

declare(strict_types=1);

namespace Pype\Validation;

/**
 * The value is in $table.$column (a real category id, a known coupon code...). String form:
 * 'exists:categories,id'. Object form to scope it: new Exists('categories', 'id', where: ['user_id' => $id])
 */
final class Exists extends DatabaseRule
{
    /** @param array<string, scalar|null> $where */
    public function __construct(
        private readonly string $table,
        private readonly ?string $column = null,
        private readonly array $where = [],
    ) {
    }

    public function check(string $field, mixed $value, array $data): ?string
    {
        $query = $this->db()->table($this->table)->where($this->column ?? $field, $value);

        foreach ($this->where as $column => $is) {
            $is === null ? $query->whereNull($column) : $query->where($column, $is);
        }

        return $query->exists() ? null : "The selected {$field} is invalid.";
    }
}

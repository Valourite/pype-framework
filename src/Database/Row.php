<?php

declare(strict_types=1);

namespace Pype\Database;

use ArrayAccess;
use JsonSerializable;
use stdClass;

/**
 * A stored row as an object, for factories without a $class: $row->id and $row['id'] both
 * work, and a column that isn't there warns ("Undefined property") instead of quietly being null.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class Row extends stdClass implements ArrayAccess, JsonSerializable
{
    /** @param array<string, mixed> $columns */
    public function __construct(array $columns = [])
    {
        foreach ($columns as $name => $value) {
            $this->{$name} = $value;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, (string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->{(string) $offset};
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->{(string) $offset} = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->{(string) $offset});
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

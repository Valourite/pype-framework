<?php

declare(strict_types=1);

namespace Pype\Validation;

use LogicException;
use Pype\Database\Connection;

/**
 * A rule that queries the database. The Validate stage hands it the app's Connection; rule
 * objects in route definitions are shared by every request, so using() returns a copy.
 */
abstract class DatabaseRule implements Rule
{
    private ?Connection $db = null;

    public function using(Connection $db): static
    {
        $copy = clone $this;
        $copy->db = $db;

        return $copy;
    }

    protected function db(): Connection
    {
        return $this->db ?? throw new LogicException(static::class . ' needs a database. Validate it through the Validate stage, or pass a Connection to Validator::validate().');
    }
}

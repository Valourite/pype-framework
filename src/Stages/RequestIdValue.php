<?php

declare(strict_types=1);

namespace Pype\Stages;

/** The current request's id, as put in the context by the RequestId stage. */
final class RequestIdValue
{
    public function __construct(public readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

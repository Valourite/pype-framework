<?php

declare(strict_types=1);

namespace Pype\Validation;

/**
 * Input that passed validation. Put in the request context by the Validate stage:
 *
 *   $input = $request->context()->get(ValidatedInput::class);
 */
final class ValidatedInput
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}

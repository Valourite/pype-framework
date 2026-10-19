<?php

declare(strict_types=1);

namespace Pype\Validation;

/**
 * A reusable rule. Put an instance in a field's rule list:
 *
 *   final class Postcode implements Rule
 *   {
 *       public function check(string $field, mixed $value, array $data): ?string
 *       {
 *           return preg_match('/^\d{4}$/', (string) $value) ? null : "The {$field} must be a 4-digit postcode.";
 *       }
 *   }
 *
 *   ['postcode' => ['required', new Postcode()]]
 */
interface Rule
{
    /**
     * @param array<string, mixed> $data  all input, for rules that compare fields
     * @return string|null  an error message, or null when the value is fine
     */
    public function check(string $field, mixed $value, array $data): ?string;
}

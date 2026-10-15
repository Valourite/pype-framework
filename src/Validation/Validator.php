<?php

declare(strict_types=1);

namespace Pype\Validation;

use Closure;
use InvalidArgumentException;

/**
 * Small rule-based validator.
 *
 * Rules: required, nullable, string, int, numeric, bool, array, email,
 *        min:n, max:n (length for strings, value for numbers, count for arrays),
 *        in:a,b,c — or a Closure(mixed $value): ?string returning an error message.
 *
 *   ['name' => 'required|string|max:50', 'age' => ['nullable', 'int', 'min:18']]
 */
final class Validator
{
    /**
     * @param array<string, mixed>                       $data
     * @param array<string, string|list<string|Closure>> $rules
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} [validated, errors]
     */
    public static function validate(array $data, array $rules): array
    {
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $present = array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '';
            $value = $data[$field] ?? null;

            if (!$present) {
                if (in_array('required', $fieldRules, true)) {
                    $errors[$field][] = "The {$field} field is required.";
                } elseif (array_key_exists($field, $data)) {
                    $validated[$field] = $value;
                }
                continue;
            }

            foreach ($fieldRules as $rule) {
                $error = $rule instanceof Closure ? $rule($value) : self::check($field, $value, $rule);

                if ($error !== null) {
                    $errors[$field][] = $error;
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        return [$validated, $errors];
    }

    private static function check(string $field, mixed $value, string $rule): ?string
    {
        [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

        return match ($name) {
            'required', 'nullable' => null,
            'string' => is_string($value) ? null : "The {$field} field must be a string.",
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : "The {$field} field must be an integer.",
            'numeric' => is_numeric($value) ? null : "The {$field} field must be numeric.",
            'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true) ? null : "The {$field} field must be true or false.",
            'array' => is_array($value) ? null : "The {$field} field must be an array.",
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : "The {$field} field must be a valid email.",
            'min' => self::size($value) >= (float) $arg ? null : "The {$field} field must be at least {$arg}.",
            'max' => self::size($value) <= (float) $arg ? null : "The {$field} field may not be greater than {$arg}.",
            'in' => in_array((string) $value, explode(',', (string) $arg), true) ? null : "The {$field} field must be one of: {$arg}.",
            default => throw new InvalidArgumentException("Unknown validation rule [{$name}]."),
        };
    }

    private static function size(mixed $value): float
    {
        return match (true) {
            is_array($value) => count($value),
            is_int($value), is_float($value) => $value,
            is_numeric($value) => (float) $value,
            default => mb_strlen((string) $value),
        };
    }
}

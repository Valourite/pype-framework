<?php

declare(strict_types=1);

namespace Pype\Validation;

use Closure;
use Pype\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Small rule-based validator.
 *
 * Rules: required, nullable, string, int, numeric, bool, array, email, url,
 *        min:n, max:n (length for strings, value for numbers, count for arrays),
 *        in:a,b,c, date (Y-m-d) or date:<format>, confirmed (matches <field>_confirmation),
 *        file, image, mimes:pdf,jpg (real type, not the browser's claim); max:n is in KB for files
 *        — or a Closure(mixed $value): ?string returning an error message.
 *
 *   ['name' => 'required|string|max:50', 'age' => ['nullable', 'int', 'min:18']]
 *
 * Fields without 'required' are optional: when missing they are skipped, which suits partial
 * updates. Sent but empty (null or ""), they fail unless the field is 'nullable'.
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
                } elseif (in_array('nullable', $fieldRules, true)) {
                    if (array_key_exists($field, $data)) {
                        $validated[$field] = null;
                    }
                } elseif (array_key_exists($field, $data)) {
                    $errors[$field][] = "The {$field} field must not be empty.";
                }
                continue;
            }

            foreach ($fieldRules as $rule) {
                $error = $rule instanceof Closure ? $rule($value) : self::check($field, $value, $rule, $data);

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

    private static function check(string $field, mixed $value, string $rule, array $data): ?string
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
            'max' => self::size($value) <= (float) $arg ? null : ($value instanceof UploadedFile
                ? "The {$field} may not be larger than " . self::kb((int) $arg) . '.'
                : "The {$field} field may not be greater than {$arg}."),
            'in' => in_array((string) $value, explode(',', (string) $arg), true) ? null : "The {$field} field must be one of: {$arg}.",
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) ? null : "The {$field} field must be a valid URL.",
            'date' => self::isDate($value, $arg ?? 'Y-m-d') ? null : "The {$field} field must be a date like " . date($arg ?? 'Y-m-d', 0) . '.',
            'file' => $value instanceof UploadedFile && $value->isValid() ? null : self::fileError($field, $value),
            'image' => $value instanceof UploadedFile && $value->isValid() && in_array($value->extension(), ['jpg', 'png', 'gif', 'webp', 'avif'], true)
                ? null : "The {$field} must be an image (jpg, png, gif, webp or avif).",
            'mimes' => $value instanceof UploadedFile && $value->isValid() && in_array($value->extension(), explode(',', (string) $arg), true)
                ? null : "The {$field} must be a file of type: " . str_replace(',', ', ', (string) $arg) . '.',
            'confirmed' => ($data["{$field}_confirmation"] ?? null) === $value ? null : "The {$field} confirmation does not match.",
            default => throw new InvalidArgumentException("Unknown validation rule [{$name}]."),
        };
    }

    /** A real date in exactly this format: 2026-02-30 and 2026-1-5 both fail for Y-m-d. */
    private static function isDate(mixed $value, string $format): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $date !== false && $date->format($format) === $value;
    }

    private static function fileError(string $field, mixed $value): string
    {
        return $value instanceof UploadedFile ? "The {$field} upload failed: " . $value->errorMessage() : "The {$field} must be a file.";
    }

    private static function kb(int $kb): string
    {
        return $kb >= 1024 ? round($kb / 1024, 1) . ' MB' : "{$kb} KB";
    }

    private static function size(mixed $value): float
    {
        return match (true) {
            $value instanceof UploadedFile => $value->size() / 1024,
            is_array($value) => count($value),
            is_int($value), is_float($value) => $value,
            is_numeric($value) => (float) $value,
            default => mb_strlen((string) $value),
        };
    }
}

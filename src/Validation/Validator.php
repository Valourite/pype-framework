<?php

declare(strict_types=1);

namespace Pype\Validation;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Pype\Database\Connection;
use Pype\Http\UploadedFile;

/**
 * Rule-based validator built on native checks (filter_var, preg_match, ctype, DateTime).
 *
 *   ['name' => 'required|string|max:50', 'age' => ['nullable', 'int', 'min:18']]
 *
 * Presence: required, nullable, required_if:field,value, required_with:field
 * Types:    string, int, numeric, bool, array, accepted, json
 * Size:     min:n, max:n, between:a,b (length for strings, value for numbers, count for arrays,
 *           KB for files), digits:n
 * Format:   email, url, ip, uuid, alpha, alpha_num, alpha_dash, regex:/pattern/,
 *           date (Y-m-d) or date:<format>
 * Compare:  in:a,b, not_in:a,b, same:field, different:field, confirmed (<field>_confirmation),
 *           before:x, after:x, before_or_equal:x, after_or_equal:x (x = a date or another field)
 * Files:    file, image, mimes:pdf,jpg (the real type, not the browser's claim)
 * Database: unique:table,column[,ignoreId[,idColumn]], exists:table,column (or new Unique/Exists)
 * Custom:   a Rule object, or a Closure(mixed $value, string $field, array $data): ?string
 *
 * Nested input with dots: 'address.city'; every item of an array with *: 'tags.*' => 'string|max:20',
 * 'items.*.qty' => 'required|int|min:1'. Errors are keyed by the real path: items.0.qty.
 *
 * Fields without 'required' are optional: when missing they are skipped, which suits partial
 * updates. Sent but empty (null or ""), they fail unless the field is 'nullable'.
 *
 * Messages: pass ['email.unique' => 'That email is taken.', 'required' => '{field} is needed.'],
 * keyed by "field.rule", "field" or "rule"; {field} and {arg} are filled in.
 *
 * A regex containing | must be given in the array form: ['regex:/^(a|b)$/'].
 */
final class Validator
{
    /**
     * @param array<string, mixed>                                $data
     * @param array<string, string|list<string|Rule|Closure>>     $rules
     * @param array<string, string>                               $messages
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} [validated, errors]
     */
    public static function validate(array $data, array $rules, array $messages = [], ?Connection $db = null): array
    {
        $validated = [];
        $errors = [];

        foreach ($rules as $pattern => $fieldRules) {
            $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;

            foreach (str_contains($pattern, '*') ? self::expand($pattern, $data) : [$pattern] as $field) {
                // Plain keys are a direct lookup; only dotted paths walk nested arrays.
                if (array_key_exists($field, $data)) {
                    [$present, $value] = [true, $data[$field]];
                } else {
                    [$present, $value] = str_contains($field, '.') ? self::lookup($data, $field) : [false, null];
                }

                if (!$present || $value === null || $value === '') {
                    if (self::isRequired($fieldRules, $data)) {
                        $errors[$field][] = self::message($messages, $pattern, $field, 'required', null, "The {$field} field is required.");
                    } elseif (in_array('nullable', $fieldRules, true)) {
                        if ($present) {
                            self::set($validated, $field, null);
                        }
                    } elseif ($present) {
                        $errors[$field][] = self::message($messages, $pattern, $field, 'filled', null, "The {$field} field must not be empty.");
                    }
                    continue;
                }

                $failed = false;
                foreach ($fieldRules as $rule) {
                    if (is_string($rule)) {
                        [$name, $arg] = str_contains($rule, ':') ? explode(':', $rule, 2) : [$rule, null];
                        $error = $name === 'unique' || $name === 'exists'
                            ? self::database($field, $value, $name, (string) $arg, $data, $db)
                            : self::check($field, $value, $name, $arg, $data);
                    } else {
                        $arg = null;
                        [$name, $error] = match (true) {
                            $rule instanceof Closure => ['closure', $rule($value, $field, $data)],
                            $rule instanceof DatabaseRule => [self::ruleName($rule), is_scalar($value) ? $rule->using($db ?? throw new InvalidArgumentException('Database rules need a Connection.'))->check($field, $value, $data) : "The {$field} is invalid."],
                            $rule instanceof Rule => [self::ruleName($rule), $rule->check($field, $value, $data)],
                            default => throw new InvalidArgumentException('A rule must be a string, a Closure or a ' . Rule::class . '.'),
                        };
                    }

                    if ($error !== null) {
                        $errors[$field][] = $messages === [] ? $error : self::message($messages, $pattern, $field, $name, $arg, $error);
                        $failed = true;
                    }
                }

                if (!$failed) {
                    if (str_contains($field, '.')) {
                        self::set($validated, $field, $value);
                    } else {
                        $validated[$field] = $value;
                    }
                }
            }
        }

        return [$validated, $errors];
    }

    /** unique:table,column[,ignoreId[,idColumn]] and exists:table,column as Unique / Exists rules. */
    private static function database(string $field, mixed $value, string $name, string $arg, array $data, ?Connection $db): ?string
    {
        $parts = array_map('trim', explode(',', $arg));
        $rule = $name === 'unique'
            ? new Unique($parts[0], $parts[1] ?? null, ($parts[2] ?? '') === '' ? null : $parts[2], $parts[3] ?? 'id')
            : new Exists($parts[0], $parts[1] ?? null);

        return is_scalar($value)
            ? $rule->using($db ?? throw new InvalidArgumentException("The {$name} rule needs a database connection."))->check($field, $value, $data)
            : "The {$field} is invalid.";
    }

    private static function check(string $field, mixed $value, string $name, ?string $arg, array $data): ?string
    {
        return match ($name) {
            'required', 'nullable', 'required_if', 'required_with' => null,
            'string' => is_string($value) ? null : "The {$field} field must be a string.",
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : "The {$field} field must be an integer.",
            'numeric' => is_numeric($value) ? null : "The {$field} field must be numeric.",
            'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true) ? null : "The {$field} field must be true or false.",
            'array' => is_array($value) ? null : "The {$field} field must be an array.",
            'accepted' => in_array($value, [true, 1, '1', 'yes', 'on', 'true'], true) ? null : "The {$field} must be accepted.",
            'json' => is_string($value) && self::isJson($value) ? null : "The {$field} field must be valid JSON.",
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : "The {$field} field must be a valid email.",
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) ? null : "The {$field} field must be a valid URL.",
            'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false ? null : "The {$field} field must be a valid IP address.",
            'uuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) ? null : "The {$field} field must be a valid UUID.",
            'alpha' => is_string($value) && preg_match('/^[\pL\pM]+$/u', $value) ? null : "The {$field} field may only contain letters.",
            'alpha_num' => is_string($value) && preg_match('/^[\pL\pM\pN]+$/u', $value) ? null : "The {$field} field may only contain letters and numbers.",
            'alpha_dash' => is_scalar($value) && preg_match('/^[\pL\pM\pN_-]+$/u', (string) $value) ? null : "The {$field} field may only contain letters, numbers, dashes and underscores.",
            'regex' => is_scalar($value) && @preg_match((string) $arg, (string) $value) === 1 ? null : self::regexError($field, (string) $arg),
            'digits' => is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) === (int) $arg ? null : "The {$field} field must be {$arg} digits.",
            'min' => self::size($value) >= (float) $arg ? null : "The {$field} field must be at least {$arg}.",
            'max' => self::size($value) <= (float) $arg ? null : ($value instanceof UploadedFile
                ? "The {$field} may not be larger than " . self::kb((int) $arg) . '.'
                : "The {$field} field may not be greater than {$arg}."),
            'between' => self::between($value, (string) $arg) ? null : "The {$field} field must be between " . str_replace(',', ' and ', (string) $arg) . '.',
            'in' => is_scalar($value) && in_array((string) $value, explode(',', (string) $arg), true) ? null : "The {$field} field must be one of: {$arg}.",
            'not_in' => is_scalar($value) && !in_array((string) $value, explode(',', (string) $arg), true) ? null : "The selected {$field} is not allowed.",
            'same' => $value === self::lookup($data, (string) $arg)[1] ? null : "The {$field} and {$arg} fields must match.",
            'different' => $value !== self::lookup($data, (string) $arg)[1] ? null : "The {$field} and {$arg} fields must be different.",
            'confirmed' => (self::lookup($data, "{$field}_confirmation")[1]) === $value ? null : "The {$field} confirmation does not match.",
            'date' => self::isDate($value, $arg ?? 'Y-m-d') ? null : "The {$field} field must be a date like " . date($arg ?? 'Y-m-d', 0) . '.',
            'before', 'after', 'before_or_equal', 'after_or_equal' => self::compareDate($field, $value, $name, (string) $arg, $data),
            'file' => $value instanceof UploadedFile && $value->isValid() ? null : self::fileError($field, $value),
            'image' => $value instanceof UploadedFile && $value->isValid() && in_array($value->extension(), ['jpg', 'png', 'gif', 'webp', 'avif'], true)
                ? null : "The {$field} must be an image (jpg, png, gif, webp or avif).",
            'mimes' => $value instanceof UploadedFile && $value->isValid() && in_array($value->extension(), explode(',', (string) $arg), true)
                ? null : "The {$field} must be a file of type: " . str_replace(',', ', ', (string) $arg) . '.',
            default => throw new InvalidArgumentException("Unknown validation rule [{$name}]."),
        };
    }

    /** required, or required_if:field,value (the other field equals value), or required_with:field (it's filled). */
    private static function isRequired(array $rules, array $data): bool
    {
        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                continue;
            }
            if ($rule === 'required') {
                return true;
            }
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
            if ($name === 'required_if') {
                [$other, $expected] = array_pad(explode(',', $arg, 2), 2, '');
                $actual = self::lookup($data, $other)[1];
                if (is_scalar($actual) && (string) $actual === $expected) {
                    return true;
                }
            }
            if ($name === 'required_with') {
                $other = self::lookup($data, $arg)[1];
                if ($other !== null && $other !== '' && $other !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Concrete field paths for a rule key: 'tags.*' → tags.0, tags.1...; 'a.*.b' → a.0.b...
     * A path with no wildcard is returned as is.
     *
     * @return list<string>
     */
    private static function expand(string $pattern, array $data): array
    {
        if (!str_contains($pattern, '*')) {
            return [$pattern];
        }

        $paths = [''];
        foreach (explode('.', $pattern) as $segment) {
            $next = [];
            foreach ($paths as $path) {
                if ($segment !== '*') {
                    $next[] = $path === '' ? $segment : "{$path}.{$segment}";
                    continue;
                }
                [, $items] = self::lookup($data, $path);
                foreach (is_array($items) ? array_keys($items) : [] as $key) {
                    $next[] = $path === '' ? (string) $key : "{$path}.{$key}";
                }
            }
            $paths = $next;
        }

        return $paths;
    }

    /** @return array{0: bool, 1: mixed} [present, value] for a dot path */
    private static function lookup(array $data, string $path): array
    {
        if ($path === '') {
            return [true, $data];
        }

        if (array_key_exists($path, $data)) {
            return [true, $data[$path]];
        }

        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }

        return [true, $value];
    }

    private static function set(array &$data, string $path, mixed $value): void
    {
        $target = &$data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($target)) {
                $target = [];
            }
            $target = &$target[$segment];
        }
        $target = $value;
    }

    /** A custom message for this failure, if one was given ("field.rule", "pattern.rule", "field", "rule"). */
    private static function message(array $messages, string $pattern, string $field, string $rule, ?string $arg, string $default): string
    {
        if ($messages === []) {
            return $default;
        }

        $message = $messages["{$field}.{$rule}"] ?? $messages["{$pattern}.{$rule}"] ?? $messages[$field] ?? $messages[$pattern] ?? $messages[$rule] ?? null;

        return $message === null ? $default : strtr($message, ['{field}' => $field, '{arg}' => (string) $arg]);
    }

    private static function ruleName(Rule $rule): string
    {
        $short = substr((string) strrchr('\\' . $rule::class, '\\'), 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));   // Unique → unique, NoProfanity → no_profanity
    }

    /** A real date in exactly this format: 2026-02-30 and 2026-1-5 both fail for Y-m-d. */
    private static function isDate(mixed $value, string $format): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $date !== false && $date->format($format) === $value;
    }

    /** before:/after: compare with another field's date, or a date string (2026-01-01, today, +1 week). */
    private static function compareDate(string $field, mixed $value, string $rule, string $arg, array $data): ?string
    {
        [$isField, $other] = self::lookup($data, $arg);
        $limit = self::toDate($isField ? $other : $arg);
        $date = self::toDate($value);

        if ($date === null || $limit === null) {
            return "The {$field} field must be a valid date.";
        }

        $ok = match ($rule) {
            'before' => $date < $limit,
            'after' => $date > $limit,
            'before_or_equal' => $date <= $limit,
            default => $date >= $limit,
        };
        $words = str_replace('_', ' ', $rule);

        return $ok ? null : "The {$field} field must be a date {$words} {$arg}.";
    }

    private static function toDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function between(mixed $value, string $arg): bool
    {
        [$min, $max] = array_pad(explode(',', $arg, 2), 2, null);
        $size = self::size($value);

        return $min !== null && $max !== null && $size >= (float) $min && $size <= (float) $max;
    }

    private static function isJson(string $value): bool
    {
        if (function_exists('json_validate')) {
            return json_validate($value);
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private static function regexError(string $field, string $pattern): string
    {
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("Invalid regex in rule for [{$field}]: {$pattern}");
        }

        return "The {$field} field format is invalid.";
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

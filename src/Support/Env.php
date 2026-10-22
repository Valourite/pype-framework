<?php

declare(strict_types=1);

namespace Pype\Support;

/**
 * Tiny .env loader. KEY=value per line, # comments, optional quotes.
 * Real environment variables win unless $override is true.
 */
final class Env
{
    public static function load(string $path, bool $override = false): void
    {
        if (!is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $key = preg_replace('/^export\s+/', '', $key);

            if (preg_match('/^(["\'])(.*)\1$/s', $value, $m)) {
                $value = $m[1] === '"' ? stripcslashes($m[2]) : $m[2];
            } else {
                $value = trim(explode(' #', $value, 2)[0]);
            }

            if (!$override && getenv($key) !== false) {
                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }

    /** Read a variable, casting "true"/"false"/"null"/"empty" to real values. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        return self::cast((string) $value);
    }

    /**
     * Every variable starting with $prefix, keyed by the rest of its name in lower case, with
     * numbers cast too: SWOOLE_MAX_REQUEST=0 and SWOOLE_ENABLE_COROUTINE=false give
     * ['enable_coroutine' => false, 'max_request' => 0]. Empty values are left out, so a blank
     * line in .env keeps the config's default.
     *
     * @return array<string, mixed>
     */
    public static function prefixed(string $prefix): array
    {
        $out = [];

        foreach ([...getenv(), ...$_ENV] as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, $prefix) || $key === $prefix || !is_scalar($value) || (string) $value === '') {
                continue;
            }

            $value = self::cast((string) $value);
            $out[strtolower(substr($key, strlen($prefix)))] = is_string($value) && is_numeric($value)
                ? (preg_match('/^-?\d+$/', $value) ? (int) $value : (float) $value)
                : $value;
        }

        ksort($out);

        return $out;
    }

    private static function cast(string $value): mixed
    {
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

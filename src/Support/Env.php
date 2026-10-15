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

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

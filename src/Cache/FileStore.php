<?php

declare(strict_types=1);

namespace Pype\Cache;

use Closure;

/**
 * One PHP file per key in a directory of its own (storage/cache). Reads are an `include`, so
 * with opcache on, a hit is served from shared memory without touching the disk.
 *
 * Each file holds `return [expiresAt, value];`. Scalars and arrays are written with
 * var_export; anything holding an object is serialize()d (and unserialized on read with any
 * class allowed: the cache directory is as trusted as the code, never point it at uploads).
 * Writes go to a temp file that is rename()d into place, so a reader never sees half a file.
 * add() and increment() hold an flock so they are atomic between processes.
 */
final class FileStore implements Store
{
    /** @param (Closure(): int)|null $now  the clock, for tests */
    public function __construct(
        private readonly string $directory,
        private readonly ?Closure $now = null,
    ) {
    }

    public function fetch(string $key): array
    {
        $file = $this->file($key);
        $item = is_file($file) ? @include $file : false;

        if (!is_array($item) || ($item[0] !== null && $item[0] <= $this->now())) {
            return [false, null];
        }

        return [true, $item[1]];
    }

    public function put(string $key, mixed $value, ?int $seconds): bool
    {
        return $this->write($key, $value, $seconds === null ? null : $this->now() + $seconds);
    }

    public function add(string $key, mixed $value, ?int $seconds): bool
    {
        return $this->locked($key, fn () => !$this->fetch($key)[0] && $this->put($key, $value, $seconds));
    }

    public function increment(string $key, int $by): int
    {
        return $this->locked($key, function () use ($key, $by) {
            $file = $this->file($key);
            $item = is_file($file) ? @include $file : false;
            $live = is_array($item) && ($item[0] === null || $item[0] > $this->now());
            $value = ($live ? (int) $item[1] : 0) + $by;
            $this->write($key, $value, $live ? $item[0] : null);

            return $value;
        });
    }

    public function forget(string $key): bool
    {
        $file = $this->file($key);
        $this->invalidate($file);

        return !is_file($file) || @unlink($file);
    }

    public function flush(string $prefix): bool
    {
        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            // The first line names the key, so only this prefix's files go.
            if ($prefix !== '' && !str_starts_with($this->keyOf($file) ?? '', $prefix)) {
                continue;
            }

            $this->invalidate($file);
            @unlink($file);
        }

        return true;
    }

    /** Delete expired files (they are otherwise only replaced, never removed); returns how many. */
    public function pruneExpired(): int
    {
        $deleted = 0;

        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            $item = @include $file;

            if (!is_array($item) || ($item[0] !== null && $item[0] <= $this->now())) {
                $this->invalidate($file);
                $deleted += (int) @unlink($file);
            }
        }

        return $deleted;
    }

    private function write(string $key, mixed $value, ?int $expiresAt): bool
    {
        $file = $this->file($key);
        $export = self::exportable($value)
            ? var_export($value, true)
            : '\unserialize(' . var_export(serialize($value), true) . ')';
        $code = "<?php // " . base64_encode($key) . "\nreturn [" . var_export($expiresAt, true) . ", {$export}];\n";

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $code) === false || !@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        $this->invalidate($file);

        return true;
    }

    /** True if var_export writes it back exactly: no objects anywhere inside. */
    private static function exportable(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::exportable($item)) {
                    return false;
                }
            }

            return true;
        }

        return !is_object($value) && !is_resource($value);
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function locked(string $key, Closure $callback): mixed
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        // 16 lock files shared by all keys: no litter, and contention only within a stripe.
        $lock = fopen($this->directory . '/.lock-' . hash('sha256', $key)[0], 'c');

        if ($lock === false) {
            throw new \RuntimeException("Cannot open a lock file in {$this->directory}.");
        }

        try {
            flock($lock, LOCK_EX);

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function keyOf(string $file): ?string
    {
        $handle = @fopen($file, 'r');
        $line = $handle === false ? false : fgets($handle);

        if ($handle !== false) {
            fclose($handle);
        }

        return is_string($line) && str_starts_with($line, '<?php // ')
            ? (base64_decode(trim(substr($line, 9)), true) ?: null)
            : null;
    }

    private function invalidate(string $file): void
    {
        // Opcache would otherwise keep serving the old file until it rechecks the timestamp.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.php';
    }

    private function now(): int
    {
        return $this->now === null ? time() : ($this->now)();
    }
}

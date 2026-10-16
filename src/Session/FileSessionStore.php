<?php

declare(strict_types=1);

namespace Pype\Session;

/**
 * Sessions as files: one per session in $directory, named by a sha256 hash of the id.
 * No database involved, so sessions never compete with your app's writes (SQLite allows
 * one writer at a time). Single server only: use the database or your own store for
 * several servers behind a load balancer.
 */
final class FileSessionStore implements SessionStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create session directory {$directory}.");
        }
    }

    public function read(string $id): ?array
    {
        $file = $this->file($id);

        // The file's modification time is its expiry (set by write()).
        if (@filemtime($file) <= time()) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        // Write to a temp file and rename over the old one, so a concurrent read never sees half
        // a file. The mtime is set to the expiry, so gc() only needs to stat, never open, files.
        $file = $this->file($id);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR));
        touch($tmp, time() + $lifetimeSeconds);
        rename($tmp, $file);
    }

    public function destroy(string $id): void
    {
        @unlink($this->file($id));
    }

    /**
     * Delete expired sessions. Runs at most once a minute however often it's called (a sweep
     * stats every file, which adds up with many sessions and many workers).
     */
    public function gc(): int
    {
        $marker = $this->directory . '/.gc';

        if (@filemtime($marker) > time() - 60) {
            return 0;
        }

        touch($marker);
        clearstatcache();
        $removed = 0;

        foreach (glob($this->directory . '/*.sess', GLOB_NOSORT) ?: [] as $file) {
            if (@filemtime($file) <= time() && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function file(string $id): string
    {
        return $this->directory . '/' . hash('sha256', $id) . '.sess';
    }
}

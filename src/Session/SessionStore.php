<?php

declare(strict_types=1);

namespace Pype\Session;

/**
 * Where session data lives. Built in: DatabaseSessionStore, ArraySessionStore.
 * Implement this for Redis, files, etc. and bind it in a provider.
 */
interface SessionStore
{
    /** @return array<string, mixed>|null  null when missing or expired */
    public function read(string $id): ?array;

    /** @param array<string, mixed> $data */
    public function write(string $id, array $data, int $lifetimeSeconds): void;

    public function destroy(string $id): void;

    /** Delete expired sessions. Returns how many were removed. */
    public function gc(): int;
}

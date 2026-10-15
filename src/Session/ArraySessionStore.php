<?php

declare(strict_types=1);

namespace Pype\Session;

/** In-memory store: lives as long as the process. For tests, or apps without a database. */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string, array{data: array<string, mixed>, expires: int}> */
    private array $sessions = [];

    public function read(string $id): ?array
    {
        $session = $this->sessions[$id] ?? null;

        return $session !== null && $session['expires'] > time() ? $session['data'] : null;
    }

    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $this->sessions[$id] = ['data' => $data, 'expires' => time() + $lifetimeSeconds];
    }

    public function destroy(string $id): void
    {
        unset($this->sessions[$id]);
    }

    public function gc(): int
    {
        $before = count($this->sessions);
        $this->sessions = array_filter($this->sessions, static fn (array $s) => $s['expires'] > time());

        return $before - count($this->sessions);
    }
}

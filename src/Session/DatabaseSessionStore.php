<?php

declare(strict_types=1);

namespace Pype\Session;

use Pype\Database\Connection;

/**
 * Sessions in a table: sessions(id TEXT PRIMARY KEY, payload TEXT, expires_at INTEGER).
 * Only a sha256 hash of the id is stored, so a leaked table can't be used to hijack sessions.
 */
final class DatabaseSessionStore implements SessionStore
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $table = 'sessions',
    ) {
    }

    public function read(string $id): ?array
    {
        $payload = $this->db->table($this->table)
            ->where('id', hash('sha256', $id))
            ->where('expires_at', '>', time())
            ->value('payload');

        $data = is_string($payload) ? json_decode($payload, true) : null;

        return is_array($data) ? $data : null;
    }

    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $table = $this->db->quote($this->table);
        $bindings = [hash('sha256', $id), json_encode($data, JSON_THROW_ON_ERROR), time() + $lifetimeSeconds];

        $this->db->execute(match ($this->db->driver()) {
            'mysql' => "INSERT INTO {$table} (id, payload, expires_at) VALUES (?, ?, ?)"
                . ' ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)',
            default => "INSERT INTO {$table} (id, payload, expires_at) VALUES (?, ?, ?)"
                . ' ON CONFLICT (id) DO UPDATE SET payload = excluded.payload, expires_at = excluded.expires_at',
        }, $bindings);
    }

    public function destroy(string $id): void
    {
        $this->db->table($this->table)->where('id', hash('sha256', $id))->delete();
    }

    public function gc(): int
    {
        return $this->db->table($this->table)->where('expires_at', '<=', time())->delete();
    }
}

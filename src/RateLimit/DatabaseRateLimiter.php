<?php

declare(strict_types=1);

namespace Pype\RateLimit;

use Pype\Database\Connection;

/**
 * Counters in a table: rate_limits(key PRIMARY KEY, hits, reset_at). Each hit is a single
 * atomic upsert, so concurrent requests (and several app servers) can't lose counts.
 */
final class DatabaseRateLimiter implements RateLimiter
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $table = 'rate_limits',
    ) {
    }

    public function hit(string $key, int $decaySeconds): array
    {
        $now = time();
        $table = $this->db->quote($this->table);
        $k = $this->db->quote('key');

        if ($this->db->driver() === 'mysql') {
            // No RETURNING in MySQL: upsert, then read the row back while it's still locked.
            // hits is assigned before reset_at, so both IF()s see the old reset_at.
            return $this->db->transaction(function (Connection $db) use ($table, $k, $key, $now, $decaySeconds) {
                $db->execute(
                    "INSERT INTO {$table} ({$k}, hits, reset_at) VALUES (?, 1, ?)"
                    . ' ON DUPLICATE KEY UPDATE hits = IF(reset_at <= ?, 1, hits + 1), reset_at = IF(reset_at <= ?, VALUES(reset_at), reset_at)',
                    [$key, $now + $decaySeconds, $now, $now],
                );
                $row = $db->first("SELECT hits, reset_at FROM {$table} WHERE {$k} = ?", [$key]);

                return [(int) $row['hits'], (int) $row['reset_at']];
            });
        }

        $row = $this->db->first(
            "INSERT INTO {$table} AS t ({$k}, hits, reset_at) VALUES (?, 1, ?)"
            . " ON CONFLICT ({$k}) DO UPDATE SET"
            . ' hits = CASE WHEN t.reset_at <= ? THEN 1 ELSE t.hits + 1 END,'
            . ' reset_at = CASE WHEN t.reset_at <= ? THEN excluded.reset_at ELSE t.reset_at END'
            . ' RETURNING hits, reset_at',
            [$key, $now + $decaySeconds, $now, $now],
        );

        return [(int) $row['hits'], (int) $row['reset_at']];
    }
}

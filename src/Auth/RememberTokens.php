<?php

declare(strict_types=1);

namespace Pype\Auth;

use Pype\Database\Connection;

/**
 * "Remember me" tokens, one row per device in config auth.remember_table (selector + validator):
 *
 *   - the cookie holds "selector:validator"; the table holds the selector and only a sha256 of
 *     the validator, so a leaked table can't sign anyone in;
 *   - every use swaps in a new validator, so a copied cookie works at most once. The previous
 *     validator stays valid for GRACE seconds, for a browser sending several requests at once;
 *   - a known selector with a wrong (older) validator means the cookie was copied and replayed:
 *     every remembered device of that user is signed out;
 *   - tokens expire after auth.remember_days.
 *
 * Used by SessionGuard (login(..., remember: true), logout()); revoked on password reset.
 */
final class RememberTokens
{
    private const GRACE = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly string $table = 'remember_tokens',
        private readonly int $days = 30,
    ) {
    }

    /** A new token for this device. Returns the cookie value. */
    public function create(int $userId, ?string $userAgent = null): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $now = time();

        $this->db->table($this->table)->insert([
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => $now + $this->days * 86400,
            'last_used_at' => $now,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);

        return "{$selector}:{$validator}";
    }

    /**
     * Check a cookie. Returns the user id and, when the validator was swapped, the new cookie
     * value to send (null: keep the one the browser has). Null overall: not valid; and if it
     * looked like a replayed copy, all of that user's tokens are gone.
     *
     * @return array{user: int, cookie: ?string}|null
     */
    public function consume(string $cookie): ?array
    {
        if (!preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $cookie, $m)) {
            return null;
        }

        [, $selector, $validator] = $m;
        $now = time();
        $row = $this->db->table($this->table)->where('selector', $selector)->first();

        if ($row === null || (int) $row['expires_at'] <= $now) {
            return null;
        }

        $hash = hash('sha256', $validator);

        if (hash_equals((string) $row['validator_hash'], $hash)) {
            $next = bin2hex(random_bytes(32));
            $this->db->table($this->table)->where('id', (int) $row['id'])->update([
                'validator_hash' => hash('sha256', $next),
                'previous_hash' => $hash,
                'rotated_at' => $now,
                'last_used_at' => $now,
            ]);

            return ['user' => (int) $row['user_id'], 'cookie' => "{$selector}:{$next}"];
        }

        // Several requests raced with the same cookie: the first swapped it, the rest are fine.
        if ($row['previous_hash'] !== null && hash_equals((string) $row['previous_hash'], $hash) && (int) $row['rotated_at'] > $now - self::GRACE) {
            return ['user' => (int) $row['user_id'], 'cookie' => null];
        }

        // A right selector with an old validator: someone replayed a copied cookie.
        $this->revokeAll((int) $row['user_id']);

        return null;
    }

    /** Forget the token behind this cookie (logout on this device). */
    public function revoke(string $cookie): void
    {
        if (preg_match('/^([0-9a-f]{24}):/', $cookie, $m)) {
            $this->db->table($this->table)->where('selector', $m[1])->delete();
        }
    }

    /** Sign the user out of every remembered device. */
    public function revokeAll(int $userId): int
    {
        return $this->db->table($this->table)->where('user_id', $userId)->delete();
    }

    /** @return list<array{id: int, user_agent: ?string, last_used_at: int, expires_at: int}> the user's remembered devices */
    public function devices(int $userId): array
    {
        return $this->db->table($this->table)
            ->select('id', 'user_agent', 'last_used_at', 'expires_at')
            ->where('user_id', $userId)
            ->where('expires_at', '>', time())
            ->orderBy('last_used_at', 'desc')
            ->get();
    }

    public function pruneExpired(): int
    {
        return $this->db->table($this->table)->where('expires_at', '<=', time())->delete();
    }
}

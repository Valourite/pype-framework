<?php

declare(strict_types=1);

namespace Pype\Auth;

use Pype\Database\Connection;

/**
 * Password reset tokens, done safely so apps don't have to get the details right:
 *
 *   - only a sha256 hash of the token is stored (a leaked table can't reset anyone);
 *   - a token works once, and only for config auth.reset_ttl minutes;
 *   - one token per user: creating a new one replaces the old; at most one per
 *     auth.reset_throttle seconds, so the form can't be used to flood someone's inbox;
 *   - a reset revokes the user's API tokens and "remember me" devices.
 *
 * Table password_resets(user_id PRIMARY KEY, token_hash UNIQUE, created_at). The flow (forms,
 * the email, wording) is app code; see the skeleton's ForgotPassword / ResetPassword handlers.
 *
 *   $token = $resets->create($user);              // null: one was sent moments ago
 *   $mailer->later(new ResetPasswordMail($user, $token));
 *   ...
 *   $user = $resets->reset($token, $newPassword); // null: wrong, used or expired
 */
final class PasswordResets
{
    public function __construct(
        private readonly Connection $db,
        private readonly Auth $auth,
    ) {
    }

    /** A new reset token for the user (plain, for the link), or null if one was made too recently. */
    public function create(object $user): ?string
    {
        $config = $this->auth->config();
        $last = $this->db->table($config['resets_table'])->where('user_id', (int) $user->id)->value('created_at');

        if ($last !== null && (int) $last > time() - (int) $config['reset_throttle']) {
            return null;
        }

        $token = bin2hex(random_bytes(32));

        $this->db->transaction(function (Connection $db) use ($config, $user, $token) {
            $db->table($config['resets_table'])->where('user_id', (int) $user->id)->delete();
            $db->table($config['resets_table'])->insert([
                'user_id' => (int) $user->id,
                'token_hash' => hash('sha256', $token),
                'created_at' => time(),
            ]);
        });

        return $token;
    }

    /** Whether a token is valid right now (e.g. before showing the "new password" form). */
    public function valid(string $token): bool
    {
        return $this->row($token) !== null;
    }

    /**
     * Set a new password with a token. Returns the user, or null for a wrong, used or expired
     * token (deliberately without saying which).
     */
    public function reset(string $token, string $password): ?object
    {
        $row = $this->row($token);
        $user = $row === null ? null : $this->auth->findUser((int) $row['user_id']);

        if ($user === null) {
            return null;
        }

        $config = $this->auth->config();

        return $this->db->transaction(function (Connection $db) use ($config, $token, $user, $password) {
            // Deleting the token is the check: of two requests racing with the same link, only
            // the one that actually removes it may change the password.
            $consumed = $db->table($config['resets_table'])->where('token_hash', hash('sha256', $token))->delete();

            if ($consumed !== 1) {
                return null;
            }

            $this->auth->updatePassword($user, $password);
            $this->auth->revokeAll($user);                                  // API tokens
            $this->auth->rememberTokens()?->revokeAll((int) $user->id);     // remembered devices

            return $user;
        });
    }

    /** Drop any pending token for the user. */
    public function forget(object|int $user): void
    {
        $this->db->table($this->auth->config()['resets_table'])->where('user_id', is_int($user) ? $user : (int) $user->id)->delete();
    }

    /** Remove expired tokens (run from a cron/CLI). */
    public function pruneExpired(): int
    {
        $config = $this->auth->config();

        return $this->db->table($config['resets_table'])->where('created_at', '<=', time() - (int) $config['reset_ttl'] * 60)->delete();
    }

    /** @return array<string, mixed>|null the unexpired row for this token */
    private function row(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $config = $this->auth->config();

        // Looked up by the token's sha256: the database compares hashes of a 256-bit random
        // value, so timing reveals nothing usable.
        return $this->db->table($config['resets_table'])
            ->where('token_hash', hash('sha256', $token))
            ->where('created_at', '>', time() - (int) $config['reset_ttl'] * 60)
            ->first();
    }
}

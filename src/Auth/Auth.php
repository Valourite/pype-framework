<?php

declare(strict_types=1);

namespace Pype\Auth;

use InvalidArgumentException;
use Pype\Database\Connection;

/**
 * Password + API token authentication over two tables (names configurable in config/auth.php):
 *
 *   users       (id, <identifier e.g. email>, password, ...your columns)
 *   api_tokens  (id, user_id, name, token_hash, expires_at, created_at)
 *
 * Users are returned as instances of the configured user class, hydrated from the row
 * (the password column is never passed to it).
 */
final class Auth
{
    private const TOKEN_PREFIX = 'pype_';

    // Random-password bcrypt hashes at PHP's default cost (10 before 8.4, 12 from 8.4), used to keep
    // failed logins for unknown users as slow as for known ones.
    private const DUMMY_HASH_COST_10 = '$2y$10$wYp4hT67v1phntjjECP77ubOxgIIrDOsD3Zjz6mbi8yyFXdmRb1gW';
    private const DUMMY_HASH_COST_12 = '$2y$12$KnSa9GFAjdNzsAKqknW5/OBJlST49PIrz7AMC4ZExxfLMEPc3vFPK';

    /** @var array{table: string, user: class-string, identifier: string, password: string, tokens_table: string, token_ttl: ?int, guards: list<class-string<Guard>|Guard>} */
    private array $config;

    public function __construct(private readonly Connection $db, array $config = [])
    {
        $this->config = [
            'table' => 'users',
            'user' => \stdClass::class,
            'identifier' => 'email',
            'password' => 'password',
            'tokens_table' => 'api_tokens',
            'token_ttl' => null,
            'guards' => [SessionGuard::class, TokenGuard::class],
            ...$config,
        ];
    }

    /** @return list<class-string<Guard>|Guard>  guards Authenticate uses by default */
    public function guards(): array
    {
        return $this->config['guards'];
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Insert a user, hashing the password. Returns the hydrated user.
     *
     * @param array<string, mixed> $attributes  must include the identifier and 'password'
     */
    public function createUser(array $attributes): object
    {
        $password = $attributes['password'] ?? throw new InvalidArgumentException('A password is required.');
        unset($attributes['password']);
        $attributes[$this->config['password']] = $this->hash((string) $password);

        $id = $this->db->table($this->config['table'])->insert($attributes);

        return $this->findUser((int) $id);
    }

    public function findUser(int $id): ?object
    {
        return $this->users()->where('id', $id)->first();
    }

    /** Check credentials. Returns the user, or null. Timing is equal whether or not the user exists. */
    public function attempt(string $identifier, string $password): ?object
    {
        $row = $this->db->table($this->config['table'])
            ->select('id', $this->config['password'])
            ->where($this->config['identifier'], $identifier)
            ->first();

        // Verify against a dummy hash when the user doesn't exist, so response time doesn't reveal it.
        $hash = $row[$this->config['password']]
            ?? (PHP_VERSION_ID >= 80400 ? self::DUMMY_HASH_COST_12 : self::DUMMY_HASH_COST_10);

        if (!password_verify($password, $hash) || $row === null) {
            return null;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->db->table($this->config['table'])->where('id', $row['id'])->update([
                $this->config['password'] => $this->hash($password),
            ]);
        }

        return $this->findUser((int) $row['id']);
    }

    public function issueToken(object|int $user, string $name = 'api', ?int $ttlMinutes = null): NewAccessToken
    {
        $userId = is_int($user) ? $user : (int) $user->id;
        $ttl = $ttlMinutes ?? $this->config['token_ttl'];
        $plain = self::TOKEN_PREFIX . bin2hex(random_bytes(32));

        $id = $this->db->table($this->config['tokens_table'])->insert([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $ttl === null ? null : gmdate('Y-m-d H:i:s', time() + $ttl * 60),
        ]);

        $token = $this->db->table($this->config['tokens_table'])->where('id', (int) $id)->as(AccessToken::class)->first();

        return new NewAccessToken($plain, $token);
    }

    /**
     * Resolve a plain bearer token to its user and token record.
     *
     * @return array{0: object, 1: AccessToken}|null
     */
    public function authenticateToken(string $plain): ?array
    {
        if (!str_starts_with($plain, self::TOKEN_PREFIX)) {
            return null;
        }

        /** @var AccessToken|null $token */
        $token = $this->db->table($this->config['tokens_table'])
            ->where('token_hash', hash('sha256', $plain))
            ->as(AccessToken::class)
            ->first();

        if ($token === null || ($token->expires_at !== null && $token->expires_at <= gmdate('Y-m-d H:i:s'))) {
            return null;
        }

        $user = $this->findUser($token->user_id);

        return $user === null ? null : [$user, $token];
    }

    public function revoke(AccessToken $token): void
    {
        $this->db->table($this->config['tokens_table'])->where('id', $token->id)->delete();
    }

    /** Log a user out everywhere. */
    public function revokeAll(object|int $user): int
    {
        $userId = is_int($user) ? $user : (int) $user->id;

        return $this->db->table($this->config['tokens_table'])->where('user_id', $userId)->delete();
    }

    /** Remove expired tokens (run from a cron/CLI). */
    public function pruneExpired(): int
    {
        return $this->db->table($this->config['tokens_table'])
            ->where('expires_at', '<=', gmdate('Y-m-d H:i:s'))
            ->delete();
    }

    private function users(): \Pype\Database\QueryBuilder
    {
        return $this->db->table($this->config['table'])->as($this->config['user']);
    }
}

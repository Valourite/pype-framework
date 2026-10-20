<?php

declare(strict_types=1);

namespace Pype\Auth;

/**
 * JSON Web Tokens, signed HS256 (a shared secret) or RS256 (an RSA key pair), with PHP's own
 * hash_hmac / openssl. Configured in config/auth.php under 'jwt'.
 *
 *   $token = $jwt->issue(['sub' => (string) $user->id]);   // adds iat and exp (ttl seconds)
 *   $claims = $jwt->verify($token);                         // or throws InvalidToken
 *
 * Which algorithms are accepted follows from the configured keys, never from the token: a
 * secret allows HS256, a public key allows RS256. So `alg: none`, and an RS256 public key used
 * as an HMAC secret (algorithm confusion), are rejected.
 */
final class Jwt
{
    private readonly string $secret;

    /** @var array<string, string> kid (or '' for a single key) => PEM public key */
    private readonly array $publicKeys;

    /**
     * @param array{secret?: ?string, public_key?: string|array<string, string>|null, private_key?: ?string,
     *              kid?: ?string, ttl?: int, issuer?: ?string, audience?: ?string, leeway?: int} $config
     * @param (\Closure(): int)|null $now  the clock, for tests
     */
    public function __construct(private readonly array $config, private readonly ?\Closure $now = null)
    {
        $this->secret = (string) ($config['secret'] ?? '');
        $public = $config['public_key'] ?? null;
        $this->publicKeys = is_array($public) ? $public : ($public === null || $public === '' ? [] : ['' => $public]);
    }

    /** A setting from config/auth.php 'jwt' (e.g. 'user', 'only'). */
    public function setting(string $name, mixed $default = null): mixed
    {
        return $this->config[$name] ?? $default;
    }

    /** A signed token with these claims plus iat and exp (unless given). RS256 if a private key is configured. */
    public function issue(array $claims, ?int $ttl = null): string
    {
        $now = $this->now();
        $claims += ['iat' => $now, 'exp' => $now + ($ttl ?? (int) ($this->config['ttl'] ?? 3600))];

        if (($this->config['issuer'] ?? null) !== null) {
            $claims += ['iss' => $this->config['issuer']];
        }

        $private = $this->config['private_key'] ?? null;
        $header = ['alg' => $private ? 'RS256' : 'HS256', 'typ' => 'JWT'];

        if ($private && ($this->config['kid'] ?? null) !== null) {
            $header['kid'] = $this->config['kid'];
        }

        $input = self::encode(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . self::encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if ($private) {
            $key = openssl_pkey_get_private($private) ?: throw new \RuntimeException('auth.jwt.private_key is not a valid private key.');
            openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256) ?: throw new \RuntimeException('Signing the JWT failed.');
        } else {
            $signature = hash_hmac('sha256', $input, $this->secret !== '' ? $this->secret : throw new \RuntimeException('auth.jwt.secret is not set.'), true);
        }

        return $input . '.' . self::encode($signature);
    }

    /**
     * The token's claims, if its signature, algorithm and times check out.
     *
     * @return array<string, mixed>
     * @throws InvalidToken
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidToken('Not a JWT.');
        }

        [$head, $body, $sig] = $parts;
        $header = self::json($head);
        $claims = self::json($body);

        if ($header === null || $claims === null) {
            throw new InvalidToken('Malformed JWT.');
        }

        $input = "{$head}.{$body}";
        $signature = self::decode($sig) ?? '';   // empty or not base64url: fails the check below
        $valid = match ($header['alg'] ?? null) {
            'HS256' => $this->secret !== '' && hash_equals(hash_hmac('sha256', $input, $this->secret, true), $signature),
            'RS256' => $this->verifyRsa($input, $signature, $header),
            default => throw new InvalidToken('Algorithm not accepted.'),
        };

        if (!$valid) {
            throw new InvalidToken('Bad signature.');
        }

        $this->checkClaims($claims);

        return $claims;
    }

    private function verifyRsa(string $input, string $signature, array $header): bool
    {
        if ($this->publicKeys === []) {
            throw new InvalidToken('Algorithm not accepted.');   // no public key: RS256 is off
        }

        $kid = $header['kid'] ?? null;
        $pem = is_string($kid) && isset($this->publicKeys[$kid]) ? $this->publicKeys[$kid]
            : (count($this->publicKeys) === 1 ? array_values($this->publicKeys)[0] : throw new InvalidToken('Unknown key id.'));
        $key = openssl_pkey_get_public($pem) ?: throw new \RuntimeException('auth.jwt.public_key is not a valid public key.');

        return openssl_verify($input, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function checkClaims(array $claims): void
    {
        $now = $this->now();
        $leeway = (int) ($this->config['leeway'] ?? 30);

        if (!isset($claims['exp']) || !is_numeric($claims['exp'])) {
            throw new InvalidToken('No expiry.');
        }

        if ($now - $leeway >= (int) $claims['exp']) {
            throw new InvalidToken('Expired.');
        }

        if (isset($claims['nbf']) && (!is_numeric($claims['nbf']) || $now + $leeway < (int) $claims['nbf'])) {
            throw new InvalidToken('Not valid yet.');
        }

        if (($issuer = $this->config['issuer'] ?? null) !== null && ($claims['iss'] ?? null) !== $issuer) {
            throw new InvalidToken('Wrong issuer.');
        }

        if (($audience = $this->config['audience'] ?? null) !== null && !in_array($audience, (array) ($claims['aud'] ?? []), true)) {
            throw new InvalidToken('Wrong audience.');
        }
    }

    /** @return array<string, mixed>|null */
    private static function json(string $part): ?array
    {
        $raw = self::decode($part);
        $data = $raw === null ? null : json_decode($raw, true);

        return is_array($data) && !array_is_list($data) ? $data : null;
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $part): ?string
    {
        if ($part === '' || strspn($part, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_') !== strlen($part)) {
            return null;
        }

        $raw = base64_decode(strtr($part, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }

    private function now(): int
    {
        return $this->now === null ? time() : ($this->now)();
    }
}

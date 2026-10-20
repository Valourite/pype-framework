<?php

declare(strict_types=1);

namespace Pype\Auth;

use Closure;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;

/**
 * Bearer JWTs: `Authorization: Bearer <header.payload.signature>`, verified by Jwt on every
 * request (no database lookup for the token itself). List it in config/auth.php 'guards'.
 *
 * Use it for tokens from an identity provider (Auth0, Cognito, Keycloak: RS256), for services
 * that check each other's tokens, or stateless APIs. A JWT can't be revoked before it expires,
 * so keep lifetimes short; for your own apps' clients, API tokens (TokenGuard) are revocable.
 *
 * - A bearer token that isn't JWT-shaped (API tokens have no dots) is left to the next guard,
 *   unless auth.jwt.only is true: then it's rejected like any invalid token.
 * - A JWT that fails verification is a 401 (error key 'invalid_token').
 * - The user: auth.jwt.user 'database' (default) loads it by `sub` through Auth; 'claims' builds a
 *   JwtUser from the token alone. Override user() for anything else. No user = 401
 *   ('invalid_token_payload'). The claims also go into the context as JwtClaims.
 */
class JwtGuard implements Guard
{
    /** @param (Closure(): Auth)|null $auth  for 'database' users; resolved only when needed */
    public function __construct(
        protected readonly Jwt $jwt,
        private readonly ?Closure $auth = null,
    ) {
    }

    public function authenticate(Request $request): ?object
    {
        if (!preg_match('/^Bearer\s+(\S+)$/i', (string) $request->header('authorization'), $m)) {
            return null;
        }

        if (substr_count($m[1], '.') !== 2 && !$this->jwt->setting('only', false)) {
            return null;
        }

        try {
            $claims = $this->jwt->verify($m[1]);
        } catch (InvalidToken) {
            throw HttpException::unauthorized('Invalid or expired token')->withKey('invalid_token');
        }

        $request->context()->put(new JwtClaims($claims));

        return $this->user($claims) ?? throw HttpException::unauthorized('Invalid token payload')->withKey('invalid_token_payload');
    }

    /** The user these (verified) claims name, or null if they don't name a valid one. */
    protected function user(array $claims): ?object
    {
        $sub = $claims['sub'] ?? null;

        if ($this->jwt->setting('user', 'database') === 'claims') {
            return is_string($sub) || is_int($sub) ? new JwtUser((string) $sub, $claims) : null;
        }

        if (!(is_int($sub) && $sub > 0) && !(is_string($sub) && preg_match('/^[1-9]\d*$/', $sub))) {
            return null;
        }

        $auth = $this->auth ?? throw new \LogicException('JwtGuard needs Auth to load users: register AuthProvider, or set auth.jwt.user to "claims".');

        return $auth()->findUser((int) $sub);
    }
}

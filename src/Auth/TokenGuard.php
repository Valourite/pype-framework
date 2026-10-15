<?php

declare(strict_types=1);

namespace Pype\Auth;

use Pype\Http\Request;

/** Authorization: Bearer <token>. Also puts the AccessToken in the context (for logout, scopes, ...). */
final class TokenGuard implements Guard
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function authenticate(Request $request): ?object
    {
        if (!preg_match('/^Bearer\s+(\S+)$/i', (string) $request->header('authorization'), $m)) {
            return null;
        }

        [$user, $token] = $this->auth->authenticateToken($m[1]) ?? [null, null];

        if ($token !== null) {
            $request->context()->put($token);
        }

        return $user;
    }
}

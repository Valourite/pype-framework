<?php

declare(strict_types=1);

namespace Pype\Auth;

/** The user a JWT names, built from its claims alone (auth.jwt.user = 'claims'). */
final class JwtUser
{
    /** @param array<string, mixed> $claims */
    public function __construct(public readonly string $id, public readonly array $claims)
    {
    }
}

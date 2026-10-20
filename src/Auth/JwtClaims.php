<?php

declare(strict_types=1);

namespace Pype\Auth;

/** A verified token's claims, in the request context: type-hint JwtClaims to read roles, scopes... */
final class JwtClaims
{
    /** @param array<string, mixed> $claims */
    public function __construct(public readonly array $claims)
    {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->claims[$name] ?? $default;
    }
}

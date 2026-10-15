<?php

declare(strict_types=1);

namespace Pype\Auth;

/**
 * A stored API token (never the plain secret). Put in the context by Authenticate,
 * so handlers can inject it — e.g. to revoke the current token on logout.
 */
final class AccessToken
{
    public function __construct(
        public readonly int $id,
        public readonly int $user_id,
        public readonly string $name,
        public readonly ?string $expires_at = null,
        public readonly ?string $created_at = null,
    ) {
    }
}

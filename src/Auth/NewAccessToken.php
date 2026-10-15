<?php

declare(strict_types=1);

namespace Pype\Auth;

use JsonSerializable;

/**
 * Returned once, when a token is issued. $plainText is the only copy of the secret —
 * the database stores a SHA-256 hash of it.
 */
final class NewAccessToken implements JsonSerializable
{
    public function __construct(
        public readonly string $plainText,
        public readonly AccessToken $accessToken,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'token' => $this->plainText,
            'type' => 'Bearer',
            'expires_at' => $this->accessToken->expires_at,
        ];
    }
}

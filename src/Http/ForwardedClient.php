<?php

declare(strict_types=1);

namespace Pype\Http;

/**
 * The client as reported by a trusted proxy (X-Forwarded-For / -Proto / -Host), put in the
 * context by Stages\TrustProxies. Request::ip(), scheme(), host() and isSecure() read it, so
 * every copy of the request (and stages outside TrustProxies, like LogRequests) sees the same.
 * A null scheme or host means the proxy didn't send one: the request's own is used.
 */
final readonly class ForwardedClient
{
    public function __construct(
        public string $ip,
        public ?string $scheme = null,
        public ?string $host = null,
    ) {
    }
}

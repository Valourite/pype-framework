<?php

declare(strict_types=1);

namespace Pype\Http;

use InvalidArgumentException;

/**
 * An outgoing cookie. Secure defaults: HttpOnly, SameSite=Lax, path "/".
 *
 *   $response->withCookie(new Cookie('theme', 'dark', maxAge: 86400 * 365));
 *   $response->withCookie(Cookie::forget('theme'));
 */
final readonly class Cookie
{
    /**
     * @param int|null $maxAge  seconds until expiry; null = until the browser closes, 0 or less = delete now
     */
    public function __construct(
        public string $name,
        public string $value,
        public ?int $maxAge = null,
        public string $path = '/',
        public ?string $domain = null,
        public bool $secure = false,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
    ) {
        if ($name === '' || preg_match('/[=,; \t\r\n\013\014]/', $name)) {
            throw new InvalidArgumentException("Invalid cookie name [{$name}].");
        }

        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException('SameSite must be Lax, Strict or None.');
        }
    }

    public static function forget(string $name, string $path = '/', ?string $domain = null): self
    {
        return new self($name, '', maxAge: 0, path: $path, domain: $domain);
    }

    /** The Set-Cookie header value. */
    public function toHeader(): string
    {
        $parts = [$this->name . '=' . rawurlencode($this->value), 'Path=' . $this->path];

        if ($this->maxAge !== null) {
            $parts[] = 'Max-Age=' . max(0, $this->maxAge);
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $this->maxAge > 0 ? time() + $this->maxAge : 0);
        }

        if ($this->domain !== null) {
            $parts[] = 'Domain=' . $this->domain;
        }

        // Browsers reject SameSite=None without Secure.
        if ($this->secure || $this->sameSite === 'None') {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $parts);
    }
}

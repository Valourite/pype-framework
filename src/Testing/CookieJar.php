<?php

declare(strict_types=1);

namespace Pype\Testing;

use Pype\Http\Cookie;

/** Cookies a TestClient keeps between requests, like a browser does. */
final class CookieJar
{
    /** @var array<string, string> */
    private array $cookies = [];

    /** @param list<Cookie> $cookies */
    public function store(array $cookies): void
    {
        foreach ($cookies as $cookie) {
            if ($cookie->maxAge !== null && $cookie->maxAge <= 0) {
                unset($this->cookies[$cookie->name]);
            } else {
                $this->cookies[$cookie->name] = $cookie->value;
            }
        }
    }

    public function set(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    public function get(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->cookies;
    }

    /** Value for a Cookie request header, or null when empty. */
    public function header(): ?string
    {
        if ($this->cookies === []) {
            return null;
        }

        return implode('; ', array_map(
            static fn (string $name, string $value) => $name . '=' . rawurlencode($value),
            array_keys($this->cookies),
            $this->cookies,
        ));
    }
}

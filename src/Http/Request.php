<?php

declare(strict_types=1);

namespace Pype\Http;

/**
 * Incoming HTTP request.
 *
 * Besides the usual HTTP data, a request carries a typed Context. That is how
 * stages talk to each other: an earlier stage puts something in (e.g. the
 * authenticated user), a later stage gets it out by class name.
 */
final class Request
{
    private readonly Context $context;

    /** @var array<string, string> */
    private array $params = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers  lower-cased header names
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly string $rawBody = '',
        private readonly string $ip = '127.0.0.1',
    ) {
        $this->context = new Context();
    }

    /**
     * Build a request by hand (tests, CLI, sub-requests).
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public static function create(string $method, string $uri, array $body = [], array $headers = []): self
    {
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);

        $headers = array_change_key_case($headers, CASE_LOWER);
        $raw = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);

        if ($raw !== '') {
            $headers['content-type'] ??= 'application/json';
        }

        return new self(strtoupper($method), $parts['path'] ?? '/', $query, $body, $headers, $raw);
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        $raw = (string) file_get_contents('php://input');
        $body = $_POST;

        if ($raw !== '' && preg_match('#^application/(.+\+)?json#i', $headers['content-type'] ?? '')) {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            query: $_GET,
            body: $body,
            headers: $headers,
            rawBody: $raw,
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return '/' . trim($this->path, '/');
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->body : ($this->body[$key] ?? $default);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Client IP as seen by PHP. Behind a proxy/load balancer this is the proxy's address;
     * resolve X-Forwarded-For in a stage that knows which proxies you trust.
     */
    public function ip(): string
    {
        return $this->ip;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** Route parameter, e.g. {id} in /users/{id}. */
    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }

    /** @return array<string, string> */
    public function params(): array
    {
        return $this->params;
    }

    /** @param array<string, string> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /** Typed store for passing data between stages. */
    public function context(): Context
    {
        return $this->context;
    }
}

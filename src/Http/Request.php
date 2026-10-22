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
     * @param array<string, string> $cookies
     * @param array<string, UploadedFile|list<UploadedFile>> $files
     */
    public function __construct(
        private string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly string $rawBody = '',
        private readonly string $ip = '127.0.0.1',
        private readonly array $cookies = [],
        private readonly array $files = [],
        ?Context $context = null,
    ) {
        $this->context = $context ?? new Context();
    }

    /**
     * Build a request by hand (tests, CLI, sub-requests).
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, UploadedFile|list<UploadedFile|null>|null> $files  e.g. ['receipt' => UploadedFile::fake($path)];
     *        null is a file input left empty
     */
    public static function create(string $method, string $uri, array $body = [], array $headers = [], array $files = [], string $ip = '127.0.0.1'): self
    {
        // A null file (or row) is an input left empty: dropped, as fromGlobals() drops UPLOAD_ERR_NO_FILE.
        // The form is still multipart, as a browser sends it.
        $multipart = $files !== [];
        foreach ($files as $field => $file) {
            if (is_array($file)) {
                $file = array_values(array_filter($file, static fn ($f) => $f !== null));
            }
            if ($file === null || $file === []) {
                unset($files[$field]);
            } else {
                $files[$field] = $file;
            }
        }

        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);

        $headers = array_change_key_case($headers, CASE_LOWER);
        $raw = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);

        if ($multipart) {
            // Like a browser: multipart form, no JSON body.
            $raw = '';
            $headers['content-type'] ??= 'multipart/form-data; boundary=pype';
        } elseif ($raw !== '') {
            $headers['content-type'] ??= 'application/json';
        }

        return new self(strtoupper($method), $parts['path'] ?? '/', $query, $body, $headers, $raw, $ip, self::parseCookies($headers['cookie'] ?? ''), $files);
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
        $body = self::bodyFrom($raw, $headers['content-type'] ?? '', $_POST);

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            query: $_GET,
            body: $body,
            headers: $headers,
            rawBody: $raw,
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            cookies: array_filter($_COOKIE, 'is_string'),
            files: self::normaliseFiles($_FILES),
        );
    }

    /**
     * The parsed body: decoded JSON for JSON requests, otherwise the form fields.
     *
     * @param array<string, mixed> $form
     * @return array<mixed>
     */
    public static function bodyFrom(string $raw, string $contentType, array $form): array
    {
        if ($raw !== '' && preg_match('#^application/(.+\+)?json#i', $contentType)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $form;
    }

    /**
     * $_FILES-shaped array → UploadedFile objects. "receipts[]" fields become lists; empty file
     * inputs (the browser sends one with UPLOAD_ERR_NO_FILE) are left out. $trusted is for
     * servers that write their own temp files (OpenSwoole), which is_uploaded_file() rejects.
     *
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    public static function normaliseFiles(array $files, bool $trusted = false): array
    {
        $result = [];

        foreach ($files as $field => $f) {
            if (!is_array($f['error'] ?? null)) {
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $result[$field] = new UploadedFile($f['tmp_name'], $f['name'], $f['type'] ?? '', (int) $f['size'], (int) $f['error'], $trusted);
                }
                continue;
            }

            foreach (array_keys($f['error']) as $i) {
                if (is_int($f['error'][$i]) && $f['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    $result[$field][] = new UploadedFile($f['tmp_name'][$i], $f['name'][$i], $f['type'][$i] ?? '', (int) $f['size'][$i], $f['error'][$i], $trusted);
                }
            }
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function parseCookies(string $header): array
    {
        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, null);

            if ($name !== '' && $value !== null) {
                $cookies[$name] = rawurldecode($value);
            }
        }

        return $cookies;
    }

    public function method(): string
    {
        return $this->method;
    }

    /** A copy with another HTTP method. It shares this request's context, params and input. */
    /**
     * The same request with other body and/or query input (e.g. TrimStrings). It shares this
     * request's context and route params, so what earlier stages put there stays.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>|null $query
     */
    public function withInput(?array $body = null, ?array $query = null): self
    {
        $clone = new self($this->method, $this->path, $query ?? $this->query, $body ?? $this->body, $this->headers,
            $this->rawBody, $this->ip, $this->cookies, $this->files, $this->context);
        $clone->params = $this->params;

        return $clone;
    }

    public function withMethod(string $method): self
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);

        return $clone;
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

    /** An uploaded file (or list, for name="field[]" inputs), or null. */
    public function file(string $key): UploadedFile|array|null
    {
        return $this->files[$key] ?? null;
    }

    /** @return array<string, UploadedFile|list<UploadedFile>> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string, string> all headers, names lower-cased */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * True when the client asked for JSON (Accept: application/json) or is a script
     * (X-Requested-With). Stages use it to pick between a redirect and a JSON error.
     */
    public function expectsJson(): bool
    {
        return str_contains((string) $this->header('accept'), 'json')
            || strcasecmp((string) $this->header('x-requested-with'), 'XMLHttpRequest') === 0;
    }

    /** GET, HEAD and OPTIONS: requests that should not change anything. */
    public function isSafe(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
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

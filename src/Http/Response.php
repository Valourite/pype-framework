<?php

declare(strict_types=1);

namespace Pype\Http;

use Pype\Outlet\Outlet;

/**
 * Outgoing response, independent of wire format.
 *
 * A Response holds *what* to send (data or error, status, headers, meta).
 * An Outlet decides *how* it is written (JSON by default, a View, or anything you like).
 */
final class Response
{
    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var array<string, mixed>|null */
    private ?array $error = null;

    private ?Outlet $outlet = null;

    private ?string $rendered = null;

    /** @param array<string, string> $headers */
    public function __construct(
        private mixed $data = null,
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    /** @param array<string, string> $headers */
    public static function make(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self($data, $status, $headers);
    }

    public static function noContent(): self
    {
        return new self(null, 204);
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $message, array $details = []): self
    {
        $response = new self(null, $status);
        $response->error = ['status' => $status, 'message' => $message] + $details;

        return $response;
    }

    /**
     * Normalise whatever a stage returned:
     *   Response → as-is,  null → 204,  anything else → 200 with that data.
     */
    public static function from(mixed $result): self
    {
        return match (true) {
            $result instanceof self => $result,
            $result === null => self::noContent(),
            default => self::make($result),
        };
    }

    public function status(): int
    {
        return $this->status;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    /** @return array<string, mixed>|null */
    public function errorDetails(): ?array
    {
        return $this->error;
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return $this->meta;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function outlet(): ?Outlet
    {
        return $this->outlet;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /** Extra top-level info alongside data, e.g. pagination. */
    public function withMeta(array $meta): self
    {
        $clone = clone $this;
        $clone->meta = [...$clone->meta, ...$meta];

        return $clone;
    }

    public function withOutlet(Outlet $outlet): self
    {
        $clone = clone $this;
        $clone->outlet = $outlet;

        return $clone;
    }

    /** Render the body using the attached outlet (computed once). */
    public function render(Request $request): string
    {
        if ($this->status === 204 || $this->status === 304 || $this->outlet === null) {
            return '';
        }

        return $this->rendered ??= $this->outlet->render($this, $request);
    }

    public function __clone()
    {
        $this->rendered = null;
    }

    public function send(Request $request): void
    {
        $body = $this->render($request);

        if (!headers_sent()) {
            http_response_code($this->status);

            if ($this->outlet !== null && $this->header('Content-Type') === null) {
                header('Content-Type: ' . $this->outlet->contentType());
            }

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        if ($request->method() !== 'HEAD') {
            echo $body;
        }
    }
}

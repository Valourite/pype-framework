<?php

declare(strict_types=1);

namespace Pype\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Throw from anywhere in a pipeline to abort with a JSON error response.
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, mixed>  $details  extra fields in the error body
     * @param array<string, string> $headers  extra response headers (e.g. WWW-Authenticate, Retry-After)
     */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $details = [],
        ?Throwable $previous = null,
        private readonly array $headers = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function notFound(string $message = 'Not Found'): static
    {
        return new static(404, $message);
    }

    public static function forbidden(string $message = 'Forbidden'): static
    {
        return new static(403, $message);
    }

    public static function methodNotAllowed(array $allowed): static
    {
        return new static(405, 'Method Not Allowed', ['allowed' => $allowed]);
    }

    public static function unauthorized(string $message = 'Unauthorized', string $scheme = 'Bearer'): static
    {
        return new static(401, $message, headers: ['WWW-Authenticate' => $scheme]);
    }

    public static function tooManyRequests(int $retryAfter): static
    {
        return new static(429, 'Too Many Requests', ['retry_after' => $retryAfter], headers: ['Retry-After' => (string) $retryAfter]);
    }

    public static function unprocessable(array $errors, string $message = 'Validation failed'): static
    {
        return new static(422, $message, ['errors' => $errors]);
    }
}

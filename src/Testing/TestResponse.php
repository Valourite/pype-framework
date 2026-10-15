<?php

declare(strict_types=1);

namespace Pype\Testing;

use Pype\Http\Request;
use Pype\Http\Response;

/**
 * Read-only view of a handled response, for use with any test framework's assertions.
 */
final class TestResponse
{
    private ?string $body = null;

    public function __construct(
        private readonly Response $response,
        private readonly Request $request,
    ) {
    }

    public function status(): int
    {
        return $this->response->status();
    }

    public function header(string $name): ?string
    {
        return $this->response->header($name);
    }

    /** The formatted body exactly as a client would receive it. */
    public function body(): string
    {
        return $this->body ??= $this->response->render($this->request);
    }

    /** Decoded JSON body, or a value inside it by dot path ("data.user.id"). */
    public function json(?string $path = null): mixed
    {
        $value = json_decode($this->body(), true);

        foreach ($path === null ? [] : explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function response(): Response
    {
        return $this->response;
    }

    public function request(): Request
    {
        return $this->request;
    }
}

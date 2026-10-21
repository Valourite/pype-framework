<?php

declare(strict_types=1);

namespace Pype\Testing;

use PHPUnit\Framework\Assert;
use Pype\Http\Cookie;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Session\Session;

/**
 * Read-only view of a handled response. Read it with status(), json(), body() and your own
 * assertions, or use the assert*() methods here (PHPUnit); they return the response, so they chain:
 *
 *   $client->get('/quotes/1')->assertOk()->assertSee('Q-0001');
 *   $client->post('/api/items', [])->assertUnprocessable()->assertInvalid('name');
 *   $client->submit('/login', '/login', [...])->assertRedirect('/account');
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

    /** A cookie the response sets, by name. */
    public function cookie(string $name): ?Cookie
    {
        foreach ($this->response->cookies() as $cookie) {
            if ($cookie->name === $name) {
                return $cookie;
            }
        }

        return null;
    }

    public function header(string $name): ?string
    {
        // Content-Type is normally filled in from the outlet at send time; report it the same way.
        return strcasecmp($name, 'Content-Type') === 0 ? $this->response->contentType() : $this->response->header($name);
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

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->status(), "Expected status {$status}, got {$this->status()}." . $this->excerpt());

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    /** A redirect (3xx with a Location), to $to if given (compared as given: a path or a full URL). */
    public function assertRedirect(?string $to = null): self
    {
        Assert::assertTrue($this->response->isRedirect(), "Expected a redirect, got status {$this->status()}." . $this->excerpt());

        if ($to !== null) {
            Assert::assertSame($to, $this->header('Location'), 'The redirect goes somewhere else.');
        }

        return $this;
    }

    /** The header is present, and equal to $value if given. */
    public function assertHeader(string $name, ?string $value = null): self
    {
        $actual = $this->header($name);
        Assert::assertNotNull($actual, "No [{$name}] header.");

        if ($value !== null) {
            Assert::assertSame($value, $actual, "The [{$name}] header differs.");
        }

        return $this;
    }

    /** The body contains $text exactly as given (write escaped text as it appears: &lt;b&gt;). */
    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->body());

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->body());

        return $this;
    }

    /** The JSON body contains these keys with these values (other keys may be there too). */
    public function assertJson(array $subset): self
    {
        $json = $this->json();
        Assert::assertIsArray($json, 'The body is not JSON.' . $this->excerpt());
        self::assertSubset($subset, $json, '');

        return $this;
    }

    /** The JSON value at a dot path: assertJsonPath('data.name', 'Ada'). */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        Assert::assertSame($expected, $this->json($path), "JSON at [{$path}] differs.");

        return $this;
    }

    /** The response sets this cookie. */
    public function assertCookie(string $name): self
    {
        Assert::assertNotNull($this->cookie($name), "No [{$name}] cookie was set.");

        return $this;
    }

    /**
     * Validation failed for these fields: in the JSON error (API routes) or in the errors sent back
     * to the form (routes in the 'web' stack).
     */
    public function assertInvalid(string ...$fields): self
    {
        $errors = $this->json('error.errors')
            ?? $this->request->context()->find(Session::class)?->get('_errors')
            ?? [];

        foreach ($fields as $field) {
            Assert::assertArrayHasKey($field, $errors, "No validation error for [{$field}]; errors: " . json_encode($errors));
        }

        return $this;
    }

    private static function assertSubset(array $subset, array $actual, string $path): void
    {
        foreach ($subset as $key => $value) {
            $at = ltrim("{$path}.{$key}", '.');
            Assert::assertArrayHasKey($key, $actual, "JSON has no [{$at}].");

            if (is_array($value) && is_array($actual[$key])) {
                self::assertSubset($value, $actual[$key], $at);
            } else {
                Assert::assertSame($value, $actual[$key], "JSON at [{$at}] differs.");
            }
        }
    }

    /** The start of the body, for failure messages. */
    private function excerpt(): string
    {
        $body = $this->body();

        return $body === '' ? '' : ' Body: ' . (strlen($body) > 300 ? substr($body, 0, 300) . '…' : $body);
    }
}

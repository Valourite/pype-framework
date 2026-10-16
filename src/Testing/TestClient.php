<?php

declare(strict_types=1);

namespace Pype\Testing;

use Pype\Application;
use Pype\Http\Request;
use Pype\Http\UploadedFile;

/**
 * Drive an Application in-process — no web server, no globals.
 *
 *   $res = (new TestClient($app))->withHeaders(['Authorization' => 'Bearer x'])->get('/me');
 *   $res->status();  $res->json('data.name');
 *
 * Like a browser, a client keeps cookies (e.g. the session) between requests. Clients made
 * with withHeaders() share the jar; withoutCookies() starts a separate "browser".
 */
final class TestClient
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly Application $app,
        private readonly array $headers = [],
        private readonly CookieJar $cookies = new CookieJar(),
    ) {
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->app, [...$this->headers, ...$headers], $this->cookies);
    }

    /** A client with an empty cookie jar of its own: a second browser. */
    public function withoutCookies(): self
    {
        return new self($this->app, $this->headers, new CookieJar());
    }

    public function cookies(): CookieJar
    {
        return $this->cookies;
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], $headers);
    }

    /** @param array<string, UploadedFile|list<UploadedFile>> $files  sends a multipart form, like a browser */
    public function post(string $uri, array $body = [], array $headers = [], array $files = []): TestResponse
    {
        return $this->request('POST', $uri, $body, $headers, $files);
    }

    public function put(string $uri, array $body = [], array $headers = [], array $files = []): TestResponse
    {
        return $this->request('PUT', $uri, $body, $headers, $files);
    }

    public function patch(string $uri, array $body = [], array $headers = [], array $files = []): TestResponse
    {
        return $this->request('PATCH', $uri, $body, $headers, $files);
    }

    public function delete(string $uri, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, [], $headers);
    }

    public function request(string $method, string $uri, array $body = [], array $headers = [], array $files = []): TestResponse
    {
        $headers = [...$this->headers, ...$headers];

        if (($cookie = $this->cookies->header()) !== null && !array_key_exists('Cookie', $headers)) {
            $headers['Cookie'] = $cookie;
        }

        $request = Request::create($method, $uri, $body, $headers, $files);
        $response = $this->app->handle($request);
        $this->cookies->store($response->cookies());

        return new TestResponse($response, $request);
    }
}

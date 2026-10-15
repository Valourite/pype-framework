<?php

declare(strict_types=1);

namespace Pype\Testing;

use Pype\Application;
use Pype\Http\Request;

/**
 * Drive an Application in-process — no web server, no globals.
 *
 *   $res = (new TestClient($app))->withHeaders(['Authorization' => 'Bearer x'])->get('/me');
 *   $res->status();  $res->json('data.name');
 */
final class TestClient
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly Application $app,
        private readonly array $headers = [],
    ) {
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->app, [...$this->headers, ...$headers]);
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], $headers);
    }

    public function post(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $body, $headers);
    }

    public function put(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $body, $headers);
    }

    public function patch(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $body, $headers);
    }

    public function delete(string $uri, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, [], $headers);
    }

    public function request(string $method, string $uri, array $body = [], array $headers = []): TestResponse
    {
        $request = Request::create($method, $uri, $body, [...$this->headers, ...$headers]);

        return new TestResponse($this->app->handle($request), $request);
    }
}

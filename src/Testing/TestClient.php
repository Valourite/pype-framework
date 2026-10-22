<?php

declare(strict_types=1);

namespace Pype\Testing;

use LogicException;
use Pype\Application;
use Pype\Auth\SessionGuard;
use Pype\Http\Request;
use Pype\Http\UploadedFile;
use Pype\Session\Session;
use Pype\Session\SessionStore;

/**
 * Drive an Application in-process — no web server, no globals.
 *
 *   $res = (new TestClient($app))->withHeaders(['Authorization' => 'Bearer x'])->get('/me');
 *   $res->status();  $res->json('data.name');
 *
 *   $client->actingAs($user)->get('/account')->assertOk();                  // signed in, no login form
 *   $client->submit('/items/new', '/items', ['name' => 'Desk'])->assertRedirect('/items');
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
        private readonly string $ip = '127.0.0.1',
    ) {
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->app, [...$this->headers, ...$headers], $this->cookies, $this->ip);
    }

    /**
     * A client whose requests come from another address ($request->ip()), e.g. to test a per-IP
     * Throttle: `$client->withIp('10.0.0.2')->post(...)`. It shares this client's cookies; chain
     * withoutCookies() for a different visitor altogether.
     */
    public function withIp(string $ip): self
    {
        return new self($this->app, $this->headers, $this->cookies, $ip);
    }

    /** A client with an empty cookie jar of its own: a second browser. */
    public function withoutCookies(): self
    {
        return new self($this->app, $this->headers, new CookieJar(), $this->ip);
    }

    public function cookies(): CookieJar
    {
        return $this->cookies;
    }

    /**
     * Sign this client in as $user (a user object or id) without going through a login form: a
     * session is stored with the user in it, and its cookie goes in this client's jar. Pages behind
     * Authenticate then see the user. Needs sessions (SessionProvider) and auth (AuthProvider).
     */
    public function actingAs(object|int $user): self
    {
        $container = $this->app->container;

        if (!$container->has(SessionStore::class)) {
            throw new LogicException('actingAs() needs sessions: register SessionProvider.');
        }

        $session = new Session(Session::generateId());
        $container->get(SessionGuard::class)->login($session, $user);
        $session->token();

        $data = $session->dataToStore();
        $data[Session::WRITTEN] = time();
        $container->get(SessionStore::class)->write($session->id(), $data, (int) $this->app->config('session.lifetime', 120) * 60);
        $this->cookies->set((string) $this->app->config('session.cookie', 'pype_session'), $session->id());

        return $this;
    }

    /**
     * Fill in and send a form the way a browser does: load $page, take the CSRF token from its
     * `_token` input, and POST $fields to $action with $page as the Referer (where a failed
     * validation sends you back). For PUT/PATCH/DELETE add '_method' => 'PATCH' to $fields.
     *
     * @param array<string, mixed> $fields
     * @param array<string, UploadedFile|list<UploadedFile|null>|null> $files  null: a file input left empty
     */
    public function submit(string $page, string $action, array $fields = [], array $files = []): TestResponse
    {
        $html = $this->get($page)->body();

        if (!preg_match('/name="_token"[^>]*?value="([^"]*)"|value="([^"]*)"[^>]*?name="_token"/', $html, $m)) {
            throw new LogicException("No CSRF token on {$page}: the form needs an input named _token (value=\"<?= e(\$csrf) ?>\").");
        }

        $token = html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES);

        return $this->post($action, [...$fields, '_token' => $token], ['Referer' => 'http://localhost' . $page, 'Host' => 'localhost'], $files);
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], $headers);
    }

    /**
     * @param array<string, UploadedFile|list<UploadedFile|null>|null> $files  sends a multipart form, like a
     *        browser; null is a file input left empty (['attachments' => [$pdf, null, null]]), which the
     *        app never sees, exactly as with a real upload
     */
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

        $request = Request::create($method, $uri, $body, $headers, $files, $this->ip);
        $response = $this->app->handle($request);
        $this->app->terminate($request);   // after-response work runs, as in a real server
        $this->cookies->store($response->cookies());

        return new TestResponse($response, $request);
    }
}

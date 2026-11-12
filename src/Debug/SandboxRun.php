<?php

declare(strict_types=1);

namespace Pype\Debug;

use Pype\Application;
use Pype\Auth\SessionGuard;
use Pype\Database\Connection;
use Pype\Mail\ArrayTransport;
use Pype\Mail\Transport;
use Pype\Queue\ArrayQueue;
use Pype\Queue\Queue;
use Pype\Session\Session;
use Pype\Session\SessionStore;
use Pype\Testing\TestClient;
use Throwable;

/**
 * One request against the sandbox, from inside the sandbox process (`bin/pype sandbox:run`,
 * started by Sandbox::run with PYPE_SANDBOX set): the app's Connection is the sandbox file and
 * mail, queue, cache and sessions are in memory.
 *
 * It watches the database, sends the request through the app as the TestClient does (signed in as
 * the chosen user, with the session's CSRF token), and reports the
 * response, what the stages did, the rows that changed, and the mail and jobs the request made.
 */
final class SandboxRun
{
    private const MAX_BODY = 200_000;

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @param array{method?: string, uri?: string, body?: array<string, mixed>, headers?: array<string, string>, user?: ?int, json?: bool} $spec
     * @return array<string, mixed>
     */
    public function run(array $spec): array
    {
        if (!$this->app->config('app.sandbox')) {
            throw new \LogicException('sandbox:run only runs in sandbox mode (PYPE_SANDBOX, in debug): it is started by the sandbox screen.');
        }

        $container = $this->app->container;
        $db = $container->bound(Connection::class) ? $container->get(Connection::class) : null;
        $method = strtoupper((string) ($spec['method'] ?? 'GET'));
        $body = (array) ($spec['body'] ?? []);
        $headers = (array) ($spec['headers'] ?? []);
        $client = new TestClient($this->app);
        $notes = [];

        // A session of their own: signed in when a user was chosen, and with the CSRF token a form would carry.
        if ($container->bound(SessionStore::class)) {
            $session = new Session(Session::generateId());

            if (($spec['user'] ?? null) !== null) {
                $container->get(SessionGuard::class)->login($session, (int) $spec['user']);
                $notes[] = "Signed in as user #{$spec['user']} (a session, as after the login form).";
            }

            $token = $session->token();
            $data = $session->dataToStore();
            $data[Session::WRITTEN] = time();
            $container->get(SessionStore::class)->write($session->id(), $data, (int) $this->app->config('session.lifetime', 120) * 60);
            $client->cookies()->set((string) $this->app->config('session.cookie', 'pype_session'), $session->id());

            // As the page's own form or script would send it: a field in a form, a header with JSON.
            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !isset($body['_token']) && !isset(array_change_key_case($headers)['x-csrf-token'])) {
                if ($spec['json'] ?? false) {
                    $headers['X-CSRF-Token'] = $token;
                    $notes[] = 'The session\'s CSRF token was added as the X-CSRF-Token header.';
                } else {
                    $body['_token'] = $token;
                    $notes[] = 'The session\'s CSRF token was added as _token.';
                }
            }
        } elseif (($spec['user'] ?? null) !== null) {
            $notes[] = 'No sessions in this app (SessionProvider): the request was sent signed out. Send an Authorization header instead.';
        }

        $watch = $db === null ? null : TableDiff::watch($db);
        $log = $container->bound(RequestLog::class) ? $container->get(RequestLog::class) : null;
        $log?->clear();

        $started = hrtime(true);
        if ($spec['json'] ?? false) {
            $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        }

        $response = $client->request($method, (string) ($spec['uri'] ?? '/'), $body, $headers);
        $ms = (hrtime(true) - $started) / 1e6;

        // Read the changes before anything else touches the database, with the recorder no longer listening.
        $changes = $db === null ? [] : TableDiff::changes($db, $watch);
        $rendered = $response->body();
        $trace = $log?->all()[0]['id'] ?? null;

        return [
            'notes' => $notes,
            'request' => ['method' => $method, 'uri' => (string) ($spec['uri'] ?? '/'), 'body' => Redact::fields($body), 'headers' => Redact::headers($headers), 'user' => $spec['user'] ?? null],
            'response' => [
                'status' => $response->status(),
                'ms' => round($ms, 3),
                'type' => (string) $response->header('Content-Type'),
                'location' => $response->header('Location'),
                'headers' => Redact::headers($response->response()->headers()),
                'body' => mb_strcut($rendered, 0, self::MAX_BODY),
                'bytes' => strlen($rendered),
            ],
            'trace' => $trace === null ? null : $log->find($trace),
            'changes' => $changes,
            'mail' => $this->mail(),
            'jobs' => $this->jobs(),
        ];
    }

    /** @return list<array<string, mixed>> the mail the request sent (kept in memory: nothing was delivered) */
    private function mail(): array
    {
        try {
            $transport = $this->app->container->bound(Transport::class) ? $this->app->container->get(Transport::class) : null;
        } catch (Throwable) {
            return [];
        }

        if (!$transport instanceof ArrayTransport) {
            return [];
        }

        return array_map(static fn ($message) => [
            'subject' => $message->getSubject(),
            'to' => array_map(static fn ($to) => is_array($to) ? (string) ($to['address'] ?? $to[0] ?? json_encode($to)) : (string) $to, $message->recipients('to')),
            'text' => mb_strcut((string) ($message->getText() ?? strip_tags((string) $message->getHtml())), 0, 4000),
        ], $transport->messages());
    }

    /** @return list<array{class: string, data: string}> the jobs the request queued (kept in memory: none ran) */
    private function jobs(): array
    {
        try {
            $queue = $this->app->container->bound(Queue::class) ? $this->app->container->get(Queue::class) : null;
        } catch (Throwable) {
            return [];
        }

        if (!$queue instanceof ArrayQueue) {
            return [];
        }

        return array_map(static fn (object $job) => [
            'class' => $job::class,
            'data' => mb_strcut((string) json_encode(Redact::fields(get_object_vars($job)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 2000),
        ], $queue->pushed());
    }
}

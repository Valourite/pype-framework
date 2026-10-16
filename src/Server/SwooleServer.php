<?php

declare(strict_types=1);

namespace Pype\Server;

use Pype\Application;
use Pype\Database\Connection;
use Pype\Http\Request;
use Pype\Http\Response;
use RuntimeException;
use Throwable;

/**
 * Serves the app with OpenSwoole: the app boots once, then each worker process keeps it in
 * memory and handles requests without PHP-FPM's per-request startup. Started by
 * `php bin/pype serve` when APP_SERVER=swoole (or --server=swoole).
 *
 * Workers are plain processes handling one request at a time (no coroutines), so pype's
 * synchronous model, PDO and SQLite work exactly as under PHP-FPM. Per-request state lives on
 * the Request, never in services, so nothing carries over between requests.
 *
 * Code changes need a restart: the code is loaded once.
 */
final class SwooleServer
{
    /** @param array<string, mixed> $settings  OpenSwoole server settings, merged over the defaults */
    public function __construct(
        private readonly Application $app,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8000,
        private readonly array $settings = [],
    ) {
    }

    public static function available(): bool
    {
        return class_exists(\OpenSwoole\Http\Server::class) || class_exists(\Swoole\Http\Server::class);
    }

    public function start(): void
    {
        if (!self::available()) {
            throw new RuntimeException('APP_SERVER=swoole needs the openswoole extension (pecl install openswoole).');
        }

        $class = class_exists(\OpenSwoole\Http\Server::class) ? \OpenSwoole\Http\Server::class : \Swoole\Http\Server::class;
        $server = new $class($this->host, $this->port);
        $settings = $this->settings();
        $server->set($settings);

        $server->on('start', function () use ($settings) {
            printf("pype serving on http://%s:%d with OpenSwoole (%d workers). Code changes need a restart.\n", $this->host, $this->port, $settings['worker_num']);
        });
        // A forked worker must never share the master's database handle (e.g. one opened while
        // booting): drop it so each worker connects on its own first query.
        $server->on('workerStart', function () {
            if ($this->app->container->resolved(Connection::class)) {
                $this->app->container->get(Connection::class)->disconnect();
            }
        });
        $server->on('request', function (object $req, object $res) {
            $this->handle($req, $res);
        });

        $server->start();
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return [
            // Twice the cores: a worker busy hashing a password (bcrypt) shouldn't leave a core idle.
            'worker_num' => 2 * self::cpus(),
            'max_request' => 10000,            // recycle workers now and then, capping any slow leak
            'enable_coroutine' => false,       // one request at a time per worker, like PHP-FPM
            'package_max_length' => 8 * 1024 * 1024,
            'enable_static_handler' => true,   // css, images, ... straight from public/
            'document_root' => $this->app->path('public'),
            'http_compression' => false,
            ...array_filter($this->settings, static fn ($v) => $v !== null),
        ];
    }

    /** CPU cores the machine reports. Containers with a CPU limit still report all host cores: set workers yourself there. */
    private static function cpus(): int
    {
        $n = match (true) {
            method_exists(\OpenSwoole\Util::class, 'getCPUNum') => \OpenSwoole\Util::getCPUNum(),
            function_exists('swoole_cpu_num') => swoole_cpu_num(),
            default => 1,
        };

        return max(1, (int) $n);
    }

    public function handle(object $req, object $res): void
    {
        try {
            $request = $this->toRequest($req);
            $this->emit($this->app->handle($request), $request, $res);
        } catch (Throwable $e) {
            // Application::handle() already turns errors into responses; this is the last resort,
            // so one bad request never takes the worker down.
            error_log('pype: ' . $e);
            $res->status(500);
            $res->end('Server error');
        }
    }

    /** OpenSwoole request → pype Request. */
    public function toRequest(object $req): Request
    {
        $server = $req->server ?? [];
        $headers = $req->header ?? [];
        $raw = method_exists($req, 'rawContent') ? (string) $req->rawContent() : (string) $req->getContent();

        return new Request(
            method: strtoupper($server['request_method'] ?? 'GET'),
            path: parse_url($server['request_uri'] ?? '/', PHP_URL_PATH) ?: '/',
            query: $req->get ?? [],
            body: Request::bodyFrom($raw, $headers['content-type'] ?? '', $req->post ?? []),
            headers: $headers,
            rawBody: $raw,
            ip: (string) ($server['remote_addr'] ?? '127.0.0.1'),
            cookies: array_filter($req->cookie ?? [], 'is_string'),
            files: Request::normaliseFiles(self::filesLikePhp($req->files ?? []), trusted: true),
        );
    }

    /** pype Response → OpenSwoole response. */
    public function emit(Response $response, Request $request, object $res): void
    {
        $body = $response->render($request);
        $res->status($response->status());

        if ($response->header('Content-Type') === null && ($type = $response->contentType()) !== null) {
            $res->header('Content-Type', $type);
        }

        foreach ($response->headers() as $name => $value) {
            $res->header($name, $value);
        }

        // header() can't repeat Set-Cookie, so each cookie goes through rawCookie().
        foreach ($response->cookies() as $c) {
            $res->rawCookie(
                $c->name,
                rawurlencode($c->value),
                $c->maxAge === null ? 0 : ($c->maxAge > 0 ? time() + $c->maxAge : 1),
                $c->path,
                $c->domain ?? '',
                $c->secure || $c->sameSite === 'None',
                $c->httpOnly,
                $c->sameSite,
            );
        }

        $res->end($request->method() === 'HEAD' ? '' : $body);
    }

    /**
     * OpenSwoole gives "receipts[]" uploads as a list of file arrays; PHP's $_FILES groups them
     * by key (name => [...], tmp_name => [...]). Convert to PHP's shape for normaliseFiles().
     */
    private static function filesLikePhp(array $files): array
    {
        foreach ($files as $field => $f) {
            if (array_is_list($f) && $f !== [] && is_array($f[0])) {
                $grouped = [];
                foreach ($f as $i => $one) {
                    foreach ($one as $key => $value) {
                        $grouped[$key][$i] = $value;
                    }
                }
                $files[$field] = $grouped;
            }
        }

        return $files;
    }
}

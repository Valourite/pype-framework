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
    /** Files over this size are left to the app (and so, usually, a 404): public/ holds css, js and images. */
    private const STATIC_MAX_BYTES = 8 * 1024 * 1024;

    /** @var array<string, string> extension => Content-Type; anything else is application/octet-stream */
    private const STATIC_TYPES = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json', 'map' => 'application/json', 'webmanifest' => 'application/manifest+json',
        'html' => 'text/html; charset=utf-8', 'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'pdf' => 'application/pdf',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ];

    /** Set in start(): true when OpenSwoole's own static handler is on, so handle() leaves static files to it. */
    private bool $nativeStatic = false;

    /** @param array<string, mixed> $settings  OpenSwoole server settings, merged over the defaults */
    public function __construct(
        private readonly Application $app,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8000,
        private readonly array $settings = [],
    ) {
    }

    /** Short names config and .env may use for server settings (SWOOLE_WORKERS=4). */
    private const ALIASES = ['workers' => 'worker_num', 'static_handler' => 'enable_static_handler'];

    /**
     * The server `php bin/pype serve` starts. `app.swoole` is a list of OpenSwoole/Swoole server
     * settings, passed through as is over pype's defaults (settings()); the skeleton's config
     * spreads env_prefixed('SWOOLE_') into it, so any SWOOLE_* variable sets one. Also `mode`
     * ('base' or 'process'), the short names `workers` and `static_handler`, and, from older
     * configs, a nested `settings` array (the others win over it). --workers wins over all.
     *
     * @param array<string, string> $options  serve's --name=value options
     */
    public static function fromApp(Application $app, array $options = []): self
    {
        $config = (array) $app->config('app.swoole', []);
        $settings = [...(array) ($config['settings'] ?? []), ...array_diff_key($config, ['settings' => true])];

        foreach (self::ALIASES as $short => $name) {
            if (array_key_exists($short, $settings)) {
                $settings[$name] = $settings[$short];
                unset($settings[$short]);
            }
        }
        if (isset($options['workers'])) {
            $settings['worker_num'] = $options['workers'];
        }
        if (is_numeric($settings['worker_num'] ?? null)) {
            $settings['worker_num'] = (int) $settings['worker_num'];
        }
        if (is_string($settings['enable_static_handler'] ?? null)) {
            $settings['enable_static_handler'] = filter_var($settings['enable_static_handler'], FILTER_VALIDATE_BOOLEAN);
        }

        return new self($app, $options['host'] ?? '0.0.0.0', (int) ($options['port'] ?? 8000), array_filter(
            $settings,
            static fn ($v) => $v !== null && $v !== '',
        ));
    }

    /**
     * SWOOLE_BASE (1, the default): each worker accepts and owns its connections; no extra hop.
     * SWOOLE_PROCESS (2): a reactor process holds the connections and hands requests to workers,
     * so a worker that restarts (max_request) doesn't drop the keep-alive connections it had.
     * In base mode those connections close with it: a proxy in front retries GETs, but a POST
     * caught on one fails. Same numbers in OpenSwoole (SIMPLE_MODE / POOL_MODE).
     */
    public function mode(): int
    {
        $mode = $this->settings['mode'] ?? 'base';

        return match (is_string($mode) ? strtolower($mode) : $mode) {
            'base', 1 => 1,
            'process', 2 => 2,
            default => throw new RuntimeException("app.swoole.mode must be 'base' or 'process', not [{$mode}]."),
        };
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
        $server = new $class($this->host, $this->port, $this->mode());
        $settings = $this->settings();
        $this->nativeStatic = (bool) $settings['enable_static_handler'];
        $server->set($settings);
        $this->app->router->warm();   // built once here; every forked worker inherits the tables

        $server->on('start', function () use ($settings) {
            printf("pype serving on http://%s:%d with OpenSwoole (%d workers, %s mode). Code changes need a restart.\n", $this->host, $this->port, $settings['worker_num'], $this->mode() === 2 ? 'process' : 'base');
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
            // One per core: each worker runs one request at a time and PHP is CPU-bound, so more
            // workers than cores only adds switching (the twelve bench: 1x beat 2x on CPU and p95).
            // Set SWOOLE_WORKERS higher when requests wait on a network database or bcrypt.
            'worker_num' => self::cpus(),
            // Recycle each worker after this many requests, capping any slow leak (0 = never). Rare on
            // purpose: in base mode a recycled worker drops its keep-alive connections (see mode()).
            // At 10,000 the helpdesk bench recycled a worker every ~14 s at 700 req/s.
            'max_request' => 100000,
            'enable_coroutine' => false,       // one request at a time per worker, like PHP-FPM
            'package_max_length' => 8 * 1024 * 1024,
            // Static files (css, images, ...) are served by serveStatic() below. OpenSwoole's own handler
            // (set 'enable_static_handler' => true, i.e. app.swoole.static_handler) leaves the connection
            // corked after a file: every later response on it, pages too, waits ~200 ms for the cork to time
            // out (seen with Swoole 6.2.3 and Chrome: a click took 300-500 ms instead of 2).
            'enable_static_handler' => false,
            'document_root' => $this->app->path('public'),
            'http_compression' => false,
            ...array_filter($this->settings, static fn ($v, $k) => $v !== null && $k !== 'mode', ARRAY_FILTER_USE_BOTH),
        ];
    }

    /** CPU cores this process may use: the container's limit if there is one, else the machine's. */
    public static function cpus(string $cgroup = '/sys/fs/cgroup'): int
    {
        // A container limited with --cpus 2 still reports every host core to getCPUNum().
        if (($limit = self::cgroupLimit($cgroup)) !== null) {
            return $limit;
        }

        $n = match (true) {
            method_exists(\OpenSwoole\Util::class, 'getCPUNum') => \OpenSwoole\Util::getCPUNum(),
            function_exists('swoole_cpu_num') => swoole_cpu_num(),
            default => 1,
        };

        return max(1, (int) $n);
    }

    /** cgroup v2 (cpu.max: "200000 100000") or v1 (cpu.cfs_quota_us / cpu.cfs_period_us); null = no limit. */
    private static function cgroupLimit(string $cgroup): ?int
    {
        $v2 = @file_get_contents("{$cgroup}/cpu.max");

        if (is_string($v2)) {
            [$quota, $period] = array_pad(explode(' ', trim($v2)), 2, '100000');

            return $quota === 'max' || (int) $period <= 0 ? null : max(1, (int) ceil((int) $quota / (int) $period));
        }

        $quota = @file_get_contents("{$cgroup}/cpu/cpu.cfs_quota_us");
        $period = @file_get_contents("{$cgroup}/cpu/cpu.cfs_period_us");

        if (!is_string($quota) || !is_string($period) || (int) $quota <= 0 || (int) $period <= 0) {
            return null;
        }

        return max(1, (int) ceil((int) $quota / (int) $period));
    }

    public function handle(object $req, object $res): void
    {
        try {
            if (!$this->nativeStatic && $this->serveStatic($req, $res)) {
                return;
            }

            $request = $this->toRequest($req);
            $this->emit($this->app->handle($request), $request, $res);
            $this->app->terminate($request);   // the response is sent; now deferred work (Mailer::later)
        } catch (Throwable $e) {
            // Application::handle() already turns errors into responses; this is the last resort,
            // so one bad request never takes the worker down.
            error_log('pype: ' . $e);
            $res->status(500);
            $res->end('Server error');
        }
    }

    /**
     * Answer a GET/HEAD for a file under public/ (css, js, images, ...) without touching the app.
     * Returns false when the request isn't for such a file, so the app handles it. Never serves
     * PHP/executable files or dotfiles, and nothing outside public/. Sends Last-Modified and answers
     * If-Modified-Since with 304, like the native handler did.
     */
    public function serveStatic(object $req, object $res): bool
    {
        $server = $req->server ?? [];
        $method = strtoupper($server['request_method'] ?? 'GET');

        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        $path = rawurldecode(parse_url($server['request_uri'] ?? '/', PHP_URL_PATH) ?: '/');

        if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('#(^|/)\.#', $path) === 1) {
            return false;   // NUL, backslashes, dotfiles and ".." segments
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === '' || preg_match('/^(php\d?|phtml|phar|inc)$/', $extension) === 1) {
            return false;
        }

        $root = realpath($this->app->path('public'));
        $file = $root === false ? false : realpath($root . $path);

        if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file) || filesize($file) > self::STATIC_MAX_BYTES) {
            return false;
        }

        $modified = (int) filemtime($file);
        $res->header('Content-Type', self::STATIC_TYPES[$extension] ?? 'application/octet-stream');
        $res->header('Last-Modified', gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        $res->header('X-Content-Type-Options', 'nosniff');

        $since = strtotime((string) (($req->header ?? [])['if-modified-since'] ?? ''));

        if ($since !== false && $since >= $modified) {
            $res->status(304);
            $res->end('');

            return true;
        }

        $res->status(200);
        $res->end($method === 'HEAD' ? '' : (string) file_get_contents($file));

        return true;
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

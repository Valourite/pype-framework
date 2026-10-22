<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pype\Application;
use Pype\Http\Cookie;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Http\UploadedFile;
use Pype\Server\SwooleServer;

/** The OpenSwoole adapter, against fakes shaped like OpenSwoole's request and response (no extension needed). */
final class SwooleServerTest extends TestCase
{
    private SwooleServer $server;

    protected function setUp(): void
    {
        $this->server = new SwooleServer(new Application(__DIR__));
    }

    private static function req(array $server, array $headers = [], array $get = [], ?array $post = null, array $cookie = [], array $files = [], string $raw = ''): object
    {
        return new class ($server, $headers, $get, $post, $cookie, $files, $raw) {
            public function __construct(public array $server, public array $header, public array $get, public ?array $post, public array $cookie, public array $files, private string $raw)
            {
            }

            public function rawContent(): string
            {
                return $this->raw;
            }
        };
    }

    private static function res(): object
    {
        return new class () {
            public int $status = 200;
            public array $headers = [];
            public array $cookies = [];
            public ?string $body = null;

            public function status(int $code): void
            {
                $this->status = $code;
            }

            public function header(string $k, string $v): void
            {
                $this->headers[$k] = $v;
            }

            public function rawCookie(string $name, string $value, int $expires, string $path, string $domain, bool $secure, bool $httpOnly, string $sameSite): void
            {
                $this->cookies[$name] = compact('value', 'expires', 'path', 'secure', 'httpOnly', 'sameSite');
            }

            public function end(string $body): void
            {
                $this->body = $body;
            }
        };
    }

    public function test_get_with_query_headers_and_cookies(): void
    {
        $r = $this->server->toRequest(self::req(
            ['request_method' => 'get', 'request_uri' => '/expenses', 'remote_addr' => '10.0.0.7'],
            ['accept' => 'text/html', 'x-thing' => '1'],
            ['month' => '2026-10'],
            cookie: ['pype_session' => 'abc'],
        ));

        $this->assertSame('GET', $r->method());
        $this->assertSame('/expenses', $r->path());
        $this->assertSame('2026-10', $r->query('month'));
        $this->assertSame('1', $r->header('X-Thing'));
        $this->assertSame('abc', $r->cookie('pype_session'));
        $this->assertSame('10.0.0.7', $r->ip());
    }

    public function test_json_and_form_bodies(): void
    {
        $json = $this->server->toRequest(self::req(['request_method' => 'POST', 'request_uri' => '/api/expenses'], ['content-type' => 'application/json'], raw: '{"amount":"12.50"}'));
        $this->assertSame('12.50', $json->input('amount'));

        $form = $this->server->toRequest(self::req(['request_method' => 'POST', 'request_uri' => '/expenses/3'], ['content-type' => 'application/x-www-form-urlencoded'], post: ['_method' => 'PATCH', 'amount' => '9']));
        $this->assertSame('PATCH', $form->input('_method'));
        $this->assertSame('9', $form->input('amount'));
    }

    public function test_uploads_are_trusted_and_can_be_stored(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'swoole');
        copy(__DIR__ . '/../fixtures/files/receipt.pdf', $tmp);
        $tmp2 = tempnam(sys_get_temp_dir(), 'swoole');
        copy(__DIR__ . '/../fixtures/files/pixel.png', $tmp2);

        $r = $this->server->toRequest(self::req(['request_method' => 'POST', 'request_uri' => '/expenses'], ['content-type' => 'multipart/form-data; boundary=x'], files: [
            'receipt' => ['name' => 'r.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)],
            // OpenSwoole sends "photos[]" as a list of file arrays
            'photos' => [['name' => 'p.png', 'type' => 'image/png', 'tmp_name' => $tmp2, 'error' => 0, 'size' => filesize($tmp2)]],
        ]));

        $file = $r->file('receipt');
        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertTrue($file->isValid(), 'OpenSwoole temp files are not PHP uploads, but are trusted');
        $this->assertSame('pdf', $file->extension());
        $this->assertSame('png', $r->files()['photos'][0]->extension());

        $dir = sys_get_temp_dir() . '/pype-store-' . bin2hex(random_bytes(3));
        $name = $file->store($dir);
        $this->assertFileExists("{$dir}/{$name}");
        $this->assertFileDoesNotExist($tmp);

        unlink("{$dir}/{$name}");
        rmdir($dir);
        @unlink($tmp2);
    }

    public function test_emit_status_headers_cookies_and_body(): void
    {
        $request = Request::create('GET', '/x');
        $response = Response::make(['ok' => true], 201)
            ->withHeader('X-Request-Id', 'r1')
            ->withCookie(new Cookie('pype_session', 'a b', maxAge: 60))
            ->withCookie(Cookie::forget('old'));
        $res = self::res();

        $this->server->emit($response->withOutlet(new \Pype\Outlet\JsonOutlet()), $request, $res);

        $this->assertSame(201, $res->status);
        $this->assertSame('r1', $res->headers['X-Request-Id']);
        $this->assertStringStartsWith('application/json', $res->headers['Content-Type']);
        $this->assertSame('a%20b', $res->cookies['pype_session']['value']);
        $this->assertEqualsWithDelta(time() + 60, $res->cookies['pype_session']['expires'], 1);
        $this->assertTrue($res->cookies['pype_session']['httpOnly']);
        $this->assertSame(1, $res->cookies['old']['expires'], 'forgotten cookies expire in the past');
        $this->assertSame(['ok' => true], json_decode($res->body, true)['data']);
    }

    public function test_head_and_redirects_have_no_body(): void
    {
        $res = self::res();
        $this->server->emit(Response::redirect('/login', 303)->withOutlet(new \Pype\Outlet\JsonOutlet()), Request::create('POST', '/x'), $res);
        $this->assertSame(303, $res->status);
        $this->assertSame('/login', $res->headers['Location']);
        $this->assertSame('', $res->body);

        $res = self::res();
        $this->server->emit(Response::make(['a' => 1])->withOutlet(new \Pype\Outlet\JsonOutlet()), Request::create('HEAD', '/x'), $res);
        $this->assertSame('', $res->body);
    }

    public function test_full_request_through_the_app(): void
    {
        $app = new Application(__DIR__);
        $app->router->get('/hello/{name}', fn (string $name) => ['hi' => $name]);
        $server = new SwooleServer($app);
        $res = self::res();

        $server->handle(self::req(['request_method' => 'GET', 'request_uri' => '/hello/ada']), $res);
        $this->assertSame(200, $res->status);
        $this->assertSame('ada', json_decode($res->body, true)['data']['hi']);

        $server->handle(self::req(['request_method' => 'GET', 'request_uri' => '/nope']), $res = self::res());
        $this->assertSame(404, $res->status);
    }

    public function test_settings_defaults_and_overrides(): void
    {
        $s = (new SwooleServer(new Application(__DIR__), settings: ['worker_num' => 8, 'max_request' => null]))->settings();

        $this->assertSame(8, $s['worker_num']);
        $this->assertSame(100000, $s['max_request'], 'null means keep the default');
        $this->assertFalse($s['enable_coroutine']);
        $this->assertArrayNotHasKey('mode', $s, 'mode is the constructor argument, not a setting');
    }

    public function test_mode_is_base_unless_set(): void
    {
        $app = new Application(__DIR__);

        $this->assertSame(1, (new SwooleServer($app))->mode());
        $this->assertSame(2, (new SwooleServer($app, settings: ['mode' => 'process']))->mode());
        $this->assertSame(2, (new SwooleServer($app, settings: ['mode' => 'PROCESS']))->mode());
        $this->assertSame(1, (new SwooleServer($app, settings: ['mode' => 1]))->mode());

        $this->expectExceptionMessage("must be 'base' or 'process'");
        (new SwooleServer($app, settings: ['mode' => 'threads']))->mode();
    }

    public function test_cpus_follow_the_container_limit(): void
    {
        $dir = sys_get_temp_dir() . '/pype-cgroup-' . bin2hex(random_bytes(4));
        mkdir("{$dir}/v2", 0777, true);
        mkdir("{$dir}/v1/cpu", 0777, true);
        mkdir("{$dir}/none");

        file_put_contents("{$dir}/v2/cpu.max", "200000 100000\n");
        $this->assertSame(2, SwooleServer::cpus("{$dir}/v2"));
        file_put_contents("{$dir}/v2/cpu.max", "150000 100000\n");   // --cpus 1.5: round up
        $this->assertSame(2, SwooleServer::cpus("{$dir}/v2"));
        file_put_contents("{$dir}/v2/cpu.max", "50000 100000\n");
        $this->assertSame(1, SwooleServer::cpus("{$dir}/v2"));

        file_put_contents("{$dir}/v1/cpu/cpu.cfs_quota_us", "400000\n");
        file_put_contents("{$dir}/v1/cpu/cpu.cfs_period_us", "100000\n");
        $this->assertSame(4, SwooleServer::cpus("{$dir}/v1"));

        // No limit (cpu.max "max", v1 quota -1, or no files): the machine's count.
        $machine = SwooleServer::cpus("{$dir}/none");
        $this->assertGreaterThanOrEqual(1, $machine);
        file_put_contents("{$dir}/v2/cpu.max", "max 100000\n");
        $this->assertSame($machine, SwooleServer::cpus("{$dir}/v2"));
        file_put_contents("{$dir}/v1/cpu/cpu.cfs_quota_us", "-1\n");
        $this->assertSame($machine, SwooleServer::cpus("{$dir}/v1"));

        exec('rm -rf ' . escapeshellarg($dir));
    }

    // ── Static files ─────────────────────────────────────────────────────────

    /** A throwaway app whose public/ holds css/app.css, a PHP file and a dotfile (and a secret outside public/). */
    private function appWithPublic(): array
    {
        $base = sys_get_temp_dir() . '/pype-static-' . bin2hex(random_bytes(4));
        mkdir("{$base}/public/css", 0777, true);
        file_put_contents("{$base}/public/css/app.css", 'body { color: red }');
        file_put_contents("{$base}/public/index.php", '<?php echo "source";');
        file_put_contents("{$base}/public/.env", 'SECRET=1');
        file_put_contents("{$base}/secret.css", 'outside');
        touch("{$base}/public/css/app.css", 1_700_000_000);

        return [$base, new SwooleServer(new Application($base))];
    }

    private static function cleanup(string $base): void
    {
        foreach (['public/css/app.css', 'public/index.php', 'public/.env', 'secret.css'] as $f) {
            @unlink("{$base}/{$f}");
        }
        @rmdir("{$base}/public/css");
        @rmdir("{$base}/public");
        @rmdir($base);
    }

    public function test_static_files_are_served_from_public_without_the_app(): void
    {
        [$base, $server] = $this->appWithPublic();
        $res = self::res();

        $this->assertTrue($server->serveStatic(self::req(['request_method' => 'GET', 'request_uri' => '/css/app.css?v=3']), $res));
        $this->assertSame(200, $res->status);
        $this->assertSame('body { color: red }', $res->body);
        $this->assertSame('text/css; charset=utf-8', $res->headers['Content-Type']);
        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $res->headers['Last-Modified']);
        $this->assertSame('nosniff', $res->headers['X-Content-Type-Options']);

        $head = self::res();
        $this->assertTrue($server->serveStatic(self::req(['request_method' => 'HEAD', 'request_uri' => '/css/app.css']), $head));
        $this->assertSame('', $head->body);

        self::cleanup($base);
    }

    public function test_unchanged_static_files_answer_304(): void
    {
        [$base, $server] = $this->appWithPublic();
        $res = self::res();

        $this->assertTrue($server->serveStatic(self::req(['request_method' => 'GET', 'request_uri' => '/css/app.css'], ['if-modified-since' => 'Tue, 14 Nov 2023 22:13:20 GMT']), $res));
        $this->assertSame(304, $res->status);
        $this->assertSame('', $res->body);

        $stale = self::res();
        $server->serveStatic(self::req(['request_method' => 'GET', 'request_uri' => '/css/app.css'], ['if-modified-since' => 'Mon, 13 Nov 2023 00:00:00 GMT']), $stale);
        $this->assertSame(200, $stale->status);

        self::cleanup($base);
    }

    public function test_only_files_inside_public_are_served(): void
    {
        [$base, $server] = $this->appWithPublic();

        foreach ([
            ['GET', '/'], ['GET', '/expenses'], ['GET', '/missing.css'], ['GET', '/css'],     // not files
            ['GET', '/index.php'], ['GET', '/index.php/'],                                    // source code
            ['GET', '/.env'], ['GET', '/css/../.env'],                                        // dotfiles
            ['GET', '/../secret.css'], ['GET', '/%2e%2e/secret.css'], ['GET', '/css/..%2f..%2fsecret.css'],   // outside public/
            ['POST', '/css/app.css'],                                                         // not a read
        ] as [$method, $uri]) {
            $res = self::res();
            $this->assertFalse($server->serveStatic(self::req(['request_method' => $method, 'request_uri' => $uri]), $res), "{$method} {$uri}");
            $this->assertNull($res->body, "{$method} {$uri} wrote a body");
        }

        self::cleanup($base);
    }

    public function test_from_app_passes_settings_through_and_named_options_win(): void
    {
        $app = new Application(__DIR__, ['app' => ['swoole' => [
            'workers' => 4,
            'settings' => ['package_max_length' => 8 * 1024 * 1024, 'worker_num' => 2, 'http_compression' => false],
        ]]]);

        $s = SwooleServer::fromApp($app)->settings();
        $this->assertSame(8 * 1024 * 1024, $s['package_max_length']);   // any server setting, as is
        $this->assertFalse($s['http_compression']);
        $this->assertSame(4, $s['worker_num']);                            // app.swoole.workers wins over settings
        $this->assertSame(6, SwooleServer::fromApp($app, ['workers' => '6'])->settings()['worker_num']);   // and --workers over both
    }

    public function test_from_app_reads_flat_settings_like_the_skeleton_config(): void
    {
        // config/app.php: ['mode' => 'base', 'max_request' => 100000, ...env_prefixed('SWOOLE_')]
        putenv('SWOOLE_MAX_REQUEST=0');
        putenv('SWOOLE_WORKERS=3');
        putenv('SWOOLE_STATIC_HANDLER=true');
        putenv('SWOOLE_PACKAGE_MAX_LENGTH=16777216');
        putenv('SWOOLE_MODE=process');
        putenv('SWOOLE_BUFFER_OUTPUT_SIZE=');
        try {
            $app = new Application(__DIR__, ['app' => ['swoole' => ['mode' => 'base', 'max_request' => 100000, ...env_prefixed('SWOOLE_')]]]);
            $server = SwooleServer::fromApp($app);
            $s = $server->settings();
        } finally {
            foreach (['MAX_REQUEST', 'WORKERS', 'STATIC_HANDLER', 'PACKAGE_MAX_LENGTH', 'MODE', 'BUFFER_OUTPUT_SIZE'] as $key) {
                putenv("SWOOLE_{$key}");
            }
        }

        $this->assertSame(0, $s['max_request']);                 // .env wins over the config's default
        $this->assertSame(3, $s['worker_num']);                  // short names
        $this->assertTrue($s['enable_static_handler']);
        $this->assertSame(16777216, $s['package_max_length']);   // any setting, typed
        $this->assertArrayNotHasKey('buffer_output_size', $s);   // blank: left out
        $this->assertArrayNotHasKey('workers', $s);
        $this->assertSame(2, $server->mode());
    }
}

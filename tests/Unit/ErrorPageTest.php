<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pype\Application;
use Pype\Exceptions\HttpException;
use Pype\Log\LogProvider;
use Pype\Testing\TestClient;

final class ErrorPageTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pype-err-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->base . '/storage/logs/*') ?: []);
        @rmdir($this->base . '/storage/logs');
        @rmdir($this->base . '/storage');
        @rmdir($this->base);
    }

    private function app(bool $debug): Application
    {
        $app = (new Application($this->base, ['app' => ['debug' => $debug], 'log' => ['viewer' => ['enabled' => true]]]))->register(LogProvider::class);
        $app->router->post('/orders/{id}', [
            fn (LoggerInterface $log, int $id) => $log->warning('Charging order {id}', ['id' => $id]) ?? throw new \RuntimeException('Payment gateway timed out'),
        ])->name('orders.pay');
        $app->router->get('/missing', fn () => throw HttpException::notFound('No such thing'));

        return $app;
    }

    // Secrets are built from pieces: the page shows source code around each frame, including
    // this file's, and must only hide the *request's* secrets.
    private static function browser(): array
    {
        return ['Accept' => 'text/html,application/xhtml+xml', 'Authorization' => 'Bearer ' . 's3cr3t' . '-token', 'Cookie' => 'pype_session=' . 'abc' . '123'];
    }

    public function test_debug_page_for_browsers(): void
    {
        $res = (new TestClient($this->app(true)))->post('/orders/42', ['card' => str_repeat('41', 8), 'password' => 'hun' . 'ter2', 'note' => 'gift'], self::browser());
        $html = $res->body();

        $this->assertSame(500, $res->status());
        $this->assertStringContainsString('text/html', (string) $res->header('Content-Type'));
        $this->assertStringContainsString('Payment gateway timed out', $html);
        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('orders.pay', $html, 'the route');
        $this->assertStringContainsString('Charging order 42', $html, 'log lines of this request');
        $this->assertStringContainsString('/_pype/logs?request=', $html, 'link into the viewer');
        $this->assertStringContainsString('ErrorPageTest.php', $html, 'trace with source');
        $this->assertStringContainsString('gift', $html);

        foreach (['s3cr3t' . '-token', 'abc' . '123', 'hun' . 'ter2', str_repeat('41', 8)] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "{$secret} must be hidden");
        }
    }

    public function test_api_clients_and_production_get_json(): void
    {
        $api = (new TestClient($this->app(true)))->post('/orders/42', [], ['Accept' => 'application/json']);
        $this->assertSame(500, $api->status());
        $this->assertSame('Payment gateway timed out', $api->json('error.message'));

        $prod = (new TestClient($this->app(false)))->post('/orders/42', [], self::browser());
        $this->assertSame(500, $prod->status());
        $this->assertStringNotContainsString('Payment gateway', $prod->body());
    }

    public function test_expected_http_errors_are_not_debug_pages(): void
    {
        $res = (new TestClient($this->app(true)))->get('/missing', self::browser());

        $this->assertSame(404, $res->status());
        $this->assertSame('No such thing', $res->json('error.message'));
    }

    public function test_route_outlets_do_not_replace_the_debug_page(): void
    {
        $app = $this->app(true);
        $app->router->get('/page', [fn () => throw new \LogicException('template data broke'), new \Pype\Outlet\HtmlOutlet()]);

        $html = (new TestClient($app))->get('/page', self::browser())->body();
        $this->assertStringContainsString('template data broke', $html);
        $this->assertStringContainsString('LogicException', $html);
    }
}

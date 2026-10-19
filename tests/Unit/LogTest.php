<?php

declare(strict_types=1);

namespace Pype\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pype\Application;
use Pype\Http\Request;
use Pype\Log\LogProvider;
use Pype\Log\LogReader;
use Pype\Log\Logger;
use Pype\Stages\RequestId;
use Pype\Testing\TestClient;

final class LogTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pype-log-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/storage/logs/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->base . '/storage/logs');
        @rmdir($this->base . '/storage');
        @rmdir($this->base);
    }

    private function app(array $log = [], bool $debug = true): Application
    {
        return (new Application($this->base, ['app' => ['debug' => $debug], 'log' => $log + ['requests' => true, 'viewer' => ['enabled' => true]]]))
            ->register(LogProvider::class);
    }

    /** @return list<array> */
    private function lines(): array
    {
        $file = $this->base . '/storage/logs/pype-' . date('Y-m-d') . '.log';

        return is_file($file) ? array_map(static fn ($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES)) : [];
    }

    public function test_json_lines_with_levels_placeholders_and_exceptions(): void
    {
        $log = new Logger($this->base . '/storage/logs', 'info');
        $log->debug('not written');
        $log->info('Card declined for {user}', ['user' => 5, 'ok' => false]);
        $log->error('Boom', ['exception' => new \RuntimeException('inner', 3, new \LogicException('cause'))]);

        $lines = $this->lines();
        $this->assertCount(2, $lines);
        $this->assertSame('info', $lines[0]['level']);
        $this->assertSame('Card declined for 5', $lines[0]['message']);
        $this->assertSame(['user' => 5, 'ok' => false], $lines[0]['context']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/', $lines[0]['time']);
        $this->assertSame('RuntimeException', $lines[1]['context']['exception']['class']);
        $this->assertSame('cause', $lines[1]['context']['exception']['previous']['message']);
    }

    public function test_old_files_are_pruned(): void
    {
        $dir = $this->base . '/storage/logs';
        mkdir($dir, 0775, true);
        touch($dir . '/pype-2020-01-01.log');
        touch($dir . '/pype-' . date('Y-m-d', strtotime('-3 days')) . '.log');

        (new Logger($dir, days: 14))->info('x');

        $this->assertFileDoesNotExist($dir . '/pype-2020-01-01.log');
        $this->assertFileExists($dir . '/pype-' . date('Y-m-d', strtotime('-3 days')) . '.log');
    }

    public function test_requests_are_logged_with_one_shared_request_id(): void
    {
        $app = $this->app();
        $app->through(new RequestId());
        $app->router->get('/things/{id}', fn (int $id, LoggerInterface $log) => $log->info('loading {id}', ['id' => $id]) ?? ['id' => $id])->name('things.show');

        $res = (new TestClient($app))->get('/things/4');
        $lines = $this->lines();

        $this->assertSame(['loading 4', 'GET /things/4 200'], [$lines[0]['message'], substr($lines[1]['message'], 0, 17)]);
        $this->assertSame('things.show', $lines[1]['context']['route']);
        $this->assertSame($res->header('X-Request-Id'), $lines[0]['request_id']);
        $this->assertSame($lines[0]['request_id'], $lines[1]['request_id']);
    }

    public function test_unexpected_errors_are_logged(): void
    {
        $app = $this->app();
        $app->router->get('/boom', fn () => throw new \RuntimeException('kaboom'));

        $this->assertSame(500, (new TestClient($app))->get('/boom')->status());
        $error = array_values(array_filter($this->lines(), fn ($l) => $l['message'] === 'kaboom'))[0];
        $this->assertSame('error', $error['level']);
        $this->assertSame('/boom', $error['context']['path']);
    }

    public function test_polling_reads_only_new_complete_lines(): void
    {
        $dir = $this->base . '/storage/logs';
        $log = new Logger($dir);
        $reader = new LogReader($dir);
        $file = $reader->today();

        $log->info('one');
        $first = $reader->tail($file);
        $this->assertSame(['one'], array_column($first['entries'], 'message'));

        $this->assertSame([], $reader->after($file, $first['next'])['entries'], 'nothing new');

        $log->info('two');
        $log->warning('three');
        file_put_contents("{$dir}/{$file}", '{"level":"info","mess', FILE_APPEND);   // being written
        $next = $reader->after($file, $first['next']);
        $this->assertSame(['two', 'three'], array_column($next['entries'], 'message'));

        file_put_contents("{$dir}/{$file}", "age\":\"four\"}\n", FILE_APPEND);
        $this->assertSame(['four'], array_column($reader->after($file, $next['next'])['entries'], 'message'));

        $this->assertSame(['three'], array_column($reader->tail($file, filters: ['level' => 'warning'])['entries'], 'message'));
        $this->assertSame(['two'], array_column($reader->tail($file, filters: ['q' => 'TWO'])['entries'], 'message'));

        file_put_contents("{$dir}/{$file}", '');   // cleared
        $this->assertTrue($reader->after($file, $next['next'])['reset']);

        $this->expectException(\InvalidArgumentException::class);
        $reader->tail('../../etc/passwd');
    }

    public function test_viewer_pages_and_entries(): void
    {
        $app = $this->app();
        $app->router->get('/hello', fn (LoggerInterface $log) => $log->notice('hi there') ?? 'ok');
        $client = new TestClient($app);
        $client->get('/hello');

        $page = $client->get('/_pype/logs');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('text/html', (string) $page->header('Content-Type'));
        $this->assertSame('no-store', $page->header('Cache-Control'));
        $this->assertStringContainsString('/_pype/logs/entries', $page->body());

        $entries = $client->get('/_pype/logs/entries?level=notice');
        $this->assertSame(['hi there'], array_column($entries->json('data.entries'), 'message'));
        $next = $entries->json('data.next');

        $client->get('/hello');
        $this->assertSame(['hi there'], array_column($client->get("/_pype/logs/entries?level=notice&after={$next}")->json('data.entries'), 'message'));

        // The viewer's own requests are never logged.
        $this->assertSame([], array_filter($this->lines(), fn ($l) => str_contains($l['message'], '/_pype/logs')));
        $this->assertSame(400, $client->get('/_pype/logs/entries?file=../secret')->status());
    }

    public function test_viewer_is_not_public_in_production(): void
    {
        $prod = $this->app(debug: false);
        $this->assertSame(404, (new TestClient($prod))->get('/_pype/logs')->status(), 'enabled but unguarded: not registered');

        $guarded = $this->app(['viewer' => ['enabled' => true, 'stages' => [fn () => \Pype\Http\Response::error(403, 'Admins only')]]], debug: false);
        $this->assertSame(403, (new TestClient($guarded))->get('/_pype/logs')->status(), 'guarded by the app\'s stages');

        $off = $this->app(['viewer' => ['enabled' => false]]);
        $this->assertSame(404, (new TestClient($off))->get('/_pype/logs')->status());
    }

    public function test_entries_are_forwarded_with_the_request_id(): void
    {
        $recorder = new RecordingLogger();
        $app = $this->app(['forward' => [RecordingLogger::class, BrokenLogger::class], 'forward_level' => 'info']);
        $app->container->instance(RecordingLogger::class, $recorder);
        $app->router->get('/pay', function (LoggerInterface $log) {
            $log->debug('not forwarded');
            $log->warning('Card declined for {user}', ['user' => 5]);

            return 'ok';
        });

        $previous = ini_set('error_log', '/dev/null');   // BrokenLogger's failure is reported there
        $res = (new TestClient($app))->get('/pay');
        ini_set('error_log', (string) $previous);
        $this->assertSame(200, $res->status(), 'a failing forward logger never breaks the request');

        $this->assertSame(['warning', 'info'], array_column($recorder->records, 'level'), 'debug is below forward_level; the request line is forwarded too');
        $this->assertSame('Card declined for {user}', $recorder->records[0]['message'], 'PSR-3 loggers get the raw message and context');
        $this->assertSame(5, $recorder->records[0]['context']['user']);
        $this->assertSame($this->lines()[0]['request_id'], $recorder->records[0]['context']['request_id']);
        $this->assertCount(3, $this->lines(), 'the file still gets everything');
    }

    public function test_stream_and_syslog_destinations(): void
    {
        $file = $this->base . '/stream.log';
        (new \Pype\Log\StreamLogger($file))->error('Disk {disk} full', ['disk' => 'sda', 'request_id' => 'abc12345']);
        $line = json_decode(trim((string) file_get_contents($file)), true);
        unlink($file);

        $this->assertSame('error', $line['level']);
        $this->assertSame('Disk sda full', $line['message']);
        $this->assertSame('abc12345', $line['request_id']);
        $this->assertSame(['disk' => 'sda'], $line['context']);

        $app = $this->app(['forward' => ['stderr', 'syslog']]);
        $forward = (new \ReflectionProperty(Logger::class, 'forward'))->getValue($app->container->get(Logger::class));
        $this->assertInstanceOf(\Pype\Log\StreamLogger::class, $forward[0]);
        $this->assertInstanceOf(\Pype\Log\SyslogLogger::class, $forward[1]);
    }
}

final class RecordingLogger extends \Psr\Log\AbstractLogger
{
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

final class BrokenLogger extends \Psr\Log\AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('log service is down');
    }
}

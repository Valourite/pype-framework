<?php

declare(strict_types=1);

namespace Pype\Debug;

use Pype\Application;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Log\LogReader;
use Pype\Log\LogViewer;
use Pype\Outlet\Outlet;
use Pype\Routing\Route;
use Pype\Routing\Router;
use Pype\Routing\Urls;
use Pype\Stages\RequestIdValue;
use Throwable;

/**
 * The development error page (APP_DEBUG=true, browsers only): the exception with source around
 * each frame, the route and its stages, the request (secrets hidden), what the stages put in the
 * context, and the log lines this request wrote, with a link into the log viewer.
 *
 * Never used in production: Application only picks it when app.debug is on.
 */
final class ErrorPage extends Outlet
{
    private const SECRET_HEADERS = ['authorization', 'cookie', 'set-cookie', 'x-api-key', 'proxy-authorization'];
    private const SECRET_FIELDS = '/pass|token|secret|key|card|cvv|cvc|pin|signature/i';

    public function __construct(private readonly Throwable $exception, private readonly Application $app)
    {
    }

    public function render(Response $response, Request $request): string
    {
        $requestId = RequestIdValue::for($request)->value;
        $route = $request->context()->find(Route::class);

        return LogViewer::render(__DIR__ . '/views/error.php', [
            'exceptions' => $this->chain($this->exception),
            'request' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'query' => $request->query(),
                'input' => self::redact($request->method() === 'GET' ? [] : (array) $request->input()),
                'headers' => self::headers($request),
                'cookies' => array_keys($request->cookies()),
                'ip' => $request->ip(),
            ],
            'route' => $route === null ? null : [
                'method' => $route->method(),
                'path' => $route->path(),
                'name' => $route->name(),
                'params' => $request->params(),
                'stages' => array_map(Router::describe(...), $this->app->router->compile($route->stages())),
            ],
            'context' => $request->context()->classes(),
            'requestId' => $requestId,
            'logs' => $this->logs($requestId),
            'viewerUrl' => $this->viewerUrl($requestId),
            'basePath' => $this->app->basePath,
        ]);
    }

    public function contentType(Response $response): string
    {
        return 'text/html; charset=utf-8';
    }

    /** @return list<array{class: string, message: string, file: string, line: int, frames: list<array>}> */
    private function chain(Throwable $e): array
    {
        $out = [];
        for ($i = 0; $e !== null && $i < 5; $i++, $e = $e->getPrevious()) {
            $frames = [['file' => $e->getFile(), 'line' => $e->getLine(), 'call' => '']];
            foreach ($e->getTrace() as $frame) {
                $frames[count($frames) - 1]['call'] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
                $frames[] = ['file' => $frame['file'] ?? '', 'line' => $frame['line'] ?? 0, 'call' => ''];
            }
            $frames = array_values(array_filter($frames, static fn ($f) => $f['file'] !== ''));

            $framework = dirname(__DIR__, 2) . '/';   // pype itself, however it's installed (vendor/ or a linked checkout)
            foreach ($frames as &$frame) {
                $frame['vendor'] = str_contains($frame['file'], '/vendor/') || str_starts_with($frame['file'], $framework);
                $frame['source'] = self::source($frame['file'], $frame['line']);
            }
            unset($frame);

            $out[] = ['class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'frames' => $frames];
        }

        return $out;
    }

    /** @return array<int, string> line number => code, ±7 lines around $line */
    private static function source(string $file, int $line): array
    {
        if ($line < 1 || !is_file($file) || !is_readable($file) || filesize($file) > 2_000_000) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $from = max(1, $line - 7);
        $out = [];
        for ($n = $from; $n <= min(count($lines), $line + 7); $n++) {
            $out[$n] = $lines[$n - 1];
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function headers(Request $request): array
    {
        $out = [];
        foreach ($request->headers() as $name => $value) {
            $out[$name] = in_array(strtolower($name), self::SECRET_HEADERS, true) ? '•••• hidden' : $value;
        }
        ksort($out);

        return $out;
    }

    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_string($key) && preg_match(self::SECRET_FIELDS, $key)) {
                $data[$key] = '•••• hidden';
            }
        }

        return $data;
    }

    /** @return list<array> the log lines this request wrote (empty when logging isn't set up) */
    private function logs(string $requestId): array
    {
        try {
            return $this->app->container->get(LogReader::class)->forRequest($requestId);
        } catch (Throwable) {
            return [];
        }
    }

    private function viewerUrl(string $requestId): ?string
    {
        try {
            return $this->app->router->named('pype.logs') === null ? null : $this->app->container->get(Urls::class)->to('pype.logs', ['request' => $requestId]);
        } catch (Throwable) {
            return null;
        }
    }
}

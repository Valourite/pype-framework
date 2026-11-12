<?php

declare(strict_types=1);

namespace Pype\Debug;

use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Log\LogViewer;
use Pype\Routing\Router;
use Pype\Routing\Urls;

/** Renders the development screens (plain-PHP templates in views/). */
final class DebugPages
{
    public function __construct(
        private readonly RouteExplorer $explorer,
        private readonly Router $router,
        private readonly Urls $urls,
        private readonly ?RequestLog $requests = null,
        private readonly ?Sandbox $sandbox = null,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->page('index', ['count' => count($this->explorer->routes())]);
    }

    public function routes(Request $request): Response
    {
        return $this->page('routes', ['routes' => $this->explorer->routes(), 'q' => (string) $request->query('q', '')]);
    }

    public function route(Request $request): Response
    {
        $route = $this->explorer->route((string) $request->query('m', 'GET'), (string) $request->query('p', ''));

        return $route === null
            ? Response::error(404, 'No such route.')
            : $this->page('route', ['route' => $route]);
    }

    public function requests(Request $request): Response
    {
        return $this->page('requests', ['requests' => $this->requests?->all() ?? []]);
    }

    public function request(Request $request): Response
    {
        $record = $this->requests?->find((string) $request->query('id', ''));

        return $record === null
            ? Response::error(404, 'No such request: only the newest are kept.')
            : $this->page('request', ['record' => $record]);
    }

    public function sandbox(Request $request): Response
    {
        return $this->sandboxPage([
            'method' => strtoupper((string) $request->query('m', 'GET')),
            'path' => (string) $request->query('p', '/'),
        ]);
    }

    /** POST /_pype/sandbox: action = build | reset | run. */
    public function sandboxAction(Request $request): Response
    {
        $form = [
            'method' => strtoupper((string) $request->input('method', 'GET')),
            'path' => (string) $request->input('path', '/'),
            'params' => (array) $request->input('params', []),
            'body' => (string) $request->input('body', ''),
            'headers' => (string) $request->input('headers', ''),
            'user' => (string) $request->input('user', ''),
            'carry' => (bool) $request->input('carry', false),
        ];

        try {
            return match ((string) $request->input('action', 'run')) {
                'build' => $this->sandboxPage($form, message: $this->sandbox->build()),
                'reset' => $this->sandboxPage($form, message: $this->forget()),
                default => $this->sandboxPage($form, result: $this->sandbox->run(self::spec($form), $form['carry'])),
            };
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->sandboxPage($form, error: $e->getMessage());
        }
    }

    private function forget(): string
    {
        $this->sandbox->reset();

        return 'Back to how it was built.';
    }

    /**
     * The form as the request to send: {params} filled in, the body from JSON or field=value
     * lines, headers from Name: value lines.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private static function spec(array $form): array
    {
        $uri = (string) preg_replace_callback('#\{(\w+)\}#', static function (array $m) use ($form): string {
            $value = trim((string) ($form['params'][$m[1]] ?? ''));

            return $value === '' ? throw new \InvalidArgumentException("Give a value for {{$m[1]}}.") : rawurlencode($value);
        }, $form['path']);

        if (!str_starts_with($uri, '/')) {
            throw new \InvalidArgumentException('The path starts with a slash: /quotes/1.');
        }

        $text = trim($form['body']);
        $json = $text !== '' && in_array($text[0], ['{', '['], true);

        if ($json) {
            $body = json_decode($text, true);

            if (!is_array($body)) {
                throw new \InvalidArgumentException('The body looks like JSON but isn\'t: ' . json_last_error_msg() . '.');
            }
        } else {
            // One field=value per line; a[b]=c and a[]=c work as in a form.
            parse_str(implode('&', array_map(static function (string $line): string {
                [$name, $value] = array_pad(explode('=', $line, 2), 2, '');

                return rawurlencode(trim($name)) . '=' . rawurlencode(trim($value));
            }, array_filter(array_map('trim', explode("\n", $text)), static fn (string $l) => $l !== ''))), $body);
            $body = array_combine(array_map('rawurldecode', array_keys($body)), $body);
        }

        $headers = [];
        foreach (array_filter(array_map('trim', explode("\n", $form['headers']))) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[trim($name)] = trim($value);
        }

        return [
            'method' => $form['method'],
            'uri' => $uri,
            'body' => $body,
            'json' => $json,
            'headers' => $headers,
            'user' => $form['user'] === '' ? null : (int) $form['user'],
        ];
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $result
     */
    private function sandboxPage(array $form, ?array $result = null, ?string $message = null, ?string $error = null): Response
    {
        $form += ['params' => [], 'body' => '', 'headers' => '', 'user' => '', 'carry' => false];
        preg_match_all('#\{(\w+)\}#', $form['path'], $found);
        $suggest = [];

        // Ids to offer for each {param}: /quotes/{id} looks in quotes, {quote} in quotes too.
        foreach ($found[1] as $param) {
            preg_match('#/([\w-]+)/\{' . $param . '\}#', $form['path'], $before);
            $tables = array_unique(array_filter([$param . 's', $param, str_replace('-', '_', $before[1] ?? '')]));

            foreach ($tables as $table) {
                if (($ids = $this->sandbox->ids($table)) !== []) {
                    $suggest[$param] = ['table' => $table, 'ids' => $ids];
                    break;
                }
            }
        }

        return $this->page('sandbox', [
            'status' => $this->sandbox->status(),
            'form' => $form,
            'params' => $found[1],
            'suggest' => $suggest,
            'users' => $this->sandbox->users(),
            'routes' => array_map(static fn (array $r) => $r['method'] . ' ' . $r['path'], $this->explorer->routes()),
            'result' => $result,
            'message' => $message,
            'error' => $error,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $html = LogViewer::render(__DIR__ . "/views/{$view}.php", $data + ['links' => $this->links()]);

        return Response::make($html, 200, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /**
     * The screens that are registered in this app, for the bar at the top.
     *
     * @return array<string, string> label => address
     */
    private function links(): array
    {
        $links = [];

        foreach (['pype' => 'pype.debug', 'Routes' => 'pype.routes', 'Requests' => 'pype.requests', 'Sandbox' => 'pype.sandbox', 'Logs' => 'pype.logs', 'Mail' => 'pype.mail'] as $label => $name) {
            if ($this->router->named($name) !== null) {
                $links[$label] = $this->urls->to($name);
            }
        }

        return $links;
    }
}

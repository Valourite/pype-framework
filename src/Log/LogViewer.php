<?php

declare(strict_types=1);

namespace Pype\Log;

use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Routing\Urls;

/**
 * The log viewer's two endpoints: the page, and `entries`, which the page polls about once a
 * second for lines written after the byte offset it has seen. Registered by LogProvider.
 */
final class LogViewer
{
    /** @param array<string, string> $sources other log folders to offer: label => directory (log.viewer.sources) */
    public function __construct(private readonly LogReader $reader, private readonly array $sources = [])
    {
    }

    /** The reader for ?source=: one of the configured folders by its label, else the app's own log. */
    private function reader(Request $request): LogReader
    {
        $source = (string) $request->query('source', '');

        return isset($this->sources[$source]) ? new LogReader($this->sources[$source]) : $this->reader;
    }

    public function page(Request $request, Urls $urls): Response
    {
        $reader = $this->reader($request);
        $source = (string) $request->query('source', '');

        $html = self::render(__DIR__ . '/views/viewer.php', [
            'entriesUrl' => $urls->to('pype.logs.entries'),
            'files' => $reader->files(),
            'today' => $reader->today(),
            'request' => (string) $request->query('request', ''),
            'sources' => array_keys($this->sources),
            'source' => isset($this->sources[$source]) ? $source : '',
        ]);

        return Response::make($html, 200, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /** ?file=pype-2026-10-19.log &after=<offset> (live) or &before=<offset> (older) &level= &request= &q= &limit= */
    public function entries(Request $request): Response
    {
        $reader = $this->reader($request);
        $file = (string) $request->query('file', $reader->today());
        $filters = array_filter([
            'level' => (string) $request->query('level', ''),
            'request' => (string) $request->query('request', ''),
            'q' => (string) $request->query('q', ''),
        ], static fn ($v) => $v !== '');

        try {
            $result = $request->query('after') !== null
                ? $reader->after($file, (int) $request->query('after'), $filters)
                : $reader->tail($file, min(1000, max(1, (int) $request->query('limit', 200))), $filters, $request->query('before') === null ? null : (int) $request->query('before'));
        } catch (\InvalidArgumentException) {
            return Response::error(400, 'Unknown log file.');
        }

        return Response::make([...$result, 'today' => $reader->today()], 200, ['Cache-Control' => 'no-store']);
    }

    /** Render a framework template (plain PHP) to a string. */
    public static function render(string $template, array $data): string
    {
        ob_start();
        try {
            (static function (string $__template, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__template;
            })($template, $data);

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}

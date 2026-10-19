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
    public function __construct(private readonly LogReader $reader)
    {
    }

    public function page(Request $request, Urls $urls): Response
    {
        $html = self::render(__DIR__ . '/views/viewer.php', [
            'entriesUrl' => $urls->to('pype.logs.entries'),
            'files' => $this->reader->files(),
            'today' => $this->reader->today(),
            'request' => (string) $request->query('request', ''),
        ]);

        return Response::make($html, 200, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /** ?file=pype-2026-10-19.log &after=<offset> (live) or &before=<offset> (older) &level= &request= &q= &limit= */
    public function entries(Request $request): Response
    {
        $file = (string) $request->query('file', $this->reader->today());
        $filters = array_filter([
            'level' => (string) $request->query('level', ''),
            'request' => (string) $request->query('request', ''),
            'q' => (string) $request->query('q', ''),
        ], static fn ($v) => $v !== '');

        try {
            $result = $request->query('after') !== null
                ? $this->reader->after($file, (int) $request->query('after'), $filters)
                : $this->reader->tail($file, min(1000, max(1, (int) $request->query('limit', 200))), $filters, $request->query('before') === null ? null : (int) $request->query('before'));
        } catch (\InvalidArgumentException) {
            return Response::error(400, 'Unknown log file.');
        }

        return Response::make([...$result, 'today' => $this->reader->today()], 200, ['Cache-Control' => 'no-store']);
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

<?php

declare(strict_types=1);

namespace Pype\Outlet;

use Pype\Http\Request;
use Pype\Http\Response;

/**
 * Sends a file from disk. Use Response::file() rather than this directly:
 *
 *   return Response::file($app->path('storage/receipts/' . $expense->receipt), 'receipt.pdf');
 *
 * The content type is detected from the file. Errors are sent as JSON.
 */
final class FileOutlet extends Outlet
{
    public function render(Response $response, Request $request): string
    {
        return $response->isError()
            ? (new JsonOutlet())->render($response, $request)
            : (string) file_get_contents($response->data());
    }

    public function contentType(Response $response): string
    {
        return $response->isError()
            ? 'application/json; charset=utf-8'
            : ((new \finfo(FILEINFO_MIME_TYPE))->file($response->data()) ?: 'application/octet-stream');
    }
}

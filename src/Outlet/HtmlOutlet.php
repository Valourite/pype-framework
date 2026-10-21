<?php

declare(strict_types=1);

namespace Pype\Outlet;

use Pype\Http\Request;
use Pype\Http\Response;

/**
 * Sends a string the handler already built as HTML, unchanged. Errors (arrays from
 * Response::error) fall back to a plain text message.
 *
 *   $router->get('/hello', [fn () => '<h1>Hello</h1>', HtmlOutlet::class]);
 */
class HtmlOutlet extends Outlet
{
    public function render(Response $response, Request $request): string
    {
        $data = $response->data();

        if (is_string($data)) {
            return $data;
        }

        $message = is_array($data) ? ($data['error']['message'] ?? 'Error') : (string) json_encode($data);

        return '<!doctype html><meta charset="utf-8"><p>' . htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    public function contentType(Response $response): string
    {
        return 'text/html; charset=utf-8';
    }
}

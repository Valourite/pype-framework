<?php

declare(strict_types=1);

namespace Pype\Format;

use JsonSerializable;
use Pype\Http\Request;
use Pype\Http\Response;

/**
 * Default formatter. Fixed envelope:
 *
 *   success: { "data": ..., "meta": {...}? }
 *   error:   { "error": { "status": 404, "message": "...", ... } }
 */
final class JsonFormatter implements Formatter
{
    public function format(Response $response, Request $request): string
    {
        if ($response->isError()) {
            $payload = ['error' => $response->errorDetails()];
        } else {
            $data = $response->data();
            $payload = ['data' => $data instanceof JsonSerializable ? $data->jsonSerialize() : $data];

            if ($response->meta() !== []) {
                $payload['meta'] = $response->meta();
            }
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function contentType(): string
    {
        return 'application/json; charset=utf-8';
    }
}

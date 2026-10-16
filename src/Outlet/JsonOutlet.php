<?php

declare(strict_types=1);

namespace Pype\Outlet;

use JsonSerializable;
use Pype\Http\Request;
use Pype\Http\Response;

/**
 * Default outlet. Fixed envelope:
 *
 *   success: { "data": ..., "meta": {...}? }
 *   error:   { "error": { "status": 404, "message": "...", ... } }
 *
 * Want a different JSON shape? Extend Outlet (or this class) and register it as the default
 * with $app->outlet(MyJson::class), or add it to a group/route.
 */
class JsonOutlet extends Outlet
{
    public function render(Response $response, Request $request): string
    {
        return json_encode($this->payload($response), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function contentType(Response $response): string
    {
        return 'application/json; charset=utf-8';
    }

    /** The structure that gets encoded. Override to change the envelope. */
    protected function payload(Response $response): array
    {
        if ($response->isError()) {
            return ['error' => $response->errorDetails()];
        }

        $data = $response->data();
        $payload = ['data' => $data instanceof JsonSerializable ? $data->jsonSerialize() : $data];

        if ($response->meta() !== []) {
            $payload['meta'] = $response->meta();
        }

        return $payload;
    }
}

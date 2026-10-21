<?php

declare(strict_types=1);

namespace Pype\Outlet;

use Closure;
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
 *
 * Extend it to decide what one route answers with, the way a page outlet does for HTML: data()
 * reads what the stages loaded and returns what goes in "data" (a 204 becomes a 200 then):
 *
 *   #[Requires(User::class)]
 *   final class UserJson extends JsonOutlet
 *   {
 *       protected function data(mixed $data, Request $request): mixed
 *       {
 *           $user = $request->context()->get(User::class);
 *           return ['id' => $user->id, 'name' => $user->name];   // only what clients may see
 *       }
 *   }
 *
 *   $router->get('/users/{id}', [FindUser::class, UserJson::class]);
 */
class JsonOutlet extends Outlet
{
    /**
     * What goes in "data", from the pipeline's result ($data) and the request (its context).
     * The default passes the result through.
     */
    protected function data(mixed $data, Request $request): mixed
    {
        return $data;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $response = parent::handle($request, $next);

        if ($response->outlet() !== $this || $response->isError() || $response->isRedirect()) {
            return $response;
        }

        $data = $this->data($response->data(), $request);

        if ($data === $response->data()) {
            return $response;
        }

        // An outlet that answers from the context: the stages returned nothing, the outlet has data.
        return $response->withData($data)->withStatus($response->status() === 204 && $data !== null ? 200 : $response->status());
    }

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

<?php

declare(strict_types=1);

namespace Pype\Outlet;

use Closure;
use JsonSerializable;
use Pype\Http\Request;
use Pype\Http\Responsable;
use Pype\Http\Response;
use Pype\Pipeline\WithArguments;

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
 *
 * A list: return the Page (or SimplePage), mapped to what clients see. Its items become "data"
 * and its numbers and links "meta.pagination", as when a stage returns the page itself:
 *
 *   return $request->context()->get(Page::class)->map(fn (Ticket $t) => ['id' => $t->id, ...]);
 *
 * The status on success: `JsonOutlet::with(status: 201)` (or `TicketJson::with(status: 201)`), or
 * set `protected ?int $status = 201;` in a subclass. Errors and redirects keep theirs.
 */
class JsonOutlet extends Outlet
{
    use WithArguments;

    /** The status of a successful answer; null keeps the pipeline's (200, or 204 for nothing). */
    protected ?int $status = null;

    public function __construct(?int $status = null)
    {
        $this->status = $status ?? $this->status;
    }

    /**
     * What goes in "data", from the pipeline's result ($data) and the request (its context).
     * The default passes the result through. A Responsable (a Page) gives data and meta.
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

        if ($data instanceof Responsable) {
            $shaped = $data->toResponse();
            $response = $response->withData($shaped->data())->withMeta($shaped->meta())->withStatus($response->status() === 204 ? 200 : $response->status());
        } elseif ($data !== $response->data()) {
            // An outlet that answers from the context: the stages returned nothing, the outlet has data.
            $response = $response->withData($data)->withStatus($response->status() === 204 && $data !== null ? 200 : $response->status());
        }

        return $this->status === null ? $response : $response->withStatus($this->status);
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

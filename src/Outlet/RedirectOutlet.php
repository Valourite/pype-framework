<?php

declare(strict_types=1);

namespace Pype\Outlet;

use Closure;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\WithArguments;
use Pype\Routing\Urls;
use Pype\Session\FlashOnSuccess;

/**
 * Ends a route with a redirect when it succeeds, the way View ends one with a page:
 *
 *   $router->post('/quotes/{id}/accept', [LoadQuote::class, AcceptQuote::class, new RedirectOutlet('quotes.show', flash: 'Quote accepted.')]);
 *   $router->post('/quotes', [..., CreateQuote::class, new RedirectOutlet('quotes.show', fn (Quote $quote) => ['id' => $quote->id])]);
 *
 * $to is a route name (or a path). Its {placeholders} come from $params, then from the matched
 * route's params, so {id} carries over by itself. $params may be a closure over what the route
 * returned. A string $flash is the 'status' message.
 *
 * Only success redirects: errors pass through (the 'web' stack sends validation errors back to
 * the form), a response that is already a redirect keeps its target (a stage's redirect()), and
 * JSON clients get the data, so one route serves forms and the API.
 */
final class RedirectOutlet extends Outlet
{
    use WithArguments;

    /** @var array<string, mixed> */
    private readonly array $flash;

    /**
     * @param array<string, mixed>|Closure(mixed $data, Request $request): array<string, mixed> $params
     * @param string|array<string, mixed> $flash
     */
    public function __construct(
        private readonly string $to,
        private readonly array|Closure $params = [],
        string|array $flash = [],
        private readonly int $status = 303,
    ) {
        $this->flash = is_string($flash) ? ['status' => $flash] : $flash;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        // This wraps every other stage, the session included, so the flash is left for
        // StartSession to apply on the way out (FlashOnSuccess uses the same success rule).
        $pending = new FlashOnSuccess($this->flash);
        if ($this->flash !== []) {
            $request->context()->put($pending);
        }

        $response = Response::from($next($request));

        if ($response->outlet() !== null || !$pending->appliesTo($response, $request)) {
            return $response;
        }

        $params = $this->params instanceof Closure ? ($this->params)($response->data(), $request) : $this->params;
        $redirect = Response::redirect(Urls::current()->target($this->to, $params, $request->params()), $this->status);

        foreach ($response->cookies() as $cookie) {
            $redirect = $redirect->withCookie($cookie);   // the session cookie, remember-me, ...
        }

        return $redirect->withOutlet($this);
    }

    public function render(Response $response, Request $request): string
    {
        return $response->isError() ? (string) $response->errorDetails()['message'] : '';
    }

    public function contentType(Response $response): string
    {
        return 'text/plain; charset=utf-8';
    }
}

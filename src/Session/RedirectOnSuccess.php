<?php

declare(strict_types=1);

namespace Pype\Session;

use Closure;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Stage;

/**
 * Lets a browser form reuse an API handler: when the rest of the pipeline succeeds, answer
 * with a redirect (and optionally a flashed status message) instead of the data.
 *
 *   $router->post('/posts', ['web', new Validate([...]), new RedirectOnSuccess('/posts', 'Post created.'), CreatePost::class]);
 *   new RedirectOnSuccess(fn (Response $r) => '/posts/' . $r->data()->id)
 *
 * Errors pass through untouched ('web' sends validation errors back to the form), and JSON
 * clients still get the data, so one route can serve both.
 */
final class RedirectOnSuccess implements Stage
{
    /**
     * @param string|Closure(Response, Request): string $to
     * @param string|null $status  flashed as 'status'; read it in templates with $this->session()->get('status')
     */
    public function __construct(
        private readonly string|Closure $to,
        private readonly ?string $status = null,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $response = Response::from($next($request));

        if ($response->status() >= 300 || $request->expectsJson()) {
            return $response;
        }

        if ($this->status !== null) {
            $request->context()->find(Session::class)?->flash('status', $this->status);
        }

        return Response::redirect(is_string($this->to) ? $this->to : ($this->to)($response, $request), 303);
    }
}

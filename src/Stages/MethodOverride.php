<?php

declare(strict_types=1);

namespace Pype\Stages;

use Closure;
use Pype\Http\Request;
use Pype\Pipeline\Stage;

/**
 * HTML forms can only send GET and POST. This lets a POST form act as PUT, PATCH or DELETE
 * with a hidden `_method` field, so pages and the API can share the same routes and verbs:
 *
 *   <form method="post" action="/posts/5"><?= $this->method('DELETE') ?> ...</form>
 *
 * It must run before routing, so register it as a global stage:
 *
 *   $app->through(new MethodOverride());
 */
final class MethodOverride implements Stage
{
    private const ALLOWED = ['PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->method() === 'POST') {
            $method = $request->input('_method');

            if (is_string($method) && in_array($method = strtoupper($method), self::ALLOWED, true)) {
                return $next($request->withMethod($method));
            }
        }

        return $next($request);
    }
}

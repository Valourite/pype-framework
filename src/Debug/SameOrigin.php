<?php

declare(strict_types=1);

namespace Pype\Debug;

use Closure;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Stage;

/**
 * Lets a form post through only when it came from this site: the development screens have no
 * session to hold a CSRF token, and another site open in the same browser must not be able to
 * press their buttons. Browsers say where a form came from (Sec-Fetch-Site, else Origin).
 */
final class SameOrigin implements Stage
{
    public function handle(Request $request, Closure $next): mixed
    {
        $site = $request->header('sec-fetch-site');
        $origin = $request->header('origin');

        $same = match (true) {
            $site !== null => in_array(strtolower($site), ['same-origin', 'none'], true),
            $origin !== null => parse_url($origin, PHP_URL_HOST) === explode(':', (string) $request->header('host'))[0],
            default => true,   // not a browser (curl, a test): there is no other site to come from
        };

        return $same ? $next($request) : Response::error(403, 'This form can only be sent from the page itself.');
    }
}

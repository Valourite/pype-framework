<?php

declare(strict_types=1);

namespace Pype\Session;

use Closure;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;

/**
 * Turns a 422 validation error into "redirect back to the form, with errors and old input",
 * the way a browser form expects. JSON clients (Accept: application/json) still get the 422.
 *
 * Read them in a template with $this->error('email') and $this->old('email').
 */
final class FlashValidationErrors implements Stage
{
    use WithArguments;

    /** @param list<string> $dontFlash  input never put back into the form */
    public function __construct(
        private readonly string $fallback = '/',
        private readonly array $dontFlash = ['password', 'password_confirmation', 'current_password', '_token'],
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $response = Response::from($next($request));
        $session = $request->context()->find(Session::class);

        if ($response->status() !== 422 || $session === null || $request->expectsJson()) {
            return $response;
        }

        $input = $request->input();
        $session->flash('_errors', $response->errorDetails()['errors'] ?? []);
        $session->flash('_old', is_array($input) ? array_diff_key($input, array_flip($this->dontFlash)) : []);

        return Response::redirect($this->back($request), 303);
    }

    /** The Referer, if it points at this site; otherwise the fallback. Never an open redirect. */
    private function back(Request $request): string
    {
        $referer = (string) $request->header('referer');
        $parts = parse_url($referer);

        if ($referer === '' || $parts === false || ($parts['host'] ?? null) !== parse_url('//' . $request->header('host', ''), PHP_URL_HOST)) {
            return $this->fallback;
        }

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}

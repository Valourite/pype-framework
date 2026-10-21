<?php

declare(strict_types=1);

namespace Pype\Session;

use Closure;
use Pype\Exceptions\HttpException;
use Pype\Http\Request;
use Pype\Pipeline\Requires;
use Pype\Pipeline\Stage;
use Pype\Pipeline\WithArguments;

/**
 * Rejects state-changing requests (POST, PUT, PATCH, DELETE) unless they carry the session's
 * CSRF token, as a `_token` field or an X-CSRF-Token header. Must run after StartSession.
 *
 * In templates: <form method="post"><?= $this->csrf() ?> ... </form>
 *
 *   new VerifyCsrfToken(except: ['/webhooks/*'])
 */
#[Requires(Session::class)]
final class VerifyCsrfToken implements Stage
{
    use WithArguments;

    /** @param list<string> $except  paths to skip; * matches anything */
    public function __construct(private readonly array $except = [])
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->isSafe() || $this->isExcepted($request->path())) {
            return $next($request);
        }

        $session = $request->context()->find(Session::class)
            ?? throw new \LogicException('VerifyCsrfToken must run after StartSession.');

        $token = $request->input('_token') ?? $request->header('x-csrf-token');

        if (!is_string($token) || !hash_equals($session->token(), $token)) {
            throw (new HttpException(419, 'Page expired. Please refresh and try again.'))->withKey('csrf');
        }

        return $next($request);
    }

    private function isExcepted(string $path): bool
    {
        foreach ($this->except as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote('/' . trim($pattern, '/'), '#')) . '$#';

            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }
}

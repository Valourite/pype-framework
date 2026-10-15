<?php

declare(strict_types=1);

namespace Pype\Auth;

use Pype\Http\Request;
use Pype\Session\Session;

/**
 * Browser login: the session remembers the user's id. Needs StartSession earlier in the
 * pipeline (the 'web' stack); without a session it simply doesn't recognise anyone.
 *
 *   // in your login handler, after checking credentials however you like:
 *   $guard->login($session, $user);
 *   $guard->logout($session);
 */
final class SessionGuard implements Guard
{
    private const KEY = '_auth.user';
    private const INTENDED = '_auth.intended';

    public function __construct(private readonly Auth $auth)
    {
    }

    public function authenticate(Request $request): ?object
    {
        $session = $request->context()->find(Session::class);
        $id = $session?->get(self::KEY);

        if (!is_int($id)) {
            return null;
        }

        $user = $this->auth->findUser($id);

        if ($user === null) {
            $session->forget(self::KEY);   // user was deleted
        }

        return $user;
    }

    /** Log the user in for this session. A new session id and CSRF token stop fixation attacks. */
    public function login(Session $session, object|int $user): void
    {
        $session->regenerate()->regenerateToken();
        $session->put(self::KEY, is_int($user) ? $user : (int) $user->id);
    }

    public function logout(Session $session): void
    {
        $session->invalidate();
    }

    /** Remember where a guest was going, so login can send them back there. */
    public function setIntended(Session $session, string $url): void
    {
        $session->put(self::INTENDED, $url);
    }

    /** Where to go after login: the page the guest was sent away from, or $default. */
    public function intended(Session $session, string $default = '/'): string
    {
        $url = $session->pull(self::INTENDED);

        return is_string($url) && str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : $default;
    }
}

<?php

declare(strict_types=1);

namespace Pype\Auth;

use LogicException;
use Pype\Http\Cookie;
use Pype\Http\Request;
use Pype\Session\Session;

/**
 * Browser login: the session remembers the user's id. Needs StartSession earlier in the
 * pipeline (the 'web' stack); without a session it simply doesn't recognise anyone.
 *
 *   // in your login handler, after checking credentials however you like:
 *   $guard->login($session, $user);
 *   $guard->login($session, $user, remember: true, userAgent: $request->header('user-agent'));
 *   $guard->logout($session, $request);
 *
 * "Remember me" (auth.remember_table set): a long-lived cookie signs the user back in when
 * their session has ended. See RememberTokens for how it stays safe.
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

        if ($session === null) {
            return null;
        }

        $id = $session->get(self::KEY);

        if (!is_int($id)) {
            return $this->fromRememberCookie($request, $session);
        }

        $user = $this->auth->findUser($id);

        if ($user === null) {
            $session->forget(self::KEY);   // user was deleted
        }

        return $user;
    }

    /**
     * Log the user in for this session. A new session id and CSRF token stop fixation attacks.
     * With $remember, this device also gets a "remember me" cookie (auth.remember_days).
     */
    public function login(Session $session, object|int $user, bool $remember = false, ?string $userAgent = null): void
    {
        $id = is_int($user) ? $user : (int) $user->id;
        $session->regenerate()->regenerateToken();
        $session->put(self::KEY, $id);

        if ($remember) {
            $tokens = $this->auth->rememberTokens() ?? throw new LogicException('"Remember me" needs auth.remember_table (and its migration).');
            $session->queueCookie($this->rememberCookie($tokens->create($id, $userAgent)));
        }
    }

    /** Log out. Pass the request to also forget this device's "remember me" token. */
    public function logout(Session $session, ?Request $request = null): void
    {
        $tokens = $this->auth->rememberTokens();
        $cookie = $request?->cookie($this->auth->config()['remember_cookie']);

        if ($tokens !== null && $cookie !== null) {
            $tokens->revoke($cookie);
        }

        $session->invalidate();

        if ($tokens !== null) {
            $session->queueCookie(Cookie::forget($this->auth->config()['remember_cookie']));
        }
    }

    /** No session user: a valid "remember me" cookie signs them back in (and is swapped for a new one). */
    private function fromRememberCookie(Request $request, Session $session): ?object
    {
        $tokens = $this->auth->rememberTokens();
        $name = $this->auth->config()['remember_cookie'];
        $cookie = $tokens === null ? null : $request->cookie($name);

        if ($cookie === null) {
            return null;
        }

        $result = $tokens->consume($cookie);
        $user = $result === null ? null : $this->auth->findUser($result['user']);

        if ($user === null) {
            $session->queueCookie(Cookie::forget($name));   // expired, unknown or replayed: drop it

            return null;
        }

        $session->regenerate();
        $session->put(self::KEY, (int) $user->id);

        if ($result['cookie'] !== null) {
            $session->queueCookie($this->rememberCookie($result['cookie']));
        }

        return $user;
    }

    private function rememberCookie(string $value): Cookie
    {
        $config = $this->auth->config();

        return new Cookie($config['remember_cookie'], $value, maxAge: (int) $config['remember_days'] * 86400, secure: (bool) $config['remember_secure']);
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

<?php

declare(strict_types=1);

namespace Pype\Auth;

use Pype\Application;
use Pype\Database\Connection;
use Pype\Provider;

/**
 * Binds Auth from config/auth.php and registers the 'auth' stack (Authenticate with the
 * configured guards).
 * Requires DatabaseProvider.
 */
final class AuthProvider implements Provider
{
    public function register(Application $app): void
    {
        $app->container->singleton(Auth::class, fn ($c) => new Auth(
            $c->get(Connection::class),
            $app->config('auth', []),
        ));

        // Built only if a JwtGuard is listed in auth.guards (or something asks for Jwt), but a
        // config that can't work (a too-short secret) stops the app here, not on the first token.
        $jwt = (array) $app->config('auth.jwt', []);
        Jwt::checkConfig($jwt);
        $app->container->singleton(Jwt::class, fn () => new Jwt($jwt));
        $app->container->singleton(JwtGuard::class, fn ($c) => new JwtGuard($c->get(Jwt::class), fn () => $c->get(Auth::class)));

        $app->stack('auth', [Authenticate::class]);
    }
}

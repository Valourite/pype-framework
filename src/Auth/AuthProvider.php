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

        // Built only if a JwtGuard is listed in auth.guards (or something asks for Jwt).
        $app->container->singleton(Jwt::class, fn () => new Jwt((array) $app->config('auth.jwt', [])));
        $app->container->singleton(JwtGuard::class, fn ($c) => new JwtGuard($c->get(Jwt::class), fn () => $c->get(Auth::class)));

        $app->stack('auth', [Authenticate::class]);
    }
}

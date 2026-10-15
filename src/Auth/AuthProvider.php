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

        $app->stack('auth', [new Authenticate()]);
    }
}

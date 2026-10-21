<?php

declare(strict_types=1);

namespace Pype\Session;

use Pype\Application;
use Pype\Database\Connection;
use Pype\Provider;

/**
 * Binds the SessionStore from config/session.php and registers the 'web' stack:
 * StartSession → VerifyCsrfToken → FlashValidationErrors.
 */
final class SessionProvider implements Provider
{
    public function register(Application $app): void
    {
        $app->container->singleton(SessionStore::class, fn ($c) => match ($app->config('session.driver', 'database')) {
            'database' => new DatabaseSessionStore($c->get(Connection::class), $app->config('session.table', 'sessions')),
            'file' => new FileSessionStore($app->path($app->config('session.path', 'storage/sessions'))),
            'array' => new ArraySessionStore(),
            default => $c->get($app->config('session.driver')),   // a class name of your own store
        });

        // Specs, not instances: built only on routes that use 'web', fresh for each request.
        $app->stack('web', [
            StartSession::with(
                cookie: $app->config('session.cookie', 'pype_session'),
                lifetimeMinutes: (int) $app->config('session.lifetime', 120),
                secure: (bool) $app->config('session.secure', false),
                sameSite: $app->config('session.same_site', 'Lax'),
                domain: $app->config('session.domain'),
                touchSeconds: (int) $app->config('session.touch', 60),
            ),
            VerifyCsrfToken::with(except: $app->config('session.csrf_except', [])),
            FlashValidationErrors::class,
        ]);
    }
}

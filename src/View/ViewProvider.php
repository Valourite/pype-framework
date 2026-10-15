<?php

declare(strict_types=1);

namespace Pype\View;

use Pype\Application;
use Pype\Provider;

/** Binds the Views engine to the app's views directory (config app.views, default resources/views). */
final class ViewProvider implements Provider
{
    public function register(Application $app): void
    {
        $app->container->singleton(Views::class, fn () => new Views(
            $app->path($app->config('app.views', 'resources/views')),
        ));
    }
}

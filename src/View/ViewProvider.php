<?php

declare(strict_types=1);

namespace Pype\View;

use Pype\Application;
use Pype\Provider;

/**
 * Binds Views to the app's views directory (config app.views, default resources/views). Latte and
 * Blade templates compile into app.view_cache (default storage/views).
 */
final class ViewProvider implements Provider
{
    public function register(Application $app): void
    {
        $app->container->singleton(Views::class, fn () => new Views(
            $app->path($app->config('app.views', 'resources/views')),
            $app->path($app->config('app.view_cache', 'storage/views')),
        ));
    }
}

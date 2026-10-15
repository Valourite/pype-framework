<?php

declare(strict_types=1);

namespace Pype;

/**
 * Hook for apps and composer packages to plug into Pype: bind services,
 * register stage stacks, add global stages, set a formatter, add routes.
 *
 * List providers in config/app.php under 'providers'.
 */
interface Provider
{
    public function register(Application $app): void;
}

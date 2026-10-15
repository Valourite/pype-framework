<?php

declare(strict_types=1);

namespace Pype\View;

/**
 * Quick template outlet — no class needed:
 *
 *   $router->get('/', [fn () => ['name' => 'Ada'], new View('welcome')]);
 *
 * When a view needs its own logic, extend ViewOutlet instead.
 */
final class View extends ViewOutlet
{
    public function __construct(
        private readonly string $template,
        private readonly string $errorTemplate = 'errors/error',
    ) {
    }

    protected function template(): string
    {
        return $this->template;
    }

    protected function errorTemplate(): string
    {
        return $this->errorTemplate;
    }
}

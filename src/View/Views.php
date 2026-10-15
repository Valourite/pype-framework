<?php

declare(strict_types=1);

namespace Pype\View;

use InvalidArgumentException;

/**
 * Plain-PHP template engine. Templates live in resources/views as .php files:
 *
 *   resources/views/users/show.php   →   $views->render('users/show', ['user' => $user])
 *
 * Inside a template: <?= e($user->name) ?> to escape, $this->layout('layouts/app') to wrap,
 * <?= $this->partial('users/_card', ['user' => $u]) ?> to include.
 */
final class Views
{
    public function __construct(private readonly string $path)
    {
    }

    public function exists(string $name): bool
    {
        return is_file($this->file($name));
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = []): string
    {
        $file = $this->file($name);

        if (!is_file($file)) {
            throw new InvalidArgumentException("View [{$name}] not found at {$file}.");
        }

        return (new Template($this))->render($file, $data);
    }

    private function file(string $name): string
    {
        if (!preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $name)) {
            throw new InvalidArgumentException("Invalid view name [{$name}].");
        }

        return rtrim($this->path, '/') . '/' . $name . '.php';
    }
}

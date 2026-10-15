<?php

declare(strict_types=1);

namespace Pype\View;

use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Format\Formatter;
use Pype\Http\Request;
use Pype\Http\Response;

/**
 * Formatter that renders an HTML template. Put it at the end of a route's stages:
 *
 *   $router->get('/users/{id}', [FindUser::class, ShowUser::class, new View('users/show')]);
 *
 * The template receives:
 *   $data     whatever the pipeline returned
 *   ...keys   if $data is an associative array, each key as its own variable
 *   $meta, $request
 *
 * Errors render errors/{status}.php if present, else errors/error.php, else plain text.
 */
final class View implements Formatter, ContainerAware
{
    private ?Views $views = null;

    public function __construct(
        private readonly string $template,
        private readonly string $errorTemplate = 'errors/error',
    ) {
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->views = $container->get(Views::class);
    }

    public function format(Response $response, Request $request): string
    {
        $views = $this->views ?? throw new \LogicException('View formatter used without a container. Is ViewProvider registered?');

        if ($response->isError()) {
            return $this->renderError($views, $response, $request);
        }

        $data = $response->data();
        $vars = ['data' => $data, 'meta' => $response->meta(), 'request' => $request];

        if (is_array($data) && !array_is_list($data)) {
            $vars = [...$data, ...$vars];
        }

        return $views->render($this->template, $vars);
    }

    public function contentType(): string
    {
        return 'text/html; charset=utf-8';
    }

    private function renderError(Views $views, Response $response, Request $request): string
    {
        $error = $response->errorDetails();
        $status = $response->status();

        foreach (["errors/{$status}", $this->errorTemplate] as $name) {
            if ($views->exists($name)) {
                return $views->render($name, ['error' => $error, 'status' => $status, 'request' => $request]);
            }
        }

        return htmlspecialchars("{$status} — " . ($error['message'] ?? 'Error'), ENT_QUOTES);
    }
}

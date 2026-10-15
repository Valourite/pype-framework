<?php

declare(strict_types=1);

namespace Pype\View;

use Psr\Container\ContainerInterface;
use Pype\Container\ContainerAware;
use Pype\Http\Request;
use Pype\Http\Response;
use Pype\Outlet\Outlet;

/**
 * An outlet that renders an HTML template. Extend it for class-based views:
 *
 *   final class UserProfile extends ViewOutlet
 *   {
 *       public function __construct(private Connection $db) {}       // services injected
 *
 *       protected function template(): string { return 'users/profile'; }
 *
 *       protected function data(mixed $user, Request $request): array // shape data before rendering
 *       {
 *           return ['user' => $user, 'posts' => $this->db->table('posts')->where('user_id', $user->id)->get()];
 *       }
 *
 *       public function initials(): string { ... }                     // callable in the template as $view->initials()
 *   }
 *
 *   $router->get('/users/{id}', [FindUser::class, ShowUser::class, UserProfile::class]);
 *
 * Templates receive the variables from data(), plus $view (this object), $meta and $request.
 * Errors render errors/{status}.php if present, else errorTemplate(), else plain text.
 */
abstract class ViewOutlet extends Outlet implements ContainerAware
{
    private ?Views $views = null;

    /** Template name relative to the views directory, e.g. 'users/profile'. */
    abstract protected function template(): string;

    /**
     * Turn the pipeline's result into template variables. By default: $data, plus each key
     * of an associative array as its own variable.
     *
     * @return array<string, mixed>
     */
    protected function data(mixed $data, Request $request): array
    {
        return is_array($data) && !array_is_list($data) ? [...$data, 'data' => $data] : ['data' => $data];
    }

    protected function errorTemplate(): string
    {
        return 'errors/error';
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->views = $container->get(Views::class);
    }

    public function contentType(): string
    {
        return 'text/html; charset=utf-8';
    }

    public function render(Response $response, Request $request): string
    {
        $views = $this->views ?? throw new \LogicException(static::class . ' needs the container. Is ViewProvider registered?');

        if ($response->isError()) {
            return $this->renderError($views, $response, $request);
        }

        return $views->render($this->template(), [
            ...$this->data($response->data(), $request),
            'view' => $this,
            'meta' => $response->meta(),
            'request' => $request,
        ]);
    }

    private function renderError(Views $views, Response $response, Request $request): string
    {
        $error = $response->errorDetails();
        $status = $response->status();

        foreach (["errors/{$status}", $this->errorTemplate()] as $name) {
            if ($views->exists($name)) {
                return $views->render($name, ['error' => $error, 'status' => $status, 'view' => $this, 'request' => $request]);
            }
        }

        return htmlspecialchars("{$status} — " . ($error['message'] ?? 'Error'), ENT_QUOTES);
    }
}

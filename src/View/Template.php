<?php

declare(strict_types=1);

namespace Pype\View;

use Pype\Http\Request;
use Pype\Session\Session;
use Throwable;

/**
 * $this inside a template file.
 *
 * Forms (need the 'web' stack, i.e. a session):
 *   <form method="post">
 *       <?= $this->csrf() ?>
 *       <input name="email" value="<?= e($this->old('email')) ?>">
 *       <?php if ($error = $this->error('email')): ?><p><?= e($error) ?></p><?php endif ?>
 *   </form>
 *
 *   <form method="post" action="/posts/5"><?= $this->csrf() ?><?= $this->method('DELETE') ?> ...</form>
 */
final class Template
{
    private ?string $layout = null;

    /** @var array<string, mixed> */
    private array $layoutData = [];

    private ?Request $request = null;

    public function __construct(private readonly Views $views)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $file, array $data): string
    {
        if (($data['request'] ?? null) instanceof Request) {
            $this->request = $data['request'];
        }

        $level = ob_get_level();
        ob_start();

        try {
            (function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);

            $content = (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        if ($this->layout === null) {
            return $content;
        }

        return $this->views->render($this->layout, [...$data, ...$this->layoutData, 'content' => $content]);
    }

    /** Wrap this template in a layout; the layout receives the output as $content. */
    public function layout(string $name, array $data = []): void
    {
        $this->layout = $name;
        $this->layoutData = $data;
    }

    /** Render another view inline. */
    public function partial(string $name, array $data = []): string
    {
        return $this->views->render($name, $this->request === null ? $data : ['request' => $this->request, ...$data]);
    }

    /** The current session, if the route runs StartSession. */
    public function session(): ?Session
    {
        return $this->request?->context()->find(Session::class);
    }

    /** Hidden input carrying the CSRF token. */
    public function csrf(): string
    {
        return '<input type="hidden" name="_token" value="' . e($this->csrfToken()) . '">';
    }

    /** Hidden `_method` input, for PUT/PATCH/DELETE from a form (needs the MethodOverride stage). */
    public function method(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }

    /** The raw token, e.g. for <meta name="csrf-token"> and an X-CSRF-Token header in fetch(). */
    public function csrfToken(): string
    {
        $session = $this->session() ?? throw new \LogicException('csrf() needs a session. Add the \'web\' stack to this route.');

        return $session->token();
    }

    /** Input from the previous, failed submission (see FlashValidationErrors). */
    public function old(string $key, mixed $default = ''): mixed
    {
        return $this->session()?->get('_old')[$key] ?? $default;
    }

    /** First validation error for a field, or null. */
    public function error(string $key): ?string
    {
        return $this->errors()[$key][0] ?? null;
    }

    /** @return array<string, list<string>> all validation errors from the previous submission */
    public function errors(): array
    {
        return $this->session()?->get('_errors') ?? [];
    }
}

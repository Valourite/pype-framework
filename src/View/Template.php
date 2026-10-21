<?php

declare(strict_types=1);

namespace Pype\View;

use Pype\Http\Request;
use Pype\Session\Session;
use Throwable;

/**
 * $this inside a template file.
 *
 * Every template rendered for a request (pages, their layouts and partials) also gets these
 * variables, so forms need no $this->:
 *   $csrf             the CSRF token (needs the 'web' stack; using it without one throws)
 *   $old('email')     what was typed before a failed submission ('' if nothing); 'lines.3.qty' reads nested input
 *   $error('email')   the first validation error for that field, or null
 *   $errors           every error: ['email' => ['...'], ...]
 * Your own data wins when it uses the same name.
 *
 *   <form method="post">
 *       <input type="hidden" name="_token" value="<?= e($csrf) ?>">
 *       <input name="email" value="<?= e($old('email')) ?>">
 *       <?php if ($message = $error('email')): ?><p><?= e($message) ?></p><?php endif ?>
 *   </form>
 *
 * Don't assign to $error or $old in a template ($error = ...): that replaces the helper.
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
            })($file, $this->request === null ? $data : [...$this->locals(), ...$data]);

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

    /**
     * Input from the previous, failed submission (see FlashValidationErrors). Dots reach into
     * arrays: old('lines.3.quantity') is what was sent as lines[3][quantity].
     */
    public function old(string $key, mixed $default = ''): mixed
    {
        $value = $this->session()?->get('_old') ?? [];

        if (array_key_exists($key, $value)) {
            return $value[$key] ?? $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value ?? $default;
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

    /** For engines other than plain PHP: the request whose session the helpers below read. */
    public function forRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * $csrf, $old, $error and $errors for the request being rendered (Views gives them to Latte
     * and Blade templates too).
     *
     * @return array{csrf: string|\Stringable, old: \Closure, error: \Closure, errors: array<string, list<string>>}
     */
    public function locals(): array
    {
        return [
            'csrf' => $this->session()?->token() ?? new class () implements \Stringable {
                public function __toString(): string
                {
                    throw new \LogicException('$csrf needs a session. Add the \'web\' stack to this route.');
                }
            },
            'old' => $this->old(...),
            'error' => $this->error(...),
            'errors' => $this->errors(),
        ];
    }
}

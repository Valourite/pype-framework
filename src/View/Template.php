<?php

declare(strict_types=1);

namespace Pype\View;

use Throwable;

/**
 * $this inside a template file.
 */
final class Template
{
    private ?string $layout = null;

    /** @var array<string, mixed> */
    private array $layoutData = [];

    public function __construct(private readonly Views $views)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $file, array $data): string
    {
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
        return $this->views->render($name, $data);
    }
}

<?php

declare(strict_types=1);

namespace Pype\View;

use InvalidArgumentException;
use LogicException;
use Pype\Http\Request;

/**
 * Renders templates from resources/views. The file extension picks the engine:
 *
 *   resources/views/users/show.php         plain PHP (no package needed)
 *   resources/views/users/show.latte       Latte      (composer require latte/latte)
 *   resources/views/users/show.blade.php   BladeOne   (composer require eftec/bladeone)
 *
 *   $views->render('users/show', ['user' => $user])
 *
 * A name without an extension takes the first file found in that order: .blade.php, .latte,
 * .php. Name the extension to choose ('users/show.latte'). Engines mix freely: one page in Latte,
 * the next in plain PHP. Latte and BladeOne load only when a template of theirs is rendered, and
 * compile into the cache directory (storage/views).
 *
 * Every engine gets the same variables: your data, plus, when rendering for a request, $csrf,
 * $old(), $error() and $errors (see Template). Layouts and includes are each engine's own:
 * $this->layout() in PHP, {layout} in Latte, @extends in Blade.
 *
 * In plain PHP: <?= e($user->name) ?> to escape, $this->layout('layouts/app') to wrap,
 * <?= $this->partial('users/_card', ['user' => $u]) ?> to include.
 */
final class Views
{
    /** Tried in this order for a name without an extension (.blade.php before .php). */
    private const ENGINES = ['.blade.php' => 'blade', '.latte' => 'latte', '.php' => 'php'];

    /** Latte's engine is reusable across renders (it keeps no per-render state); built on first use. */
    private ?object $latte = null;

    public function __construct(private readonly string $path, private readonly string $cache = '')
    {
    }

    public function exists(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = []): string
    {
        [$file, $engine] = $this->find($name)
            ?? throw new InvalidArgumentException("View [{$name}] not found in {$this->path} (.blade.php, .latte or .php).");

        if ($engine === 'php') {
            return (new Template($this))->render($file, $data);
        }

        $request = $data['request'] ?? null;
        $data = $request instanceof Request ? [...(new Template($this))->forRequest($request)->locals(), ...$data] : $data;

        return $engine === 'latte' ? $this->latte($file, $data) : $this->blade($file, $data);
    }

    /** @return array{0: string, 1: string}|null the file and its engine */
    private function find(string $name): ?array
    {
        if (!preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*(\.blade\.php|\.latte|\.php)?$#', $name, $m)) {
            throw new InvalidArgumentException("Invalid view name [{$name}].");
        }

        $base = rtrim($this->path, '/') . '/';
        $given = $m[2] ?? '';

        if ($given !== '') {
            return is_file($base . $name) ? [$base . $name, self::ENGINES[$given]] : null;
        }

        foreach (self::ENGINES as $extension => $engine) {
            if (is_file($base . $name . $extension)) {
                return [$base . $name . $extension, $engine];
            }
        }

        return null;
    }

    private function latte(string $file, array $data): string
    {
        if (!class_exists(\Latte\Engine::class)) {
            throw new LogicException("[{$file}] is a Latte template. Run: composer require latte/latte");
        }

        if ($this->latte === null) {
            $latte = new \Latte\Engine();
            $latte->setTempDirectory($this->cacheDir('latte'));
            // Names from the views root, as everywhere in pype: {layout 'layouts/app.latte'} and
            // {include 'quotes/_lines.latte'} mean the same file from any template.
            $latte->setLoader(new class ($this->path) extends \Latte\Loaders\FileLoader {
                public function getReferredName(string $file, string $referringFile): string
                {
                    return ltrim($file, '/');
                }
            });
            $this->latte = $latte;
        }

        return $this->latte->renderToString($this->relative($file), $data);
    }

    private function blade(string $file, array $data): string
    {
        if (!class_exists(\eftec\bladeone\BladeOne::class)) {
            throw new LogicException("[{$file}] is a Blade template. Run: composer require eftec/bladeone");
        }

        // A new BladeOne per render: it keeps sections and stacks between calls, which must not
        // leak from one request to the next in a long-running worker.
        $blade = new \eftec\bladeone\BladeOne($this->path, $this->cacheDir('blade'), \eftec\bladeone\BladeOne::MODE_AUTO);

        return $blade->run(str_replace('/', '.', substr($this->relative($file), 0, -strlen('.blade.php'))), $data);
    }

    private function relative(string $file): string
    {
        return substr($file, strlen(rtrim($this->path, '/')) + 1);
    }

    private function cacheDir(string $engine): string
    {
        $dir = ($this->cache !== '' ? rtrim($this->cache, '/') : sys_get_temp_dir() . '/pype-views-' . md5($this->path)) . '/' . $engine;

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }
}

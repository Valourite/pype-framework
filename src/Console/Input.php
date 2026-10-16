<?php

declare(strict_types=1);

namespace Pype\Console;

/**
 * Command-line arguments for an app command: `php bin/pype report:send 2026-10 --dry-run --to=a@b.c`
 *
 *   $input->argument(0)         // '2026-10'
 *   $input->option('to')        // 'a@b.c'
 *   $input->flag('dry-run')     // true
 */
final class Input
{
    /** @var list<string> */
    private array $arguments = [];

    /** @var array<string, string|true> */
    private array $options = [];

    /** @param list<string> $argv  everything after the command name */
    public function __construct(array $argv)
    {
        foreach ($argv as $arg) {
            if (preg_match('/^--([\w-]+)(?:=(.*))?$/s', $arg, $m)) {
                $this->options[$m[1]] = $m[2] ?? true;
            } else {
                $this->arguments[] = $arg;
            }
        }
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function flag(string $name): bool
    {
        return isset($this->options[$name]);
    }
}

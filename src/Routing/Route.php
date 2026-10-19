<?php

declare(strict_types=1);

namespace Pype\Routing;

use Closure;
use Pype\Pipeline\Stage;

/**
 * A route is a method + path bound to its own pipeline of stages.
 */
final class Route
{
    private ?string $name = null;
    private ?string $regex = null;

    /** @param list<class-string<Stage>|Stage|Closure> $stages */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private array $stages,
        private readonly string $namePrefix = '',   // from enclosing groups: 'admin.'
    ) {
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return list<class-string<Stage>|Stage|Closure> */
    public function stages(): array
    {
        return $this->stages;
    }

    /** Append more stages to the end of this route's pipeline. */
    public function through(string|object ...$stages): self
    {
        array_push($this->stages, ...$stages);

        return $this;
    }

    /**
     * Name the route, so links to it can be built with url('name', [...]) instead of written
     * by hand. Inside a group with a name prefix, the prefix is added: 'users' → 'admin.users'.
     * Called without an argument, returns the full name.
     */
    public function name(?string $name = null): self|string|null
    {
        if ($name === null) {
            return $this->name;
        }

        $this->name = $this->namePrefix . $name;

        return $this;
    }

    /**
     * Match a path against this route; returns extracted params or null.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        $this->regex ??= '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $this->path) . '$#';

        if (!preg_match($this->regex, $path, $matches)) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}

<?php

declare(strict_types=1);

namespace Pype\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Minimal PSR-11 DI container: bindings, singletons, and reflection-based autowiring.
 * Being PSR-11, it can be handed to any third-party package that expects a container.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, array{factory: Closure, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $concrete ??= $id;

        $factory = $concrete instanceof Closure
            ? $concrete
            : fn (Container $c) => $c->build($concrete);

        $this->bindings[$id] = ['factory' => $factory, 'shared' => $shared];
        unset($this->instances[$id]);
    }

    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /** Whether $id has already been built (or set with instance()). */
    public function resolved(string $id): bool
    {
        return array_key_exists($id, $this->instances);
    }

    /** Drop a resolved instance (bindings stay). */
    public function forget(string $id): void
    {
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    /**
     * @template T
     * @param class-string<T>|string $id
     * @return T|mixed
     */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * @template T
     * @param class-string<T>|string $id
     * @return T|mixed
     */
    public function make(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $object = ($this->bindings[$id]['factory'])($this);

            if ($this->bindings[$id]['shared']) {
                $this->instances[$id] = $object;
            }

            return $object;
        }

        return $this->build($id);
    }

    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new NotFoundException("Cannot resolve [{$class}]: no binding and class does not exist.");
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Cannot resolve [{$class}]: not instantiable. Bind it first.");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->make($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new ContainerException(
                    "Cannot resolve parameter \${$param->getName()} of [{$class}]."
                );
            }
        }

        return $reflection->newInstanceArgs($args);
    }
}

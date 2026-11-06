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

    /** @var array<class-string, array<string, array{0: ?string, 1: \ReflectionParameter}>|false> see plan() */
    private array $plans = [];

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

    /**
     * A new instance of $class (bindings and singletons are not used), with $args for the
     * constructor parameters they name (string keys) or position (int keys) and the rest
     * resolved as usual. Used for stage() specs.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<int|string, mixed> $args
     * @return T
     */
    public function makeWith(string $class, array $args): object
    {
        return $this->build($class, $args);
    }

    /** @param array<int|string, mixed> $given */
    private function build(string $class, array $given = []): object
    {
        $params = $this->plans[$class] ?? $this->plan($class);

        if ($params === false) {
            if ($given !== []) {
                throw new ContainerException("Cannot build [{$class}] with arguments: it has no constructor.");
            }

            return new $class();
        }

        foreach (array_keys($given) as $key) {
            if (is_int($key) ? $key >= count($params) : !isset($params[$key])) {
                throw new ContainerException(is_int($key)
                    ? "Cannot build [{$class}]: argument #{$key} is past its constructor's parameters."
                    : "Cannot build [{$class}]: its constructor has no parameter \${$key}.");
            }
        }

        $args = [];
        $position = 0;
        foreach ($params as $name => [$type, $param]) {
            if ($given !== [] && array_key_exists($name, $given)) {
                $args[] = $given[$name];
            } elseif ($given !== [] && array_key_exists($position, $given)) {
                $args[] = $given[$position];
            } elseif ($type !== null) {
                try {
                    $args[] = $this->make($type);
                } catch (ContainerException|NotFoundException $e) {
                    // An optional dependency nothing provides (?Closure $x = null, an unbound
                    // interface with a default): use the default instead of failing.
                    $args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : throw $e;
                }
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();   // evaluated each time: a `new Foo()` default is never shared
            } else {
                throw new ContainerException(
                    "Cannot resolve parameter \${$name} of [{$class}]."
                );
            }
            $position++;
        }

        return new $class(...$args);
    }

    /**
     * A class's constructor parameters, reflected once per class (stages, repositories and
     * outlets are built on every request): name => [class to resolve or null, the parameter],
     * or false when it has no constructor.
     *
     * @return array<string, array{0: ?string, 1: \ReflectionParameter}>|false
     */
    private function plan(string $class): array|false
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
            return $this->plans[$class] = false;
        }

        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $params[$param->getName()] = [$type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null, $param];
        }

        return $this->plans[$class] = $params;
    }
}

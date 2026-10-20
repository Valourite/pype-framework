<?php

declare(strict_types=1);

namespace Pype\Pipeline;

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Pype\Container\ContainerException;
use Pype\Exceptions\HttpException;
use Pype\Http\Context;
use Pype\Http\Request;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Calls closures and invokable objects, filling their parameters by type and name:
 *
 *   Request / passable type   → the request flowing through the pipeline
 *   Closure                   → $next
 *   Context                   → the request's context
 *   any class in the Context  → that object (e.g. User put there by an auth stage)
 *   any other class           → resolved from the container
 *   scalar named like a route param ({id} → int $id) → the param, cast to the declared type
 *   untyped $next             → $next;  any other untyped param → the passable
 *
 *   $router->get('/users/{id}', [Authenticate::class, fn (User $me, int $id) => ...]);
 */
final class Invoker
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function call(callable $callable, mixed $passable, Closure $next): mixed
    {
        $reflection = $this->reflect($callable);
        $args = [];

        foreach ($reflection->getParameters() as $param) {
            if ($param->isVariadic()) {
                break;
            }

            $args[] = $this->resolve($param, $passable, $next, $reflection);
        }

        return $callable(...$args);
    }

    private function reflect(callable $callable): ReflectionFunctionAbstract
    {
        if ($callable instanceof Closure) {
            return new ReflectionFunction($callable);
        }

        if (is_object($callable)) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return new ReflectionFunction(Closure::fromCallable($callable));
    }

    private function resolve(ReflectionParameter $param, mixed $passable, Closure $next, ReflectionFunctionAbstract $fn): mixed
    {
        $type = $param->getType();
        $name = $param->getName();
        $request = $passable instanceof Request ? $passable : null;

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->resolveClass($type->getName(), $param, $passable, $request, $next, $fn);
        }

        if ($request !== null && ($value = $request->param($name)) !== null) {
            return $this->cast($value, $type, $name);
        }

        if ($type === null) {
            return $name === 'next' ? $next : $passable;
        }

        return $this->fallback($param, $fn, "no route parameter named '{$name}'");
    }

    private function resolveClass(
        string $class,
        ReflectionParameter $param,
        mixed $passable,
        ?Request $request,
        Closure $next,
        ReflectionFunctionAbstract $fn,
    ): mixed {
        if ($class === Closure::class) {
            return $next;
        }

        if ($passable instanceof $class) {
            return $passable;
        }

        if ($request !== null) {
            if ($class === Context::class) {
                return $request->context();
            }

            if (($item = $request->context()->find($class)) !== null) {
                return $item;
            }
        }

        try {
            return $this->container->get($class);
        } catch (ContainerExceptionInterface $e) {
            return $this->fallback($param, $fn, "not in the request context, and the container could not build it ({$e->getMessage()})");
        }
    }

    private function fallback(ReflectionParameter $param, ReflectionFunctionAbstract $fn, string $why): mixed
    {
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        $where = $fn->getFileName() ? basename($fn->getFileName()) . ':' . $fn->getStartLine() : $fn->getName();

        throw new ContainerException("Cannot inject \${$param->getName()} into stage at {$where}: {$why}.");
    }

    private function cast(string $value, ?\ReflectionType $type, string $name): mixed
    {
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'string';

        $cast = match ($typeName) {
            'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            default => $value,
        };

        // A route param that doesn't fit its declared type means the route doesn't exist.
        return $cast ?? throw HttpException::notFound("Invalid value for route parameter {{$name}}")->withKey('invalid_param');
    }
}

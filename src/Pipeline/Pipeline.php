<?php

declare(strict_types=1);

namespace Pype\Pipeline;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Pype\Container\Container;
use Pype\Container\ContainerAware;
use Throwable;

/**
 * Sends a payload through an ordered list of stages.
 *
 * A stage may be:
 *  - a class-string (resolved through the container) of a Stage or an invokable class
 *  - a StageSpec (stage(X::class, arg: ...)): built when reached, arguments given, services autowired
 *  - a configured instance: new Throttle(60)
 *  - a Closure
 *
 * Stages get handle($request, $next). Closures and invokable classes get their
 * parameters injected by the Invoker (request, $next, context objects, route params, services).
 *
 * Two optional hooks run around every stage (and the destination):
 *  - mapResult():   transforms each return value, so $next() always yields a predictable type
 *  - onException(): turns a thrown exception into a return value, so outer stages still see it
 */
final class Pipeline
{
    private mixed $passable = null;

    /** @var list<class-string<Stage>|Stage|Closure> */
    private array $stages = [];

    private ?Closure $mapResult = null;
    private ?Closure $onException = null;

    private readonly Invoker $invoker;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->invoker = new Invoker($container);
    }

    public function send(mixed $passable): self
    {
        $this->passable = $passable;

        return $this;
    }

    /** @param list<class-string<Stage>|Stage|Closure> $stages */
    public function through(array $stages): self
    {
        $this->stages = array_values($stages);

        return $this;
    }

    /** @param Closure(mixed $result): mixed $map */
    public function mapResult(Closure $map): self
    {
        $this->mapResult = $map;

        return $this;
    }

    /** @param Closure(Throwable $e, mixed $passable): mixed $handler */
    public function onException(Closure $handler): self
    {
        $this->onException = $handler;

        return $this;
    }

    public function then(Closure $destination): mixed
    {
        return $this->step(0, $this->passable, $this->stages, $destination);
    }

    /**
     * Run stage $i (or the destination after the last one). Each stage gets a $next that runs
     * the one after it, and is guarded on its own: an exception becomes a result right there,
     * so the stages around it still see one.
     */
    private function step(int $i, mixed $passable, array $stages, Closure $destination): mixed
    {
        try {
            $result = isset($stages[$i])
                ? $this->invoke($stages[$i], $passable, fn (mixed $passable) => $this->step($i + 1, $passable, $stages, $destination))
                : $destination($passable);
        } catch (Throwable $e) {
            if ($this->onException === null) {
                throw $e;
            }

            $result = ($this->onException)($e, $passable);
        }

        return $this->mapResult === null ? $result : ($this->mapResult)($result);
    }

    /** Run the pipeline; whatever falls out the far end is returned. */
    public function thenReturn(): mixed
    {
        return $this->then(fn (mixed $passable) => $passable);
    }

    private function invoke(mixed $stage, mixed $passable, Closure $next): mixed
    {
        if ($stage instanceof StageSpec) {
            $stage = $this->container instanceof Container
                ? $this->container->makeWith($stage->class, $stage->args)
                : throw new \LogicException('stage() needs pype\'s container to build ' . $stage->class . '.');
        } elseif (is_string($stage)) {
            $stage = $this->container->get($stage);
        }

        if ($stage instanceof ContainerAware) {
            $stage->setContainer($this->container);
        }

        if ($stage instanceof Stage) {
            return $stage->handle($passable, $next);
        }

        if (is_callable($stage)) {
            return $this->invoker->call($stage, $passable, $next);
        }

        throw new InvalidArgumentException(
            'Pipeline stage must be a Closure, an invokable object, or implement ' . Stage::class
            . ', got ' . get_debug_type($stage)
        );
    }
}

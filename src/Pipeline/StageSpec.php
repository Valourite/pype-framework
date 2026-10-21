<?php

declare(strict_types=1);

namespace Pype\Pipeline;

use InvalidArgumentException;
use UnitEnum;

/**
 * A stage to build later: its class and the arguments you give. Made by stage() or a stage's
 * ::with(). Nothing is loaded or constructed until a request reaches it; the container then
 * builds a fresh instance with these arguments and autowires the rest of the constructor
 * (services), so the stage needs no ContainerAware.
 *
 *   stage(LoadDocumentsPage::class, kind: 'quote')    // __construct(string $kind, Documents $docs)
 *   LoadDocumentsPage::with(kind: 'quote')            // the same, via the WithArguments trait
 *
 * Arguments are plain data (scalars, null, enums, arrays of them): configuration known when the
 * route is written. Data made during a request belongs in the request context instead. Being
 * plain data, a spec is the same for every request, so it is safe to share in a long-running
 * worker, unlike a `new X()` stage instance.
 */
final readonly class StageSpec
{
    /**
     * @param class-string $class
     * @param array<int|string, mixed> $args  int keys by position, string keys by parameter name
     */
    public function __construct(public string $class, public array $args = [])
    {
        foreach ($args as $name => $value) {
            self::assertPlain($value, $class, (string) $name);
        }
    }

    private static function assertPlain(mixed $value, string $class, string $name): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertPlain($item, $class, $name);
            }

            return;
        }

        if ($value !== null && !is_scalar($value) && !$value instanceof UnitEnum) {
            throw new InvalidArgumentException(sprintf(
                'stage(%s): argument [%s] is %s. Stage arguments are scalars, arrays or enums; services are autowired, and request data belongs in the context.',
                $class,
                $name,
                get_debug_type($value),
            ));
        }
    }
}

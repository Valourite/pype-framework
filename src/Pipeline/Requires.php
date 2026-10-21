<?php

declare(strict_types=1);

namespace Pype\Pipeline;

use Attribute;

/**
 * What a stage reads from the request context, so `php bin/pype routes:check` can tell when a
 * route lists it before the stage that puts it there:
 *
 *   #[Requires(User::class)]
 *   #[Provides(Quote::class)]
 *   final class LoadQuote implements Stage { ... }
 *
 * The key is what put() stored it under: the object's class (or the $as you gave). Optional:
 * stages without declarations are not checked. Or implement DeclaresContext instead.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Requires
{
    /** @var list<string> */
    public array $keys;

    public function __construct(string ...$keys)
    {
        $this->keys = array_values($keys);
    }
}

<?php

declare(strict_types=1);

namespace Pype\Pipeline;

use Attribute;

/**
 * What a stage puts in the request context for later stages (see Requires). A stage that only
 * sometimes puts it may still declare it: routes:check trusts the declaration.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Provides
{
    /** @var list<string> */
    public array $keys;

    public function __construct(string ...$keys)
    {
        $this->keys = array_values($keys);
    }
}

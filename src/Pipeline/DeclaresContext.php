<?php

declare(strict_types=1);

namespace Pype\Pipeline;

/**
 * The method form of #[Requires] / #[Provides], for those who prefer no attributes. Static, so
 * routes:check reads them without building the stage. When a class has both, these win.
 *
 *   final class LoadQuote implements Stage, DeclaresContext
 *   {
 *       public static function requires(): array { return [User::class]; }
 *       public static function provides(): array { return [Quote::class]; }
 *   }
 */
interface DeclaresContext
{
    /** @return list<string> context keys this stage reads */
    public static function requires(): array;

    /** @return list<string> context keys this stage puts */
    public static function provides(): array;
}

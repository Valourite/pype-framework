<?php

declare(strict_types=1);

namespace Pype\Pipeline;

/**
 * Gives a stage a static with(): LoadDocumentsPage::with(kind: 'quote') is
 * stage(LoadDocumentsPage::class, kind: 'quote'). Arguments go to the constructor by name (or
 * position); the constructor's other parameters are autowired when a request reaches the stage.
 *
 * Write your own with() instead when you want the arguments typed for your editor:
 *
 *   public static function with(string $kind): StageSpec { return stage(self::class, kind: $kind); }
 */
trait WithArguments
{
    public static function with(mixed ...$args): StageSpec
    {
        return new StageSpec(static::class, $args);
    }
}

<?php

declare(strict_types=1);

namespace Pype\Database;

/**
 * One entry of Page::links(): a page number with its URL, or a gap (… between numbers).
 *
 *   <?php foreach ($page->links() as $link): ?>
 *       <?php if ($link->gap): ?>…
 *       <?php elseif ($link->current): ?><strong><?= $link->number ?></strong>
 *       <?php else: ?><a href="<?= e($link->url) ?>"><?= $link->number ?></a>
 *       <?php endif ?>
 *   <?php endforeach ?>
 */
final readonly class PageLink
{
    public function __construct(
        public ?int $number,
        public ?string $url,
        public bool $current = false,
        public bool $gap = false,
    ) {
    }

    public static function gap(): self
    {
        return new self(null, null, gap: true);
    }
}

<?php

declare(strict_types=1);

namespace Pype\Database;

use Pype\Http\Responsable;
use Pype\Http\Response;

/**
 * One page of results, from $query->paginate($perPage, $request).
 *
 * Return it from a handler: the data is the page's items, and the response meta carries
 * {"pagination": {"page", "per_page", "total", "last_page", "next", "previous"}}. Views receive the items as their
 * data, the numbers as $meta['pagination'], and this object as $page for the links:
 *
 *   <?php foreach ($page->links() as $link): ?> ... <?php endforeach ?>
 *   <a href="<?= e($page->nextUrl()) ?>">Next</a>
 *
 * Links keep the request's other query parameters (?status=, ?q=) when the page came from
 * paginate(..., $request).
 *
 * @template T
 */
final readonly class Page implements Responsable
{
    public PageUrls $urls;

    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $page,
        ?PageUrls $urls = null,
    ) {
        $this->urls = $urls ?? new PageUrls();
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->lastPage();
    }

    /** The link to page $number of this list. */
    public function url(int $number): string
    {
        return $this->urls->to($number);
    }

    public function previousUrl(): ?string
    {
        return $this->hasPrevious() ? $this->url($this->page - 1) : null;
    }

    public function nextUrl(): ?string
    {
        return $this->hasNext() ? $this->url($this->page + 1) : null;
    }

    /**
     * The page numbers to show: the first, the last, and $window pages either side of the
     * current one, with gaps where numbers are skipped. A gap that would hide one page shows
     * that page instead (1 2 3, not 1 … 3). On page 9 of 9000: 1 … 7 8 [9] 10 11 … 9000.
     *
     * @return list<PageLink>
     */
    public function links(int $window = 2): array
    {
        $last = $this->lastPage();
        $numbers = array_unique([1, ...range(max(1, $this->page - $window), min($last, $this->page + $window)), $last]);
        sort($numbers);

        $links = [];
        $previous = 0;

        foreach ($numbers as $number) {
            if ($number - $previous === 2) {
                $links[] = $this->link($previous + 1);
            } elseif ($number - $previous > 2) {
                $links[] = PageLink::gap();
            }

            $links[] = $this->link($number);
            $previous = $number;
        }

        return $links;
    }

    /** @return array{page: int, per_page: int, total: int, last_page: int, next: ?string, previous: ?string} */
    public function meta(): array
    {
        return ['page' => $this->page, 'per_page' => $this->perPage, 'total' => $this->total, 'last_page' => $this->lastPage(), 'next' => $this->nextUrl(), 'previous' => $this->previousUrl()];
    }

    /**
     * The same page with each item mapped, e.g. to what API clients may see:
     * `$page->map(fn (Ticket $t) => ['id' => $t->id, 'subject' => $t->subject])`.
     *
     * @template U
     * @param callable(T): U $fn
     * @return self<U>
     */
    public function map(callable $fn): self
    {
        return new self(array_map($fn, $this->items), $this->total, $this->perPage, $this->page, $this->urls);
    }

    public function toResponse(): Response
    {
        return Response::make($this->items)->withMeta(['pagination' => $this->meta()]);
    }

    private function link(int $number): PageLink
    {
        return new PageLink($number, $this->url($number), $number === $this->page);
    }
}

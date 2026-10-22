<?php

declare(strict_types=1);

namespace Pype\Database;

use Pype\Http\Responsable;
use Pype\Http\Response;

/**
 * One page of results without a total, from $query->simplePaginate($perPage, $request): one
 * extra row is fetched to know whether a next page exists, and no COUNT(*) runs. Only previous
 * and next links, so use it where the total is expensive and nobody needs to jump to page 9,000.
 *
 * JSON meta: {"pagination": {"page", "per_page", "has_more", "next", "previous"}}. Views get this object as $page.
 *
 * @template T
 */
final readonly class SimplePage implements Responsable
{
    public PageUrls $urls;

    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $perPage,
        public int $page,
        public bool $hasMore,
        ?PageUrls $urls = null,
    ) {
        $this->urls = $urls ?? new PageUrls();
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->hasMore;
    }

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
        return $this->hasMore ? $this->url($this->page + 1) : null;
    }

    /** @return array{page: int, per_page: int, has_more: bool, next: ?string, previous: ?string} */
    public function meta(): array
    {
        return ['page' => $this->page, 'per_page' => $this->perPage, 'has_more' => $this->hasMore, 'next' => $this->nextUrl(), 'previous' => $this->previousUrl()];
    }

    /**
     * The same page with each item mapped (see Page::map()).
     *
     * @template U
     * @param callable(T): U $fn
     * @return self<U>
     */
    public function map(callable $fn): self
    {
        return new self(array_map($fn, $this->items), $this->perPage, $this->page, $this->hasMore, $this->urls);
    }

    public function toResponse(): Response
    {
        return Response::make($this->items)->withMeta(['pagination' => $this->meta()]);
    }
}

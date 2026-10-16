<?php

declare(strict_types=1);

namespace Pype\Database;

use Pype\Http\Responsable;
use Pype\Http\Response;

/**
 * One page of results, from $query->paginate($perPage, $page).
 *
 * Return it from a handler: the data is the page's items, and the response meta carries
 * {"pagination": {"page", "per_page", "total", "last_page"}}. Views receive the items as their
 * data and the numbers as $meta['pagination'].
 *
 * @template T
 */
final readonly class Page implements Responsable
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $page,
    ) {
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

    /** @return array{page: int, per_page: int, total: int, last_page: int} */
    public function meta(): array
    {
        return ['page' => $this->page, 'per_page' => $this->perPage, 'total' => $this->total, 'last_page' => $this->lastPage()];
    }

    public function toResponse(): Response
    {
        return Response::make($this->items)->withMeta(['pagination' => $this->meta()]);
    }
}

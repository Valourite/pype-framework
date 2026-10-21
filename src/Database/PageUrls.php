<?php

declare(strict_types=1);

namespace Pype\Database;

use Pype\Http\Request;

/**
 * Builds the link to page N of a list from the request that asked for it: same path, the same
 * query string (?status=, ?q=, ?month= ...) with only the page number changed. Page 1 leaves the
 * page parameter out, so the first page has one URL.
 */
final readonly class PageUrls
{
    /** @param array<string, mixed> $query */
    public function __construct(
        public string $path = '',
        public array $query = [],
        public string $pageName = 'page',
    ) {
    }

    public static function from(Request $request, string $pageName = 'page'): self
    {
        $query = $request->query();
        unset($query[$pageName]);

        return new self($request->path(), $query, $pageName);
    }

    /** The requested page number: at least 1, and 1 for anything that isn't a whole number. */
    public static function pageFrom(Request $request, string $pageName = 'page'): int
    {
        $value = $request->query($pageName);

        return is_string($value) && ctype_digit($value) ? max(1, (int) $value) : 1;
    }

    public function to(int $page): string
    {
        $query = $page > 1 ? [...$this->query, $this->pageName => $page] : $this->query;
        $string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $this->path . ($string === '' ? ($this->path === '' ? '?' : '') : '?' . $string);
    }
}

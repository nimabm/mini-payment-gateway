<?php

declare(strict_types=1);

namespace App\Application\Reporting;

/**
 * @template T
 */
final readonly class Paginated
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function pageCount(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 1;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pageCount();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}

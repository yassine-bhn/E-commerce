<?php

declare(strict_types=1);

namespace App\Shared\Application\Pagination;

final readonly class Page
{
    /**
     * @param array<int, mixed> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $size,
        public int $total,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->page * $this->size < $this->total;
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->total / $this->size));
    }

    /**
     * @return array{items: array<int, mixed>, meta: array{page: int, size: int, total: int, totalPages: int, hasMore: bool}}
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'meta' => [
                'page' => $this->page,
                'size' => $this->size,
                'total' => $this->total,
                'totalPages' => $this->totalPages(),
                'hasMore' => $this->hasMore(),
            ],
        ];
    }
}

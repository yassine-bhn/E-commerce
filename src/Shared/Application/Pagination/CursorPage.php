<?php

declare(strict_types=1);

namespace App\Shared\Application\Pagination;

final readonly class CursorPage
{
    /**
     * @param array<int, mixed> $items
     */
    public function __construct(
        public array $items,
        public int $limit,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {
    }

    /**
     * @return array{items: array<int, mixed>, meta: array{limit: int, hasMore: bool, nextCursor: ?string}}
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'meta' => [
                'limit' => $this->limit,
                'hasMore' => $this->hasMore,
                'nextCursor' => $this->nextCursor,
            ],
        ];
    }
}

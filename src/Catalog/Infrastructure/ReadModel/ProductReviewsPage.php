<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Shared\Application\Pagination\CursorPage;

/**
 * One reviews page plus the product-wide aggregates (R6): `count` and `averageRating` cover
 * every review of the product, while `items`/`meta` describe only the requested keyset slice.
 */
final readonly class ProductReviewsPage
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public array $items,
        public int $limit,
        public bool $hasMore,
        public ?string $nextCursor,
        public int $count,
        public float $averageRating,
    ) {
    }

    /**
     * @return array{count: int, averageRating: float, items: list<array<string, mixed>>, meta: array{limit: int, hasMore: bool, nextCursor: ?string}}
     */
    public function toArray(): array
    {
        $page = new CursorPage($this->items, $this->limit, $this->hasMore, $this->nextCursor)->toArray();

        return [
            'count' => $this->count,
            'averageRating' => $this->averageRating,
            'items' => $this->items,
            'meta' => $page['meta'],
        ];
    }
}

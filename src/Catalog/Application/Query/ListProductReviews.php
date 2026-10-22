<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Pagination\CursorRequest;

/**
 * One keyset page of a product's reviews (R6).
 *
 * `count` and `averageRating` are always computed over every review of the product, never
 * only over the returned page or the rating-filtered subset.
 */
final readonly class ListProductReviews
{
    public const int DEFAULT_LIMIT = 10;
    public const int MAX_LIMIT = 50;

    public function __construct(
        public string $slug,
        public CursorRequest $cursor = new CursorRequest(self::DEFAULT_LIMIT, null, self::MAX_LIMIT),
        public ?int $rating = null,
    ) {
    }
}

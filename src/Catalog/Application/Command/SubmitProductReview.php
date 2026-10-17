<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class SubmitProductReview
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $authorName,
        public int $rating,
        public string $comment,
    ) {
    }
}

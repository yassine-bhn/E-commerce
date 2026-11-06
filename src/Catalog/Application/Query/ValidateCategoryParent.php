<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ValidateCategoryParent
{
    public function __construct(
        public string $categorySlug,
        public ?string $parentSlug,
    ) {
    }
}

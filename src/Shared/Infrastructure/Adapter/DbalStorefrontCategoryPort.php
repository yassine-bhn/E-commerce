<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Adapter;

use App\Shared\Application\Port\StorefrontCategoryPort;
use App\Shared\Infrastructure\ReadModel\StorefrontReferenceRepository;

final class DbalStorefrontCategoryPort implements StorefrontCategoryPort
{
    public function __construct(private StorefrontReferenceRepository $reference)
    {
    }

    public function isQuoteEligible(string $categorySlug): bool
    {
        return $this->reference->isQuoteCategory($categorySlug);
    }
}

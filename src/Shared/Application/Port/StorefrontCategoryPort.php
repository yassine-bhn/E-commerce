<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface StorefrontCategoryPort
{
    public function isQuoteEligible(string $categorySlug): bool;
}

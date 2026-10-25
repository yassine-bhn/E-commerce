<?php

declare(strict_types=1);

namespace App\Shared\Domain\Inventory;

final class InsufficientStockException extends \DomainException
{
    public function __construct(public readonly string $productSlug)
    {
        parent::__construct(sprintf('Insufficient stock for product: %s', $productSlug));
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class ProductHasOrdersCannotBeDeletedException extends \DomainException
{
    public static function forProduct(string $slug, int $ordersCount): self
    {
        return new self(sprintf(
            'Product "%s" cannot be deleted because it is associated with %d existing orders. Please unpublish or hide the product instead.',
            $slug,
            $ordersCount,
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

use App\Ordering\Domain\Model\OrderStatus;

/**
 * The write model persists {@see OrderStatus} values; the storefront UI only understands
 * "En préparation" / "Expédiée" / "Livrée" / "Annulée". Translating at the read-model
 * boundary keeps the API contract stable whatever the internal status vocabulary is.
 */
final class StorefrontOrderStatus
{
    public static function label(string $status): string
    {
        return match (OrderStatus::tryFrom($status)) {
            OrderStatus::PENDING, OrderStatus::PAID => 'En préparation',
            OrderStatus::SHIPPED => 'Expédiée',
            OrderStatus::CANCELLED, OrderStatus::FAILED, OrderStatus::REFUNDED => 'Annulée',
            null => $status,
        };
    }
}

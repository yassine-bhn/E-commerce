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

    /**
     * The persisted statuses behind one filter slug; `[]` for an unknown slug so the caller can
     * answer an empty page instead of leaking rows.
     *
     * @return list<string>
     */
    public static function valuesFor(string $slug): array
    {
        return match ($slug) {
            'en_preparation' => [OrderStatus::PENDING->value, OrderStatus::PAID->value],
            'expediee' => [OrderStatus::SHIPPED->value],
            'annulee' => [OrderStatus::CANCELLED->value, OrderStatus::FAILED->value, OrderStatus::REFUNDED->value],
            default => [],
        };
    }
}

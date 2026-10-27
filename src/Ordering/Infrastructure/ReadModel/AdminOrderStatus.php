<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

use App\Ordering\Domain\Model\OrderStatus;

/**
 * Dashboard vocabulary for the order statuses.
 *
 * The write model persists {@see OrderStatus} values and the transition endpoint takes them as is,
 * while the storefront collapses them into three customer-facing labels
 * ({@see StorefrontOrderStatus}). The back office needs every state, with a stable ASCII slug for
 * URLs and filters and a French label, so that translation lives here — at the read-model boundary.
 */
final class AdminOrderStatus
{
    /** @return list<string> */
    public static function slugs(): array
    {
        return ['pending', 'paid', 'failed', 'cancelled', 'shipped', 'refunded'];
    }

    public static function fromSlug(string $slug): ?OrderStatus
    {
        return match ($slug) {
            'pending' => OrderStatus::PENDING,
            'paid' => OrderStatus::PAID,
            'failed' => OrderStatus::FAILED,
            'cancelled' => OrderStatus::CANCELLED,
            'shipped' => OrderStatus::SHIPPED,
            'refunded' => OrderStatus::REFUNDED,
            default => null,
        };
    }

    public static function slug(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::PENDING => 'pending',
            OrderStatus::PAID => 'paid',
            OrderStatus::FAILED => 'failed',
            OrderStatus::CANCELLED => 'cancelled',
            OrderStatus::SHIPPED => 'shipped',
            OrderStatus::REFUNDED => 'refunded',
        };
    }

    public static function label(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::PENDING => 'En attente',
            OrderStatus::PAID => 'Acceptée',
            OrderStatus::FAILED => 'Paiement échoué',
            OrderStatus::CANCELLED => 'Refusée / annulée',
            OrderStatus::SHIPPED => 'Expédiée',
            OrderStatus::REFUNDED => 'Remboursée',
        };
    }

    /**
     * The statuses an order in `$status` may move to, so the dashboard never has to duplicate the
     * state machine: it offers exactly what the backend would accept.
     *
     * @return list<array{status: string, slug: string, label: string}>
     */
    public static function transitionsFrom(OrderStatus $status): array
    {
        return array_map(
            static fn (OrderStatus $target): array => [
                'status' => $target->value,
                'slug' => self::slug($target),
                'label' => self::label($target),
            ],
            $status->allowedTransitions(),
        );
    }
}

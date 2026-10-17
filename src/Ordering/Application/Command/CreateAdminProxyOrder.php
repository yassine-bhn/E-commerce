<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Domain\Model\OrderSource;

final readonly class CreateAdminProxyOrder
{
    /**
     * @param list<array{slug: string, variant?: string|null, quantity: int}> $lines
     */
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public OrderSource $orderSource,
        public string $customerName,
        public string $customerPhone,
        public string $shippingAddress,
        public array $lines,
        public string $deliveryMethod,
        public string $paymentMethod,
        public string $createdByAdminId,
        public ?string $adminNotes = null,
        public ?string $promoCode = null,
    ) {
    }
}

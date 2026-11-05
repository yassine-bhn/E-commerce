<?php

declare(strict_types=1);

namespace App\Ordering\Application\Dto;

/**
 * Everything the proxy-order INSERT needs: the admin's form fields plus the priced quote the
 * handler just computed.
 *
 * @phpstan-type ProxyLine array{slug: string, name: string, variant: ?string, unitPriceMinor: int, quantity: int}
 */
final readonly class AdminProxyOrderData
{
    /**
     * @param list<ProxyLine> $lines
     */
    public function __construct(
        public string $orderId,
        public string $guestCustomerId,
        public string $orderNumber,
        public string $orderSource,
        public string $customerName,
        public string $customerPhone,
        public string $shippingAddress,
        public string $createdByAdminId,
        public ?string $adminNotes,
        public int $subtotalMinor,
        public int $discountMinor,
        public int $deliveryMinor,
        public int $totalMinor,
        public string $currency,
        public string $deliveryMethod,
        public string $paymentMethod,
        public ?string $promoCode,
        public array $lines,
    ) {
    }
}

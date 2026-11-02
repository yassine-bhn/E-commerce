<?php

declare(strict_types=1);

namespace App\Payment\Application\Dto;

use App\Payment\Domain\Model\PaymentStatus;

final readonly class PaymentSnapshot
{
    public function __construct(
        public string $orderId,
        public PaymentStatus $status,
        public int $version,
        public int $amountMinor,
        public string $currency,
    ) {
    }
}

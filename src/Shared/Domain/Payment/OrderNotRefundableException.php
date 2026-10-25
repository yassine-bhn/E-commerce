<?php

declare(strict_types=1);

namespace App\Shared\Domain\Payment;

final class OrderNotRefundableException extends \DomainException
{
    public static function forOrder(string $orderId): self
    {
        return new self(sprintf('Order "%s" cannot be refunded from its current state.', $orderId));
    }
}

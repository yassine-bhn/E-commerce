<?php

declare(strict_types=1);

namespace App\Shared\Domain\Payment;

final class OrderNotPayableException extends \DomainException
{
    public static function forOrder(string $orderId): self
    {
        return new self(sprintf('Order "%s" is no longer awaiting payment.', $orderId));
    }
}

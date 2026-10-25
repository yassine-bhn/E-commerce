<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

final class PaymentNotFoundException extends \RuntimeException
{
    public static function forId(string $paymentId): self
    {
        return new self(sprintf('Payment "%s" not found.', $paymentId));
    }
}

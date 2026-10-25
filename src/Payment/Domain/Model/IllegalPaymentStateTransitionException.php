<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

final class IllegalPaymentStateTransitionException extends \DomainException
{
    public static function forTransition(PaymentStatus $from, PaymentStatus $to): self
    {
        return new self(sprintf('Illegal payment state transition from %s to %s', $from->value, $to->value));
    }
}

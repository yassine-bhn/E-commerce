<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Model;

final class IllegalOrderStateTransitionException extends \DomainException
{
    public static function forTransition(OrderStatus $from, OrderStatus $to): self
    {
        return new self(sprintf('Illegal order state transition from %s to %s', $from->value, $to->value));
    }
}

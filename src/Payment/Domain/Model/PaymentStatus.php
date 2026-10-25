<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case AUTHORIZED = 'authorized';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::AUTHORIZED, self::FAILED],
            self::AUTHORIZED => [self::REFUNDED],
            self::FAILED, self::REFUNDED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function ensureCanTransitionTo(self $target): void
    {
        if (!$this->canTransitionTo($target)) {
            throw IllegalPaymentStateTransitionException::forTransition($this, $target);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Model;

enum OrderStatus: string
{
    case PENDING = '待支付';
    case PAID = '已支付';
    case FAILED = '支付失败';
    case CANCELLED = '已取消';
    case SHIPPED = '已发货';
    case REFUNDED = '已退款';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::PAID, self::CANCELLED, self::FAILED],
            self::PAID => [self::SHIPPED, self::REFUNDED],
            self::FAILED => [self::PENDING, self::CANCELLED],
            self::SHIPPED => [self::REFUNDED],
            self::CANCELLED, self::REFUNDED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function ensureCanTransitionTo(self $target): void
    {
        if (!$this->canTransitionTo($target)) {
            throw IllegalOrderStateTransitionException::forTransition($this, $target);
        }
    }
}

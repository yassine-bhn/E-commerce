<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\Model\IllegalOrderStateTransitionException;
use App\Ordering\Domain\Model\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStateTransitionTest extends TestCase
{
    public function testPendingCanTransitionToPaidCancelledAndFailed(): void
    {
        self::assertTrue(OrderStatus::PENDING->canTransitionTo(OrderStatus::PAID));
        self::assertTrue(OrderStatus::PENDING->canTransitionTo(OrderStatus::CANCELLED));
        self::assertTrue(OrderStatus::PENDING->canTransitionTo(OrderStatus::FAILED));
    }

    public function testPendingCannotTransitionDirectlyToShippedOrRefunded(): void
    {
        self::assertFalse(OrderStatus::PENDING->canTransitionTo(OrderStatus::SHIPPED));
        self::assertFalse(OrderStatus::PENDING->canTransitionTo(OrderStatus::REFUNDED));
    }

    public function testPaidCanTransitionToShippedOrRefunded(): void
    {
        self::assertTrue(OrderStatus::PAID->canTransitionTo(OrderStatus::SHIPPED));
        self::assertTrue(OrderStatus::PAID->canTransitionTo(OrderStatus::REFUNDED));
        self::assertFalse(OrderStatus::PAID->canTransitionTo(OrderStatus::PENDING));
        self::assertFalse(OrderStatus::PAID->canTransitionTo(OrderStatus::CANCELLED));
    }

    public function testTerminalStatesCannotTransition(): void
    {
        self::assertFalse(OrderStatus::CANCELLED->canTransitionTo(OrderStatus::PAID));
        self::assertFalse(OrderStatus::REFUNDED->canTransitionTo(OrderStatus::SHIPPED));
    }

    public function testEnsureTransitionThrowsOnIllegalMove(): void
    {
        $this->expectException(IllegalOrderStateTransitionException::class);
        $this->expectExceptionMessage('Illegal order state transition from 待支付 to 已发货');

        OrderStatus::PENDING->ensureCanTransitionTo(OrderStatus::SHIPPED);
    }
}

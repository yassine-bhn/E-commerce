<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Event\OrderStatusChanged;
use App\Ordering\Domain\Model\Order;
use App\Ordering\Domain\Model\OrderId;
use App\Ordering\Domain\Model\OrderLine;
use App\Ordering\Domain\Model\OrderSource;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    public function testPlaceOnlineRecordsOrderPlacedEventWithPendingStatus(): void
    {
        $order = Order::placeOnline(
            new OrderId('33333333-3333-3333-3333-333333333333'),
            '01929312-7000-7000-8000-000000000001',
            'ZG-123456',
            Money::ofMinor(10000, Currency::TND),
            Money::ofMinor(1000, Currency::TND),
            Money::ofMinor(1500, Currency::TND),
            Money::ofMinor(10500, Currency::TND),
            'home',
            'Carte bancaire',
            'ZIDEN10',
            [
                OrderLine::snapshot(
                    '44444444-4444-4444-4444-444444444444',
                    'baignoire-d-code',
                    'Baignoire',
                    null,
                    Money::ofMinor(10000, Currency::TND),
                    1,
                ),
            ],
            new \DateTimeImmutable('2026-10-19T12:00:00+00:00'),
        );

        self::assertSame(OrderStatus::PENDING, $order->status());
        self::assertSame(1, $order->version());
        self::assertSame(OrderSource::ONLINE, $order->source());
        self::assertCount(1, $order->lines());

        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrderPlaced::class, $events[0]);
        self::assertSame('33333333-3333-3333-3333-333333333333', $events[0]->orderId);
        self::assertSame('01929312-7000-7000-8000-000000000001', $events[0]->customerId);
        self::assertSame(10500, $events[0]->totalMinor);
    }

    public function testTransitionToPaidIncrementsVersion(): void
    {
        $order = Order::placeOnline(
            new OrderId('33333333-3333-3333-3333-333333333333'),
            '01929312-7000-7000-8000-000000000001',
            'ZG-123456',
            Money::ofMinor(5000, Currency::TND),
            Money::ofMinor(0, Currency::TND),
            Money::ofMinor(0, Currency::TND),
            Money::ofMinor(5000, Currency::TND),
            'home',
            'COD',
            null,
            [
                OrderLine::snapshot(
                    '44444444-4444-4444-4444-444444444444',
                    'lavabo',
                    'Lavabo',
                    null,
                    Money::ofMinor(5000, Currency::TND),
                    1,
                ),
            ],
            new \DateTimeImmutable(),
        );
        $order->releaseEvents();

        $order->transitionTo(OrderStatus::PAID, 1);

        self::assertSame(OrderStatus::PAID, $order->status());
        self::assertSame(2, $order->version());

        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrderStatusChanged::class, $events[0]);
        self::assertSame('待支付', $events[0]->fromStatus);
        self::assertSame('已支付', $events[0]->toStatus);
    }
}

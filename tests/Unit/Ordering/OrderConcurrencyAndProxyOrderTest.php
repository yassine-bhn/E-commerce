<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Command\CreateAdminProxyOrder;
use App\Ordering\Application\Command\TransitionOrderStatus;
use App\Ordering\Application\CommandHandler\CreateAdminProxyOrderHandler;
use App\Ordering\Application\CommandHandler\TransitionOrderStatusHandler;
use App\Ordering\Application\Dto\AdminProxyOrderData;
use App\Ordering\Application\Dto\OrderState;
use App\Ordering\Application\Port\AdminProxyOrderStore;
use App\Ordering\Application\Port\OrderStateReader;
use App\Ordering\Application\Port\OrderTransitionStore;
use App\Ordering\Domain\Model\IllegalOrderStateTransitionException;
use App\Ordering\Domain\Model\OptimisticLockException;
use App\Ordering\Domain\Model\OrderSource;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Application\Dto\StorefrontProductSnapshot;
use App\Shared\Application\Port\PaymentInitiationPort;
use App\Shared\Application\Port\StockReservationPort;
use App\Shared\Application\Port\StorefrontCatalogPort;
use App\Shared\Application\Port\StorefrontPricingPort;
use App\Shared\Application\Service\StorefrontQuoteService;
use App\Shared\Domain\Storefront\DeliveryPolicy;
use PHPUnit\Framework\TestCase;

final class OrderConcurrencyAndProxyOrderTest extends TestCase
{
    public function testCreateAdminProxyOrderBuildsThePlacementFromTheQuote(): void
    {
        $catalogPort = $this->createStub(StorefrontCatalogPort::class);
        $pricingPort = $this->createStub(StorefrontPricingPort::class);

        $catalogPort->method('getBySlug')
            ->willReturn(new StorefrontProductSnapshot(
                id: '01926b00-0000-7000-8000-000000000010',
                slug: 'baignoire-d-code',
                name: 'Baignoire D-Code',
                priceMinor: 120000,
                stock: 'in_stock',
                variants: [],
            ));

        $pricingPort->method('deliveryPolicy')->willReturn(new DeliveryPolicy(15000, 1000000));
        $pricingPort->method('promoBasisPoints')->willReturn(null);

        $proxyOrders = new InMemoryProxyOrders();

        $handler = new CreateAdminProxyOrderHandler(
            $proxyOrders,
            new StorefrontQuoteService($catalogPort, $pricingPort),
            $this->createStub(StockReservationPort::class),
            $this->createStub(PaymentInitiationPort::class),
        );
        $command = new CreateAdminProxyOrder(
            orderId: '01926b00-0000-7000-8000-000000000001',
            orderNumber: 'ZG-WA-20261017-001',
            orderSource: OrderSource::WHATSAPP,
            customerName: 'Foulen Ben Foulen',
            customerPhone: '+216 98 123 456',
            shippingAddress: '15 Avenue Habib Bourguiba, Tunis',
            lines: [['slug' => 'baignoire-d-code', 'quantity' => 1]],
            deliveryMethod: 'home',
            paymentMethod: 'Virement bancaire / WhatsApp',
            createdByAdminId: 'admin-01',
            adminNotes: 'Client a commandé par message WhatsApp, confirmation par virement',
        );

        $handler($command);

        $created = $proxyOrders->created;
        self::assertNotNull($created, 'the proxy order is handed to the store exactly once');
        self::assertSame('01926b00-0000-7000-8000-000000000001', $created->orderId);
        self::assertNotSame('', $created->guestCustomerId, 'the synthetic guest id is generated');
        self::assertSame('WHATSAPP', $created->orderSource);
        self::assertSame('Foulen Ben Foulen', $created->customerName);
        self::assertSame('+216 98 123 456', $created->customerPhone);
        self::assertSame('admin-01', $created->createdByAdminId);
        self::assertSame(120000, $created->subtotalMinor);
        self::assertSame(15000, $created->deliveryMinor, 'home delivery under the threshold is charged');
        self::assertSame(135000, $created->totalMinor);
        self::assertSame('TND', $created->currency);
        self::assertCount(1, $created->lines);
        self::assertSame(120000, $created->lines[0]['unitPriceMinor']);
    }

    public function testTransitionThrowsOptimisticLockExceptionWhenVersionMismatches(): void
    {
        $states = new InMemoryOrderState();
        $states->state = new OrderState(OrderStatus::PENDING, 2);
        $handler = new TransitionOrderStatusHandler($states, $states);

        $command = new TransitionOrderStatus(
            orderId: '01926b00-0000-7000-8000-000000000001',
            expectedVersion: 1, // Stale version! Current is 2
            targetStatus: OrderStatus::PAID,
            changedBy: 'admin-01',
            reason: 'Payment verified offline',
        );

        $this->expectException(OptimisticLockException::class);
        $this->expectExceptionMessage('Conflict: order version mismatch');

        $handler($command);
    }

    public function testTransitionThrowsOnIllegalStateMove(): void
    {
        $states = new InMemoryOrderState();
        $states->state = new OrderState(OrderStatus::PENDING, 1);
        $handler = new TransitionOrderStatusHandler($states, $states);

        $command = new TransitionOrderStatus(
            orderId: '01926b00-0000-7000-8000-000000000001',
            expectedVersion: 1,
            targetStatus: OrderStatus::SHIPPED, // Illegal jump from PENDING to SHIPPED!
            changedBy: 'admin-01',
            reason: 'Shipped directly without payment',
        );

        $this->expectException(IllegalOrderStateTransitionException::class);

        try {
            $handler($command);
        } finally {
            self::assertNull($states->lastTransition, 'an illegal move writes nothing');
            self::assertNull($states->lastAudit, 'an illegal move audits nothing');
        }
    }
}

/**
 * Inline fake (TDD-008) of the proxy-order store.
 */
final class InMemoryProxyOrders implements AdminProxyOrderStore
{
    public ?AdminProxyOrderData $created = null;

    public function create(AdminProxyOrderData $data): void
    {
        $this->created = $data;
    }
}

/**
 * Inline fake (TDD-008) of the order-state ports.
 */
final class InMemoryOrderState implements OrderStateReader, OrderTransitionStore
{
    public ?OrderState $state = null;

    public bool $transitionOk = true;

    /** @var array{orderId: string, expectedVersion: int, target: OrderStatus, newVersion: int}|null */
    public ?array $lastTransition = null;

    /** @var array{orderId: string, from: OrderStatus, to: OrderStatus, changedBy: string, reason: string}|null */
    public ?array $lastAudit = null;

    public function find(string $orderId): ?OrderState
    {
        return $this->state;
    }

    public function transition(string $orderId, int $expectedVersion, OrderStatus $target, int $newVersion): bool
    {
        $this->lastTransition = ['orderId' => $orderId, 'expectedVersion' => $expectedVersion, 'target' => $target, 'newVersion' => $newVersion];

        return $this->transitionOk;
    }

    public function appendAudit(
        string $orderId,
        OrderStatus $from,
        OrderStatus $to,
        int $fromVersion,
        int $toVersion,
        string $changedBy,
        string $reason,
    ): void {
        $this->lastAudit = ['orderId' => $orderId, 'from' => $from, 'to' => $to, 'changedBy' => $changedBy, 'reason' => $reason];
    }
}

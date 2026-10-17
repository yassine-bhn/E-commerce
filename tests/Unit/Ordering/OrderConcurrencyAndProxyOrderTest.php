<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Command\CreateAdminProxyOrder;
use App\Ordering\Application\Command\TransitionOrderStatus;
use App\Ordering\Application\CommandHandler\CreateAdminProxyOrderHandler;
use App\Ordering\Application\CommandHandler\TransitionOrderStatusHandler;
use App\Ordering\Domain\Model\IllegalOrderStateTransitionException;
use App\Ordering\Domain\Model\OptimisticLockException;
use App\Ordering\Domain\Model\OrderSource;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Application\Service\StorefrontQuoteService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OrderConcurrencyAndProxyOrderTest extends TestCase
{
    public function testCreateAdminProxyOrderInsertsWithVersion1AndOrderSource(): void
    {
        $connection = $this->createMock(Connection::class);
        $catalogPort = $this->createStub(\App\Shared\Application\Port\StorefrontCatalogPort::class);
        $pricingPort = $this->createStub(\App\Shared\Application\Port\StorefrontPricingPort::class);

        $catalogPort->method('getBySlug')
            ->willReturn(new \App\Shared\Application\Dto\StorefrontProductSnapshot(
                id: '01926b00-0000-7000-8000-000000000010',
                slug: 'baignoire-d-code',
                name: 'Baignoire D-Code',
                priceMinor: 120000,
                stock: 'in_stock',
                variants: [],
            ));

        $pricingPort->method('deliveryPolicy')->willReturn(new \App\Shared\Domain\Storefront\DeliveryPolicy(15000, 1000000));
        $pricingPort->method('promoBasisPoints')->willReturn(null);

        $quoteService = new StorefrontQuoteService($catalogPort, $pricingPort);

        $connection->expects(self::exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params): int {
                if (str_contains($sql, 'INSERT INTO ordering_orders')) {
                    self::assertSame('WHATSAPP', $params['order_source']);
                    self::assertSame('待支付', $params['status']);
                    self::assertSame(1, $params['version']);
                    self::assertSame('Foulen Ben Foulen', $params['customer_name']);
                    self::assertSame('+216 98 123 456', $params['customer_phone']);
                    self::assertSame('admin-01', $params['created_by_admin_id']);
                }

                return 1;
            });

        $handler = new CreateAdminProxyOrderHandler($connection, $quoteService);
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
    }

    public function testTransitionThrowsOptimisticLockExceptionWhenVersionMismatches(): void
    {
        $connection = $this->createMock(Connection::class);

        // First query gets current status and version
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['status' => '待支付', 'version' => 2]);

        $handler = new TransitionOrderStatusHandler($connection);
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
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['status' => '待支付', 'version' => 1]);

        $handler = new TransitionOrderStatusHandler($connection);
        $command = new TransitionOrderStatus(
            orderId: '01926b00-0000-7000-8000-000000000001',
            expectedVersion: 1,
            targetStatus: OrderStatus::SHIPPED, // Illegal jump from PENDING to SHIPPED!
            changedBy: 'admin-01',
            reason: 'Shipped directly without payment',
        );

        $this->expectException(IllegalOrderStateTransitionException::class);

        $handler($command);
    }
}

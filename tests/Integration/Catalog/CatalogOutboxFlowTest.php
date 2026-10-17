<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Command\RegisterProduct;
use App\Catalog\Application\Query\GetProduct;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class CatalogOutboxFlowTest extends KernelTestCase
{
    public function testRegisterProductProjectsReadModelViaOutbox(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1 FROM outbox LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }

        $productId = '22222222-2222-2222-2222-222222222222';
        $connection->executeStatement('DELETE FROM catalog_product_view WHERE id = :id', ['id' => $productId]);
        $connection->executeStatement('DELETE FROM catalog_products WHERE id = :id', ['id' => $productId]);

        /** @var MessageBusInterface $commandBus */
        $commandBus = $container->get('messenger.bus.command');
        $commandBus->dispatch(new RegisterProduct($productId, 'Integration Widget', 2500, 'EUR'));

        /** @var OutboxRelay $relay */
        $relay = $container->get(OutboxRelay::class);
        $relay->publishBatch();

        /** @var MessageBusInterface $queryBus */
        $queryBus = $container->get('messenger.bus.query');
        $envelope = $queryBus->dispatch(new GetProduct($productId, 'tester', true));
        $handled = $envelope->last(HandledStamp::class);
        self::assertNotNull($handled);
        self::assertSame('Integration Widget', $handled->getResult()->name);
    }
}

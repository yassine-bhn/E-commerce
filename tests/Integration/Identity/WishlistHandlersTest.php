<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Command\AddWishlistItem;
use App\Identity\Application\Command\RemoveWishlistItem;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SRP-001 split (Task 2.3) — both wishlist commands are dispatched through the real
 * command.bus against PostgreSQL and keep their store semantics: a duplicate add is a no-op
 * (ON CONFLICT DO NOTHING) and a removal deletes only the matching (customer, slug) row.
 */
final class WishlistHandlersTest extends KernelTestCase
{
    private const TABLE = 'storefront_wishlist_item';

    /** @var list<string> */
    private array $createdCustomerIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdCustomerIds) {
            self::connection()->executeStatement(
                sprintf('DELETE FROM %s WHERE customer_id IN (:ids)', self::TABLE),
                ['ids' => $this->createdCustomerIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        $this->createdCustomerIds = [];

        parent::tearDown();
    }

    public function testAddWishlistItemInsertsTheRowThroughTheBus(): void
    {
        $customerId = $this->newCustomerId();

        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: 'fauteuil'));

        $count = self::connection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE customer_id = :customer AND product_slug = :slug', self::TABLE),
            ['customer' => $customerId, 'slug' => 'fauteuil'],
        );
        self::assertSame(1, (int) $count, 'the wishlist row is written');
    }

    public function testAddingTheSameSlugTwiceIsANoOp(): void
    {
        $customerId = $this->newCustomerId();

        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: 'fauteuil'));
        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: 'fauteuil'));

        $count = self::connection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE customer_id = :customer AND product_slug = :slug', self::TABLE),
            ['customer' => $customerId, 'slug' => 'fauteuil'],
        );
        self::assertSame(1, (int) $count, 'ON CONFLICT DO NOTHING keeps one row');
    }

    public function testRemoveWishlistItemDeletesTheMatchingRow(): void
    {
        $customerId = $this->newCustomerId();

        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: 'fauteuil'));
        $this->commandBus()->dispatch(new RemoveWishlistItem(customerId: $customerId, productSlug: 'fauteuil'));

        $count = self::connection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE customer_id = :customer AND product_slug = :slug', self::TABLE),
            ['customer' => $customerId, 'slug' => 'fauteuil'],
        );
        self::assertSame(0, (int) $count, 'the wishlist row is deleted');
    }

    public function testRemoveWishlistItemLeavesOtherSlugsAlone(): void
    {
        $customerId = $this->newCustomerId();

        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: 'fauteuil'));
        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: 'pouf'));
        $this->commandBus()->dispatch(new RemoveWishlistItem(customerId: $customerId, productSlug: 'fauteuil'));

        $count = self::connection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE customer_id = :customer AND product_slug = :slug', self::TABLE),
            ['customer' => $customerId, 'slug' => 'pouf'],
        );
        self::assertSame(1, (int) $count, 'only the targeted slug is removed');
    }

    private function newCustomerId(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $id;

        return $id;
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }

    private function commandBus(): MessageBusInterface
    {
        self::bootKernel();

        $bus = static::getContainer()->get('messenger.bus.command');
        assert($bus instanceof MessageBusInterface);

        return $bus;
    }
}

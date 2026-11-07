<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Command\AddWishlistItem;
use App\Identity\Application\Command\RemoveWishlistItem;
use App\Shared\Domain\Storefront\ProductNotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SRP-001 split (Task 2.3) + Task 3.1 existence check — both wishlist commands are dispatched
 * through the real command.bus against PostgreSQL and keep their store semantics: an unknown
 * product is refused, a duplicate add is a no-op (ON CONFLICT DO NOTHING) and a removal deletes
 * only the matching (customer, slug) row.
 */
final class WishlistHandlersTest extends KernelTestCase
{
    private const string TABLE = 'storefront_wishlist_item';

    private const string CATEGORY_SLUG = 'wishlist-adapter-category';

    private const string PRODUCT_SLUG = 'wishlist-adapter-product';

    private const string OTHER_PRODUCT_SLUG = 'wishlist-adapter-other';

    /** @var list<string> */
    private array $createdCustomerIds = [];

    protected function setUp(): void
    {
        $connection = self::connection();

        // the handler refuses unknown products, so the add cases run against visible products
        $connection->executeStatement(
            "INSERT INTO storefront_category (slug, label, subcategories, hidden, sort_order, approval_status)
             VALUES (:slug, 'Wishlist Adapter', '[]', false, 9999, 'APPROVED')
             ON CONFLICT (slug) DO UPDATE SET label = EXCLUDED.label",
            ['slug' => self::CATEGORY_SLUG],
        );

        $this->insertProduct(self::PRODUCT_SLUG, 'Fauteuil Adapter');
        $this->insertProduct(self::OTHER_PRODUCT_SLUG, 'Pouf Adapter');
    }

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->createdCustomerIds) {
            $connection->executeStatement(
                sprintf('DELETE FROM %s WHERE customer_id IN (:ids)', self::TABLE),
                ['ids' => $this->createdCustomerIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        $connection->executeStatement(
            'DELETE FROM storefront_product_view WHERE slug IN (:slugs)',
            ['slugs' => [self::PRODUCT_SLUG, self::OTHER_PRODUCT_SLUG]],
            ['slugs' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $connection->executeStatement(
            'DELETE FROM storefront_category WHERE slug = :slug',
            ['slug' => self::CATEGORY_SLUG],
        );

        $this->createdCustomerIds = [];

        parent::tearDown();
    }

    public function testAddWishlistItemInsertsTheRowThroughTheBus(): void
    {
        $customerId = $this->newCustomerId();

        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: self::PRODUCT_SLUG));

        self::assertSame(1, $this->wishlistCount($customerId, self::PRODUCT_SLUG), 'the wishlist row is written');
    }

    public function testAddingAnUnknownProductIsRefusedThroughTheBus(): void
    {
        $customerId = $this->newCustomerId();

        try {
            $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: 'no-such-product'));
            self::fail('an unknown product must be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(ProductNotFoundException::class, $exception->getPrevious());
        }

        self::assertSame(
            0,
            $this->wishlistCount($customerId, 'no-such-product'),
            'nothing is written for an unknown product',
        );
    }

    public function testAddingTheSameSlugTwiceIsANoOp(): void
    {
        $customerId = $this->newCustomerId();

        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: self::PRODUCT_SLUG));
        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: self::PRODUCT_SLUG));

        self::assertSame(1, $this->wishlistCount($customerId, self::PRODUCT_SLUG), 'ON CONFLICT DO NOTHING keeps one row');
    }

    public function testRemoveWishlistItemDeletesTheMatchingRow(): void
    {
        $customerId = $this->newCustomerId();

        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: self::PRODUCT_SLUG));
        $this->commandBus()->dispatch(new RemoveWishlistItem(customerId: $customerId, productSlug: self::PRODUCT_SLUG));

        self::assertSame(0, $this->wishlistCount($customerId, self::PRODUCT_SLUG), 'the wishlist row is deleted');
    }

    public function testRemoveWishlistItemLeavesOtherSlugsAlone(): void
    {
        $customerId = $this->newCustomerId();

        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: self::PRODUCT_SLUG));
        $this->commandBus()->dispatch(new AddWishlistItem(customerId: $customerId, productSlug: self::OTHER_PRODUCT_SLUG));
        $this->commandBus()->dispatch(new RemoveWishlistItem(customerId: $customerId, productSlug: self::PRODUCT_SLUG));

        self::assertSame(0, $this->wishlistCount($customerId, self::PRODUCT_SLUG), 'the targeted slug is removed');
        self::assertSame(1, $this->wishlistCount($customerId, self::OTHER_PRODUCT_SLUG), 'the other slug survives');
    }

    private function insertProduct(string $slug, string $name): void
    {
        self::connection()->executeStatement(
            'DELETE FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );
        self::connection()->insert('storefront_product_view', [
            'id' => Uuid::v7()->toRfc4122(),
            'slug' => $slug,
            'name' => $name,
            'brand' => 'Atelier Adapter',
            'ref' => 'WL-ADAPTER',
            'category_slug' => self::CATEGORY_SLUG,
            'subcategory' => 'Test',
            'price_minor' => 2500,
            'old_price_minor' => null,
            'currency' => 'TND',
            'stock' => 'in',
            'material' => 'Bois',
            'dims' => '10 x 20 cm',
            'image_path' => '/uploads/products/wishlist-adapter.jpg',
            'rating' => '4.5',
            'reviews' => 2,
            'is_new' => false,
            'is_best' => false,
            'headrest' => false,
            'description' => $name.' description',
            'approval_status' => 'APPROVED',
            'published' => true,
        ], [
            'rating' => ParameterType::STRING,
            'reviews' => ParameterType::INTEGER,
            'price_minor' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
            'published' => ParameterType::BOOLEAN,
        ]);
    }

    private function newCustomerId(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $id;

        return $id;
    }

    private function wishlistCount(string $customerId, string $slug): int
    {
        return (int) self::connection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE customer_id = :customer AND product_slug = :slug', self::TABLE),
            ['customer' => $customerId, 'slug' => $slug],
        );
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }

    private function commandBus(): MessageBusInterface
    {
        self::bootKernel();

        $bus = static::getContainer()->get('messenger.bus.command');
        \assert($bus instanceof MessageBusInterface);

        return $bus;
    }
}

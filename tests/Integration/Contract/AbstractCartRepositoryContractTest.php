<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contract;

use App\Cart\Domain\Model\Cart;
use App\Cart\Domain\Model\CartLineId;
use App\Cart\Domain\Repository\CartRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * LSP-001 — the contract every CartRepository adapter must honour (solid Task 5.1):
 * a missing cart reads null, save/find round-trips with line identity, and a repeat save
 * updates rather than duplicates.
 */
abstract class AbstractCartRepositoryContractTest extends KernelTestCase
{
    /** @var list<string> */
    protected array $createdCustomerIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function repository(): CartRepository
    {
        $repository = static::getContainer()->get(CartRepository::class);
        \assert($repository instanceof CartRepository);

        return $repository;
    }

    protected function newCart(string $customerId, string $slug, int $quantity): Cart
    {
        $cart = Cart::create($customerId);
        $cart->addItem(new CartLineId(Uuid::v7()->toRfc4122()), $slug, null, $quantity, 2500, 'TND');

        return $cart;
    }

    protected function cleanup(string $customerId): void
    {
        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement(
            'DELETE FROM storefront_cart_item WHERE cart_id IN (SELECT id FROM storefront_cart WHERE customer_id = :customer)',
            ['customer' => $customerId],
        );
        $connection->executeStatement(
            'DELETE FROM storefront_cart WHERE customer_id = :customer',
            ['customer' => $customerId],
        );
    }

    public function testFindByCustomerIdAnswersNullForAMissingCart(): void
    {
        $customerId = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $customerId;

        self::assertNull($this->repository()->findByCustomerId($customerId));
    }

    public function testSaveThenFindRoundTripsTheLinesWithTheirIdentity(): void
    {
        $customerId = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $customerId;
        $cart = $this->newCart($customerId, 'fauteuil', 2);

        $this->repository()->save($cart);

        $read = $this->repository()->findByCustomerId($customerId);
        self::assertNotNull($read);
        self::assertSame($cart->id(), $read->id());
        self::assertCount(1, $read->lines());
        self::assertSame('fauteuil', $read->lines()[0]->productSlug());
        self::assertSame(2, $read->lines()[0]->quantity());
        self::assertSame($cart->lines()[0]->id()->toString(), $read->lines()[0]->id()->toString(), 'line identity survives the round-trip');
    }

    public function testASecondSaveUpdatesInsteadOfDuplicatingTheLines(): void
    {
        $customerId = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $customerId;
        $cart = $this->newCart($customerId, 'fauteuil', 2);

        $this->repository()->save($cart);
        $read = $this->repository()->findByCustomerId($customerId);
        self::assertNotNull($read);
        $read->lines()[0]->changeQuantity(5);
        $this->repository()->save($read);

        $read = $this->repository()->findByCustomerId($customerId);
        self::assertNotNull($read);
        self::assertSame(5, $read->lines()[0]->quantity(), 'the quantity update persists');
        self::assertCount(1, $read->lines(), 'a second save updates the line instead of duplicating it');
    }
}

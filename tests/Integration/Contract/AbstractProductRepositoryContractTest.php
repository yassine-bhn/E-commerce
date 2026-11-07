<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contract;

use App\Catalog\Domain\Model\Product;
use App\Catalog\Domain\Model\ProductId;
use App\Catalog\Domain\Repository\ProductRepository;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * LSP-001 — the contract every ProductRepository adapter must honour (solid Task 5.1):
 * save then get returns the same aggregate, get after changePrice reflects the new price,
 * a missing id throws, and a second save updates rather than duplicates.
 */
abstract class AbstractProductRepositoryContractTest extends KernelTestCase
{
    /** @var list<string> */
    protected array $createdProductIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function repository(): ProductRepository
    {
        $repository = static::getContainer()->get(ProductRepository::class);
        \assert($repository instanceof ProductRepository);

        return $repository;
    }

    protected function newProduct(string $productId, string $name, int $priceMinor): Product
    {
        return Product::create(
            new ProductId($productId),
            $name,
            Money::ofMinor($priceMinor, Currency::TND),
        );
    }

    protected function cleanup(string $productId): void
    {
        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement('DELETE FROM catalog_products WHERE id = :id', ['id' => $productId]);
    }

    public function testSaveThenGetReturnsTheSameAggregate(): void
    {
        $productId = Uuid::v7()->toRfc4122();
        $this->createdProductIds[] = $productId;

        $this->repository()->save($this->newProduct($productId, 'Fauteuil Contract', 12900));

        $read = $this->repository()->get(new ProductId($productId));

        self::assertSame($productId, $read->id()->toString());
        self::assertSame(12900, $read->price()->minorUnits());
    }

    public function testGetAfterChangePriceReflectsTheNewPrice(): void
    {
        $productId = Uuid::v7()->toRfc4122();
        $this->createdProductIds[] = $productId;
        $product = $this->newProduct($productId, 'Fauteuil Reprice', 12900);

        $product->changePrice(Money::ofMinor(9900, Currency::TND));
        $this->repository()->save($product);

        $read = $this->repository()->get(new ProductId($productId));
        self::assertSame(9900, $read->price()->minorUnits(), 'the changed price survives the round-trip');
    }

    public function testGetOnAMissingIdThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->repository()->get(new ProductId(Uuid::v7()->toRfc4122()));
    }

    public function testSaveTwiceUpdatesRatherThanDuplicates(): void
    {
        $productId = Uuid::v7()->toRfc4122();
        $this->createdProductIds[] = $productId;
        $product = $this->newProduct($productId, 'Fauteuil Once', 12900);

        $this->repository()->save($product);
        $this->repository()->save($product);

        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM catalog_products WHERE id = :id',
            ['id' => $productId],
        );
        self::assertSame(1, (int) $count, 'a second save updates the row instead of duplicating it');
    }
}

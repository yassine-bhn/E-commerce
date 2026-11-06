<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Query\ListAdminProducts;
use App\Catalog\Infrastructure\ReadModel\AdminProductListItem;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductRepository;
use App\Shared\Application\Pagination\Page;
use App\Shared\Application\Pagination\PageRequest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-17 WP2 / R9-R10, R12 — the admin reads carry the pricing fields while the
 * customer-facing read model never selects them.
 */
final class AdminProductPricingReadTest extends KernelTestCase
{
    private string $token;

    /** @var list<string> */
    private array $productSlugs = [];

    /** @var list<string> */
    private array $categorySlugs = [];

    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        try {
            self::db()->executeQuery('SELECT vat_rate, sale_price_ttc_minor FROM storefront_product_view LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }

        $this->token = 'it'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $connection = self::db();

        if ([] !== $this->productSlugs) {
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug IN (:v)', ['v' => $this->productSlugs], ['v' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->categorySlugs) {
            $connection->executeStatement('DELETE FROM storefront_category WHERE slug IN (:v)', ['v' => $this->categorySlugs], ['v' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->userIds) {
            $connection->executeStatement('DELETE FROM customer_profile WHERE id IN (:v)', ['v' => $this->userIds], ['v' => ArrayParameterType::STRING]);
        }

        $this->productSlugs = [];
        $this->categorySlugs = [];
        $this->userIds = [];

        parent::tearDown();
    }

    public function testTheAdminListAndDetailReturnTheStoredPricingFields(): void
    {
        $adminId = $this->createAdmin();
        $slug = $this->insertProduct('pricing-'.$this->token, 19.5, 12345);

        $page = $this->listProducts($adminId);
        self::assertSame(1, $page->total);
        $item = $page->items[0];
        self::assertInstanceOf(AdminProductListItem::class, $item);
        self::assertSame(19.5, $item->product->vatRate);
        self::assertSame(12345, $item->product->salePriceTtc);
        self::assertSame(19.5, $item->toArray()['vatRate']);
        self::assertSame(12345, $item->toArray()['salePriceTtc']);

        $detail = $this->repository()->adminBySlug($slug);
        self::assertNotNull($detail);
        self::assertSame(19.5, $detail->vatRate);
        self::assertSame(12345, $detail->salePriceTtc);
        self::assertSame(19.5, $detail->toAdminApi()['vatRate']);
        self::assertSame(12345, $detail->toAdminApi()['salePriceTtc']);
    }

    public function testTheAdminListAndDetailReturnAnExplicitNullWhenThePricingFieldsAreUnset(): void
    {
        $adminId = $this->createAdmin();
        $slug = $this->insertProduct('pricing-null-'.$this->token, null, null);

        $page = $this->listProducts($adminId);
        self::assertSame(1, $page->total);
        $item = $page->items[0];
        self::assertInstanceOf(AdminProductListItem::class, $item);
        $row = $item->toArray();
        self::assertArrayHasKey('vatRate', $row);
        self::assertNull($row['vatRate']);
        self::assertArrayHasKey('salePriceTtc', $row);
        self::assertNull($row['salePriceTtc']);

        $detail = $this->repository()->adminBySlug($slug);
        self::assertNotNull($detail);
        self::assertArrayHasKey('vatRate', $detail->toAdminApi());
        self::assertNull($detail->toAdminApi()['vatRate']);
        self::assertArrayHasKey('salePriceTtc', $detail->toAdminApi());
        self::assertNull($detail->toAdminApi()['salePriceTtc']);
    }

    public function testThePublicDetailNeverSelectsThePricingFields(): void
    {
        $this->insertCategory('cat-'.$this->token);
        $slug = $this->insertProduct('pricing-public-'.$this->token, 19.5, 12345);

        $detail = $this->repository()->bySlug($slug);
        self::assertNotNull($detail);
        self::assertNull($detail->vatRate, 'the public SELECT does not load the admin-only column');
        self::assertNull($detail->salePriceTtc, 'the public SELECT does not load the admin-only column');
        self::assertArrayNotHasKey('vatRate', $detail->toApi());
        self::assertArrayNotHasKey('salePriceTtc', $detail->toApi());
    }

    private function createAdmin(): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->userIds[] = $id;

        self::db()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Pricing reader',
            'email' => sprintf('pricing.%s@example.com', substr($id, 0, 8)),
            'role' => 'ROLE_ADMIN',
            'status' => 'active',
        ]);

        return $id;
    }

    private function insertCategory(string $slug): void
    {
        $this->categorySlugs[] = $slug;

        self::db()->insert('storefront_category', [
            'slug' => $slug,
            'label' => 'Catégorie '.$slug,
            'subcategories' => '[]',
            'quote_eligible' => false,
            'hidden' => false,
            'sort_order' => 999,
            'parent_slug' => null,
            'approval_status' => 'APPROVED',
        ], [
            'quote_eligible' => ParameterType::BOOLEAN,
            'hidden' => ParameterType::BOOLEAN,
            'sort_order' => ParameterType::INTEGER,
        ]);
    }

    private function insertProduct(string $slug, ?float $vatRate, ?int $salePriceTtc): string
    {
        $this->productSlugs[] = $slug;

        self::db()->insert('storefront_product_view', [
            'id' => Uuid::v7()->toRfc4122(),
            'slug' => $slug,
            'name' => 'Produit '.$slug,
            'brand' => 'Sopal',
            'ref' => 'REF-'.$this->token,
            'category_slug' => 'cat-'.$this->token,
            'subcategory' => 'WC',
            'price_minor' => 10_000,
            'old_price_minor' => null,
            'currency' => 'TND',
            'stock' => 'in',
            'stock_quantity' => 4,
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image_path' => '/p-wc.jpg',
            'rating' => 4.5,
            'reviews' => 0,
            'is_new' => false,
            'is_best' => false,
            'headrest' => false,
            'description' => 'Produit de test.',
            'variants' => '[]',
            'approval_status' => 'APPROVED',
            'published' => true,
            'vat_rate' => $vatRate,
            'sale_price_ttc_minor' => $salePriceTtc,
        ], [
            'price_minor' => ParameterType::INTEGER,
            'stock_quantity' => ParameterType::INTEGER,
            'reviews' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
            'published' => ParameterType::BOOLEAN,
            'vat_rate' => ParameterType::STRING,
            'sale_price_ttc_minor' => ParameterType::INTEGER,
        ]);

        return $slug;
    }

    private function listProducts(string $requesterId): Page
    {
        $envelope = $this->queryBus()->dispatch(new ListAdminProducts(
            requesterId: $requesterId,
            requesterIsAdmin: true,
            page: new PageRequest(1, ListAdminProducts::MAX_SIZE, ListAdminProducts::MAX_SIZE),
            q: $this->token,
        ));
        $handled = $envelope->last(HandledStamp::class);
        self::assertNotNull($handled, 'the admin list must be handled on query.bus');
        $result = $handled->getResult();
        self::assertInstanceOf(Page::class, $result);

        return $result;
    }

    private function repository(): StorefrontProductRepository
    {
        $repository = static::getContainer()->get(StorefrontProductRepository::class);
        assert($repository instanceof StorefrontProductRepository);

        return $repository;
    }

    private function queryBus(): MessageBusInterface
    {
        $bus = static::getContainer()->get('messenger.bus.query');
        assert($bus instanceof MessageBusInterface);

        return $bus;
    }

    private static function db(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }
}

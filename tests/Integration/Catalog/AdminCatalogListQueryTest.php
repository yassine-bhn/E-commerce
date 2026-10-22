<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Query\ListAdminBrands;
use App\Catalog\Application\Query\ListCategories;
use App\Catalog\Infrastructure\ReadModel\CategoryView;
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
 * R10/R11 — the admin category and brand lists through the real query.bus against PostgreSQL:
 * a filtered COUNT plus a bounded LIMIT/OFFSET window, deterministic sorts, DTOs only, and the
 * requester visibility kept inside the SQL (QRY-001/QRY-002/QRY-003/QRY-004).
 */
final class AdminCatalogListQueryTest extends KernelTestCase
{
    private string $token;

    /** @var list<string> */
    private array $categorySlugs = [];

    /** @var list<string> */
    private array $brandNames = [];

    /** @var list<string> */
    private array $productSlugs = [];

    protected function setUp(): void
    {
        self::bootKernel();

        try {
            self::db()->executeQuery('SELECT 1 FROM storefront_category LIMIT 1');
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
        if ([] !== $this->brandNames) {
            $connection->executeStatement('DELETE FROM storefront_brand WHERE name IN (:v)', ['v' => $this->brandNames], ['v' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->categorySlugs) {
            $connection->executeStatement('DELETE FROM storefront_category WHERE slug IN (:v)', ['v' => $this->categorySlugs], ['v' => ArrayParameterType::STRING]);
        }

        $this->categorySlugs = [];
        $this->brandNames = [];
        $this->productSlugs = [];

        parent::tearDown();
    }

    public function testTheCategoryPageFiltersSearchesAndSortsOnTheRealDatabase(): void
    {
        $parent = $this->insertCategory('cat-'.$this->token.'-parent', 'Zulu '.$this->token, 900);
        $visible = $this->insertCategory('cat-'.$this->token.'-visible', 'Yankee '.$this->token, 901);
        $hidden = $this->insertCategory('cat-'.$this->token.'-hidden', 'Xray '.$this->token, 902, hidden: true);
        $childOne = $this->insertCategory('cat-'.$this->token.'-child-one', 'Alpha '.$this->token, 903, parentSlug: $parent);
        $childTwo = $this->insertCategory('cat-'.$this->token.'-child-two', 'Bravo '.$this->token, 904, parentSlug: $parent);
        $this->seedProduct('cat-product-'.$this->token, categorySlug: $visible);

        $first = $this->dispatch(new ListCategories(
            requesterId: 'integration-admin',
            isAdmin: true,
            page: new PageRequest(1, 2, ListCategories::MAX_SIZE),
            q: $this->token,
        ));
        self::assertSame(1, $first->page);
        self::assertSame(2, $first->size);
        self::assertSame(5, $first->total, 'the COUNT carries the same free-text predicate as the rows');
        self::assertSame(3, $first->totalPages());
        self::assertTrue($first->hasMore());
        self::assertSame([$parent, $visible], $this->categorySlugsOf($first), 'sort_order is the default sort');

        $productCounts = [];
        foreach ($first->items as $view) {
            self::assertInstanceOf(CategoryView::class, $view, 'QRY-002 — the page carries read DTOs, never entities');
            $productCounts[$view->slug] = $view->productCount;
        }
        self::assertSame(1, $productCounts[$visible], 'the product_count sub-select survives the pagination');
        self::assertSame(0, $productCounts[$parent]);

        $second = $this->dispatch(new ListCategories('integration-admin', true, new PageRequest(2, 2, ListCategories::MAX_SIZE), q: $this->token));
        self::assertSame([$hidden, $childOne], $this->categorySlugsOf($second));

        $third = $this->dispatch(new ListCategories('integration-admin', true, new PageRequest(3, 2, ListCategories::MAX_SIZE), q: $this->token));
        self::assertSame([$childTwo], $this->categorySlugsOf($third));
        self::assertFalse($third->hasMore());

        $hiddenOnly = $this->dispatch(new ListCategories('integration-admin', true, $this->maxPage(), q: $this->token, hidden: true));
        self::assertSame(1, $hiddenOnly->total);
        self::assertSame([$hidden], $this->categorySlugsOf($hiddenOnly));

        $visibleOnly = $this->dispatch(new ListCategories('integration-admin', true, $this->maxPage(), q: $this->token, hidden: false));
        self::assertSame(4, $visibleOnly->total);
        self::assertSame([$parent, $visible, $childOne, $childTwo], $this->categorySlugsOf($visibleOnly));

        $children = $this->dispatch(new ListCategories('integration-admin', true, $this->maxPage(), q: $this->token, parent: $parent));
        self::assertSame(2, $children->total);
        self::assertSame([$childOne, $childTwo], $this->categorySlugsOf($children), 'parent returns the direct children only');

        $byLabel = $this->dispatch(new ListCategories('integration-admin', true, $this->maxPage(), q: $this->token, sort: 'label'));
        self::assertSame([$childOne, $childTwo, $hidden, $visible, $parent], $this->categorySlugsOf($byLabel));

        $byCount = $this->dispatch(new ListCategories('integration-admin', true, $this->maxPage(), q: $this->token, sort: 'productCount'));
        self::assertSame([$visible, $childOne, $childTwo, $hidden, $parent], $this->categorySlugsOf($byCount), 'ties on the product count fall back to the slug');

        $customer = $this->dispatch(new ListCategories('integration-customer', false, $this->maxPage(), q: $this->token));
        self::assertSame(4, $customer->total, 'the hidden row is filtered in the COUNT too');
        self::assertSame([$parent, $visible, $childOne, $childTwo], $this->categorySlugsOf($customer));
    }

    public function testTheBrandPageFiltersOnSearchAndSortsByTheProductAggregate(): void
    {
        $alpha = 'Alpha'.$this->token;
        $beta = 'Beta'.$this->token;
        $gamma = 'Gamma'.$this->token;
        foreach ([$alpha, $beta, $gamma] as $name) {
            $this->insertBrand($name);
        }
        $this->seedProduct('brand-a-'.$this->token, $beta);
        $this->seedProduct('brand-b-'.$this->token, $gamma);
        $this->seedProduct('brand-c-'.$this->token, $gamma);

        $first = $this->dispatch(new ListAdminBrands(new PageRequest(1, 2, ListAdminBrands::MAX_SIZE), q: $this->token));
        self::assertSame(3, $first->total);
        self::assertSame(2, $first->size);
        self::assertTrue($first->hasMore());
        self::assertSame([
            ['name' => $alpha, 'productCount' => 0],
            ['name' => $beta, 'productCount' => 1],
        ], $first->items, 'name is the default sort and the aggregate reaches the page as an int');

        $second = $this->dispatch(new ListAdminBrands(new PageRequest(2, 2, ListAdminBrands::MAX_SIZE), q: $this->token));
        self::assertSame([['name' => $gamma, 'productCount' => 2]], $second->items);
        self::assertFalse($second->hasMore());

        $byCount = $this->dispatch(new ListAdminBrands($this->maxPage(), q: $this->token, sort: 'productCount'));
        self::assertSame([$gamma, $beta, $alpha], array_column($byCount->items, 'name'));

        $none = $this->dispatch(new ListAdminBrands($this->maxPage(), q: 'zzz'.$this->token));
        self::assertSame(0, $none->total);
        self::assertSame([], $none->items);
    }

    private function maxPage(): PageRequest
    {
        return new PageRequest(1, ListCategories::MAX_SIZE, ListCategories::MAX_SIZE);
    }

    /**
     * @return list<string>
     */
    private function categorySlugsOf(Page $page): array
    {
        return array_values(array_map(static fn (CategoryView $view): string => $view->slug, $page->items));
    }

    private function dispatch(object $query): Page
    {
        $envelope = $this->queryBus()->dispatch($query);
        $handled = $envelope->last(HandledStamp::class);
        self::assertNotNull($handled, 'the query must be handled on query.bus');

        $result = $handled->getResult();
        self::assertInstanceOf(Page::class, $result, 'QRY-002 — one page DTO, never a raw row list');

        return $result;
    }

    private function insertCategory(string $slug, string $label, int $sortOrder, bool $hidden = false, ?string $parentSlug = null): string
    {
        $this->categorySlugs[] = $slug;

        self::db()->insert('storefront_category', [
            'slug' => $slug,
            'label' => $label,
            'subcategories' => '[]',
            'quote_eligible' => false,
            'hidden' => $hidden,
            'sort_order' => $sortOrder,
            'parent_slug' => $parentSlug,
        ], [
            'quote_eligible' => ParameterType::BOOLEAN,
            'hidden' => ParameterType::BOOLEAN,
            'sort_order' => ParameterType::INTEGER,
        ]);

        return $slug;
    }

    private function insertBrand(string $name): void
    {
        $this->brandNames[] = $name;
        self::db()->insert('storefront_brand', ['name' => $name]);
    }

    private function seedProduct(string $slug, ?string $brand = null, string $categorySlug = 'sanitaires'): void
    {
        $this->productSlugs[] = $slug;

        self::db()->insert('storefront_product_view', [
            'id' => Uuid::v4()->toRfc4122(),
            'slug' => $slug,
            'name' => 'Produit '.$slug,
            'brand' => $brand ?? 'Brand'.$this->token,
            'ref' => 'TST-'.$this->token,
            'category_slug' => $categorySlug,
            'subcategory' => 'WC',
            'price_minor' => 10_000,
            'old_price_minor' => null,
            'currency' => 'TND',
            'stock' => 'in',
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image_path' => '/p-wc.jpg',
            'rating' => 0,
            'reviews' => 0,
            'is_new' => false,
            'is_best' => false,
            'headrest' => false,
            'description' => 'Seed for the admin catalog list.',
            'variants' => '[]',
        ], [
            'price_minor' => ParameterType::INTEGER,
            'reviews' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
        ]);
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

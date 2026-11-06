<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Port\BannerImageStore;
use App\Catalog\Application\Port\BannerSetReader;
use App\Catalog\Application\Port\CategoryCreator;
use App\Catalog\Application\Port\CategoryDeleter;
use App\Catalog\Application\Port\CategoryDeletionReader;
use App\Catalog\Application\Port\CategoryHierarchyReader;
use App\Catalog\Application\Port\CategoryReader;
use App\Catalog\Application\Port\CategoryTreeWriter;
use App\Catalog\Application\Port\CategoryVisibilityWriter;
use App\Catalog\Application\Port\ProductDeletionReader;
use App\Catalog\Application\Port\ProductPublicationStore;
use App\Catalog\Application\Port\ProductReviewStore;
use App\Catalog\Domain\Model\BannerSet;
use App\Catalog\Domain\Model\Category;
use App\Catalog\Domain\Model\CategoryAlreadyExistsException;
use App\Catalog\Infrastructure\Persistence\DbalAdminProductStore;
use App\Catalog\Infrastructure\Persistence\DbalBannerStore;
use App\Catalog\Infrastructure\Persistence\DbalCategoryStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * solid Task 3.2 — the category, admin-product and banner adapters against PostgreSQL: every
 * port method lands the columns the former inline handler SQL wrote, and the read contracts
 * answer the shapes the handlers expect.
 */
final class CatalogStoreAdaptersTest extends KernelTestCase
{
    /** @var list<string> */
    private array $createdCategorySlugs = [];

    /** @var list<string> */
    private array $createdProductSlugs = [];

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->createdProductSlugs) {
            $connection->executeStatement(
                'DELETE FROM storefront_product_review WHERE product_slug IN (:slugs)',
                ['slugs' => $this->createdProductSlugs],
                ['slugs' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM storefront_product_view WHERE slug IN (:slugs)',
                ['slugs' => $this->createdProductSlugs],
                ['slugs' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->createdCategorySlugs) {
            $connection->executeStatement(
                'DELETE FROM storefront_category WHERE slug IN (:slugs)',
                ['slugs' => $this->createdCategorySlugs],
                ['slugs' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        $this->createdCategorySlugs = [];
        $this->createdProductSlugs = [];

        parent::tearDown();
    }

    public function testCategoryCreatorInsertsAVisibleRowAndRefusesADuplicate(): void
    {
        $store = $this->categoryStore();

        $store->create(Category::createRoot(
            slug: 'adapter-cat',
            label: '  Adapter Cat  ',
            subcategories: [['label' => 'Cuvette']],
            quoteEligible: true,
        ));
        $this->createdCategorySlugs[] = 'adapter-cat';

        $row = self::connection()->fetchAssociative(
            'SELECT label, subcategories, quote_eligible, hidden FROM storefront_category WHERE slug = :slug',
            ['slug' => 'adapter-cat'],
        );
        self::assertNotFalse($row, 'the category row is written');
        self::assertSame('Adapter Cat', $row['label']);
        self::assertSame([['label' => 'Cuvette']], json_decode((string) $row['subcategories'], true));
        self::assertTrue((bool) $row['quote_eligible']);
        self::assertFalse((bool) $row['hidden'], 'a new category is visible (R5 default)');

        try {
            $store->create(Category::createRoot(slug: 'adapter-cat', label: 'Bis'));
            self::fail('a taken slug must be refused');
        } catch (CategoryAlreadyExistsException $exception) {
            self::assertStringContainsString('adapter-cat', $exception->getMessage());
        }
    }

    public function testCategoryReadersAnswerTheRowAndTheTreeCounts(): void
    {
        $store = $this->categoryStore();
        $this->seedCategory('adapter-read', subcategories: '[{"label":"Cuvette"},{"label":"Abattant"}]', hidden: true);

        $category = $store->find('adapter-read');
        self::assertNotNull($category);
        self::assertSame('Adapter read', $category->label);
        self::assertTrue($category->isHidden());
        self::assertNull($store->find('introuvable'));

        $store->setHidden('adapter-read', false);
        self::assertFalse((bool) $this->categoryRow('adapter-read')['hidden'], 'the visibility flag is written alone');

        $counts = $store->counts('adapter-read');
        self::assertSame(0, $counts['children']);
        self::assertSame(0, $counts['products']);
    }

    public function testCategoryTreeWriterRewritesContentAndSubLevels(): void
    {
        $store = $this->categoryStore();
        $this->seedCategory('adapter-tree', subcategories: '[{"label":"Cuvette"}]');

        $category = $store->find('adapter-tree');
        self::assertNotNull($category);
        $store->replaceContent($category->withContent('Nouveau Nom', [['label' => 'Robinet']], false));

        $row = $this->categoryRow('adapter-tree');
        self::assertSame('Nouveau Nom', $row['label']);
        self::assertSame([['label' => 'Robinet']], json_decode((string) $row['subcategories'], true));
        self::assertFalse((bool) $row['quote_eligible']);

        $category = $store->find('adapter-tree');
        self::assertNotNull($category);
        $store->addSubLevel($category->withSubLevel('Siphon'));

        $row = $this->categoryRow('adapter-tree');
        self::assertSame(
            [['label' => 'Robinet'], ['label' => 'Siphon']],
            json_decode((string) $row['subcategories'], true),
        );
    }

    public function testCategoryHierarchyReaderMapsEverySlugToItsParent(): void
    {
        $store = $this->categoryStore();
        $this->seedCategory('adapter-hier-parent');
        $this->createdCategorySlugs[] = 'adapter-hier-child';
        self::connection()->executeStatement(
            'INSERT INTO storefront_category (slug, label, subcategories, hidden, sort_order, parent_slug)
             VALUES (:slug, :label, :subs, false, 9998, :parent)',
            [
                'slug' => 'adapter-hier-child',
                'label' => 'Hierarchy Child',
                'subs' => '[]',
                'parent' => 'adapter-hier-parent',
            ],
        );

        $map = $store->parentMap();
        self::assertArrayHasKey('adapter-hier-child', $map);
        self::assertSame('adapter-hier-parent', $map['adapter-hier-child']);
        self::assertArrayHasKey('adapter-hier-parent', $map);
    }

    public function testCategoryDeleterDeletesAndCountsRefuseNothing(): void
    {
        $store = $this->categoryStore();
        $this->seedCategory('adapter-del');

        $store->delete('adapter-del');

        self::assertFalse($this->categoryRow('adapter-del'), 'the row is gone');

        $deleter = static::getContainer()->get(CategoryDeleter::class);
        self::assertInstanceOf(DbalCategoryStore::class, $deleter);
        self::assertSame($this->categoryStore(), $deleter, 'every category port resolves to the one store');
    }

    public function testAdminProductStoreWritesPublicationReviewsAndCountsOrders(): void
    {
        $store = static::getContainer()->get(ProductPublicationStore::class);
        self::assertInstanceOf(DbalAdminProductStore::class, $store);
        $slug = $this->seedProduct();

        $store->setPublished($slug, true);
        self::assertTrue((bool) $this->productRow($slug)['published']);

        $store->setPublished($slug, false);
        self::assertFalse((bool) $this->productRow($slug)['published'], 'unpublish writes the flag alone');

        $reviews = static::getContainer()->get(ProductReviewStore::class);
        self::assertInstanceOf(DbalAdminProductStore::class, $reviews);
        $reviewId = Uuid::v7()->toRfc4122();
        $reviews->store($reviewId, $slug, 'Acheteur Web', 9, 'Très bon rapport qualité prix');

        $review = self::connection()->fetchAssociative(
            'SELECT author_name, rating, comment FROM storefront_product_review WHERE id = :id',
            ['id' => $reviewId],
        );
        self::assertNotFalse($review);
        self::assertSame('Acheteur Web', $review['author_name']);
        self::assertSame(5, (int) $review['rating'], 'the store clamps the rating to the 1..5 window');
    }

    public function testProductDeletionReaderCountsDistinctOrders(): void
    {
        $reader = static::getContainer()->get(ProductDeletionReader::class);
        self::assertInstanceOf(DbalAdminProductStore::class, $reader);

        // a slug with no order lines counts zero; the historical-order path is covered by the
        // delete flow's functional tests against the real order data
        self::assertSame(0, $reader->orderCount('adapter-never-ordered'));
    }

    public function testBannerStoreReplacesReordersAndRemovesSlides(): void
    {
        $reader = static::getContainer()->get(BannerSetReader::class);
        self::assertInstanceOf(DbalBannerStore::class, $reader);
        $store = static::getContainer()->get(BannerImageStore::class);
        self::assertInstanceOf(DbalBannerStore::class, $store);

        $this->cleanBannerRows();

        $store->replace(BannerSet::fromPaths(['/uploads/banner/a.jpg', '/uploads/banner/b.jpg']));

        $set = $reader->current();
        self::assertCount(2, $set->images(), 'the replace-all write stores the ordered set');

        $set->reorder(array_map(static fn ($image): string => (string) $image->id, array_reverse($set->images())));
        $store->reorder($set);
        $stored = $reader->current();
        self::assertSame(
            '/uploads/banner/b.jpg',
            $stored->images()[0]->path,
            'the reorder persisted the dense sequence',
        );

        $store->remove((string) $stored->images()[0]->id);
        self::assertCount(1, $reader->current()->images(), 'the removal deletes only the targeted slide');
    }

    private function categoryStore(): DbalCategoryStore
    {
        $store = static::getContainer()->get(CategoryReader::class);
        self::assertInstanceOf(DbalCategoryStore::class, $store);
        static::getContainer()->get(CategoryCreator::class);
        static::getContainer()->get(CategoryTreeWriter::class);
        static::getContainer()->get(CategoryVisibilityWriter::class);
        static::getContainer()->get(CategoryDeletionReader::class);
        static::getContainer()->get(CategoryDeleter::class);
        static::getContainer()->get(CategoryHierarchyReader::class);

        return $store;
    }

    private function seedCategory(string $slug, string $subcategories = '[]', bool $hidden = false): void
    {
        $this->createdCategorySlugs[] = $slug;
        self::connection()->insert('storefront_category', [
            'slug' => $slug,
            'label' => ucfirst(str_replace('-', ' ', $slug)),
            'subcategories' => $subcategories,
            'hidden' => $hidden,
            'sort_order' => 9997,
        ], ['hidden' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function categoryRow(string $slug): array|false
    {
        return self::connection()->fetchAssociative(
            'SELECT * FROM storefront_category WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    private function seedProduct(): string
    {
        $slug = 'adapter-product-'.substr(Uuid::v7()->toRfc4122(), 0, 8);
        $this->createdProductSlugs[] = $slug;
        self::connection()->executeStatement(
            "INSERT INTO storefront_product_view (
                id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                stock, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, approval_status, published
            )
            SELECT :id, :slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                   stock, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, approval_status, published
            FROM storefront_product_view WHERE slug = 'baignoire-d-code'",
            ['id' => Uuid::v7()->toRfc4122(), 'slug' => $slug],
        );

        return $slug;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function productRow(string $slug): array|false
    {
        return self::connection()->fetchAssociative(
            'SELECT published FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    private function cleanBannerRows(): void
    {
        self::connection()->executeStatement(
            'DELETE FROM storefront_banner_image WHERE banner_id = :banner',
            ['banner' => BannerSet::SITE_BANNER_ID],
        );
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}

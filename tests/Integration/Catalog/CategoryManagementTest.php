<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Command\AddCategorySubLevel;
use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\DeleteCategory;
use App\Catalog\Application\Command\HideCategory;
use App\Catalog\Application\Command\ReplaceCategoryTree;
use App\Catalog\Application\Command\ShowCategory;
use App\Catalog\Application\Query\ListCategories;
use App\Catalog\Domain\Model\CategoryNotEmptyException;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use App\Catalog\Domain\Model\CategorySublevelException;
use App\Catalog\Infrastructure\ReadModel\CategoryView;
use App\Shared\Infrastructure\ReadModel\StorefrontReferenceRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * WP2 (spec R1-R8) on real PostgreSQL: one command.bus handler per category command, the
 * sub-category stored as a node of the parent row's JSON tree (ADR-0006 / Q1), the hidden
 * flag persisted alone (R5), and the reads — admin list and storefront navigation — filtered
 * in SQL / in the read model rather than in a controller (QRY-001/QRY-003/QRY-004).
 */
final class CategoryManagementTest extends KernelTestCase
{
    private string $slug;
    private string $childSlug;

    protected function setUp(): void
    {
        self::bootKernel();

        try {
            $this->db()->executeQuery('SELECT 1 FROM storefront_category LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }

        $this->slug = 'int-cat-'.bin2hex(random_bytes(6));
        $this->childSlug = $this->slug.'-child';
    }

    protected function tearDown(): void
    {
        $this->db()->executeStatement(
            'DELETE FROM storefront_category WHERE slug IN (:s)',
            ['s' => [$this->slug, $this->childSlug]],
            ['s' => ArrayParameterType::STRING],
        );
        $this->db()->executeStatement('DELETE FROM storefront_product_view WHERE category_slug = :slug', ['slug' => $this->slug]);

        parent::tearDown();
    }

    public function testASubCategoryIsStoredAsANodeOfTheParentRowTreeAndCreatesNoChildRow(): void
    {
        $this->write(new CreateCategory($this->slug, 'Intégration', [['label' => 'Alpha']]));

        $this->write(new AddCategorySubLevel($this->slug, 'Abattant', 'Alpha'));

        /** @var list<string> $slugs */
        $slugs = $this->db()->fetchFirstColumn('SELECT slug FROM storefront_category WHERE slug = :s OR parent_slug = :s', ['s' => $this->slug]);
        self::assertSame([$this->slug], $slugs, 'a sub-category is a node of the parent row, never a child row (Q1)');

        /** @var string $tree */
        $tree = $this->db()->fetchOne('SELECT subcategories FROM storefront_category WHERE slug = :s', ['s' => $this->slug]);
        self::assertSame(
            [['label' => 'Alpha', 'children' => [['label' => 'Abattant']]]],
            json_decode($tree, true),
        );
    }

    public function testAWriteThatObeysNeitherTheDepthNorTheSiblingRuleLeavesTheParentUntouched(): void
    {
        $depth4 = [['label' => 'L4']];
        $depth3 = [['label' => 'L3', 'children' => $depth4]];
        $depth2 = [['label' => 'L2', 'children' => $depth3]];
        $depth1 = [['label' => 'L1', 'children' => $depth2]];
        $this->write(new CreateCategory($this->slug, 'Intégration', $depth1));

        $before = $this->storedTree();

        $this->expectDomainException(CategorySublevelException::class, function (): void {
            $this->write(new AddCategorySubLevel($this->slug, 'L5', 'L4'));
        });
        $this->expectDomainException(CategorySublevelException::class, function (): void {
            $this->write(new AddCategorySubLevel($this->slug, 'L1'));
        });

        self::assertSame($before, $this->storedTree(), 'a refused sub-level write does not change the parent');
    }

    public function testHidingPersistsOnlyTheFlagAndUnHidingReversesItWithoutDeletingAnything(): void
    {
        $this->write(new CreateCategory($this->slug, 'Intégration', [['label' => 'Alpha']], true));
        $this->seedProduct();

        $visibleBefore = $this->storedRow();

        $this->write(new HideCategory($this->slug));
        $hidden = $this->storedRow();
        self::assertTrue($this->flag($hidden['hidden']), 'the hidden column is what carries the visibility');
        self::assertSame($visibleBefore['label'], $hidden['label'], 'hiding never rewrites the row');
        self::assertSame($visibleBefore['subcategories'], $hidden['subcategories']);
        self::assertSame($visibleBefore['quote_eligible'], $hidden['quote_eligible']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_product_view WHERE category_slug = :s', ['s' => $this->slug]));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_category WHERE slug = :s', ['s' => $this->slug]));

        $this->write(new HideCategory($this->slug));
        self::assertTrue($this->flag($this->storedRow()['hidden']), 'hiding twice is idempotent');

        $this->write(new ShowCategory($this->slug));
        self::assertFalse($this->flag($this->storedRow()['hidden']));
    }

    public function testHidingACategoryRemovesItAndItsSubtreeFromTheStorefrontNavigationOnly(): void
    {
        $this->write(new CreateCategory($this->slug, 'Intégration', [['label' => 'Alpha'], ['label' => 'Beta']]));
        // A legacy pre-Q1 descendant row: it must go with the hidden ancestor too.
        $this->db()->insert(
            'storefront_category',
            [
                'slug' => $this->childSlug,
                'label' => 'Descendant',
                'subcategories' => '[]',
                'quote_eligible' => false,
                'hidden' => false,
                'sort_order' => 99,
                'parent_slug' => $this->slug,
            ],
            ['quote_eligible' => ParameterType::BOOLEAN, 'hidden' => ParameterType::BOOLEAN],
        );

        $navigation = $this->navigationSlugs();
        self::assertContains($this->slug, $navigation);
        self::assertContains($this->childSlug, $navigation);

        $this->write(new HideCategory($this->slug));

        $navigation = $this->navigationSlugs();
        self::assertNotContains($this->slug, $navigation, 'a hidden category leaves the storefront navigation');
        self::assertNotContains($this->childSlug, $navigation, 'with its whole subtree');

        // The admin read still returns both rows, in the same order, so nothing was deleted.
        self::assertSame(
            [$this->slug, $this->childSlug],
            array_values(array_filter(
                $this->adminSlugs(hiddenRequester: true),
                fn (string $slug): bool => in_array($slug, [$this->slug, $this->childSlug], true),
            )),
        );
        $view = $this->viewFor($this->slug, hiddenRequester: true);
        self::assertNotNull($view);
        self::assertTrue($view->hidden);
        self::assertSame([['label' => 'Alpha'], ['label' => 'Beta']], $view->subcategories);
    }

    public function testTheCategoryFinderFiltersHiddenRowsInSqlForEachNonAdminRequester(): void
    {
        $this->write(new CreateCategory($this->slug, 'Intégration'));
        $this->write(new HideCategory($this->slug));

        $customerSlugs = $this->adminSlugs(hiddenRequester: false);
        self::assertNotContains($this->slug, $customerSlugs, 'an identified non-admin never reads a hidden row (QRY-004)');
        self::assertContains('sanitaires', $customerSlugs, 'the visible taxonomy is still served');

        $adminSlugs = $this->adminSlugs(hiddenRequester: true);
        self::assertContains($this->slug, $adminSlugs, 'the admin list keeps the hidden row (R8)');
        $hiddenView = $this->viewFor($this->slug, hiddenRequester: true);
        self::assertNotNull($hiddenView);
        self::assertTrue($hiddenView->hidden);
    }

    public function testDeletingCountsTheJsonNodesAndRefusesANonEmptyCategoryWithoutTouchingOrders(): void
    {
        $this->write(new CreateCategory($this->slug, 'Intégration', [['label' => 'Alpha', 'children' => [['label' => 'Abattant']]], ['label' => 'Beta']]));
        $this->seedProduct();

        $ordersBefore = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ordering_orders');
        $linesBefore = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ordering_order_lines');

        $this->expectDomainException(CategoryNotEmptyException::class, function (): void {
            $this->write(new DeleteCategory($this->slug));
        });
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_category WHERE slug = :s', ['s' => $this->slug]));

        $this->db()->executeStatement('DELETE FROM storefront_product_view WHERE category_slug = :s', ['s' => $this->slug]);
        $this->expectDomainException(CategoryNotEmptyException::class, function (): void {
            $this->write(new DeleteCategory($this->slug));
        });

        // Only once its JSON tree is emptied does the row become deletable (R4).
        $this->write(new ReplaceCategoryTree($this->slug, 'Intégration', [], true));
        $this->write(new DeleteCategory($this->slug));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_category WHERE slug = :s', ['s' => $this->slug]));

        $this->expectDomainException(CategoryNotFoundException::class, function (): void {
            $this->write(new DeleteCategory($this->slug));
        });

        self::assertSame($ordersBefore, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ordering_orders'), 'ECO-006: no order row is ever touched');
        self::assertSame($linesBefore, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ordering_order_lines'));
    }

    public function testTheDeleteGuardReportsTheNodeAndProductCountsItRefusedOn(): void
    {
        $this->write(new CreateCategory($this->slug, 'Intégration', [['label' => 'Alpha', 'children' => [['label' => 'Abattant']]], ['label' => 'Beta']]));
        $this->seedProduct();

        $message = '';
        try {
            $this->write(new DeleteCategory($this->slug));
            self::fail('a non-empty category is never deletable');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(CategoryNotEmptyException::class, $previous);
            $message = $previous->getMessage();
        }

        self::assertStringContainsString('it contains 3 subcategories and 1 products', $message);
    }

    /**
     * @return list<string>
     */
    private function navigationSlugs(): array
    {
        /** @var StorefrontReferenceRepository $references */
        $references = static::getContainer()->get(StorefrontReferenceRepository::class);

        return array_column($references->categories(), 'slug');
    }

    /**
     * @return list<string>
     */
    private function adminSlugs(bool $hiddenRequester): array
    {
        return array_map(
            static fn (CategoryView $view): string => $view->slug,
            $this->categories($hiddenRequester),
        );
    }

    private function viewFor(string $slug, bool $hiddenRequester): ?CategoryView
    {
        foreach ($this->categories($hiddenRequester) as $view) {
            if ($view->slug === $slug) {
                return $view;
            }
        }

        return null;
    }

    /**
     * @return list<CategoryView>
     */
    private function categories(bool $isAdmin): array
    {
        /** @var MessageBusInterface $queryBus */
        $queryBus = static::getContainer()->get('messenger.bus.query');
        $envelope = $queryBus->dispatch(new ListCategories(
            requesterId: $isAdmin ? 'integration-admin' : 'integration-customer',
            isAdmin: $isAdmin,
        ));

        $handled = $envelope->last(HandledStamp::class);
        self::assertNotNull($handled);

        /** @var list<CategoryView> $views */
        $views = $handled->getResult();

        return $views;
    }

    /** @return array<string, mixed> */
    private function storedRow(): array
    {
        $row = $this->db()->fetchAssociative(
            'SELECT slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug FROM storefront_category WHERE slug = :s',
            ['s' => $this->slug],
        );
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<array-key, mixed> */
    private function storedTree(): array
    {
        /** @var string $json */
        $json = $this->db()->fetchOne('SELECT subcategories FROM storefront_category WHERE slug = :s', ['s' => $this->slug]);

        /** @var array<array-key, mixed> $tree */
        $tree = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $tree;
    }

    private function flag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function write(object $command): void
    {
        $this->commands()->dispatch($command);
    }

    private function commands(): MessageBusInterface
    {
        /** @var MessageBusInterface $bus */
        $bus = static::getContainer()->get('messenger.bus.command');

        return $bus;
    }

    private function db(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }

    /** @param class-string<object> $expected */
    private function expectDomainException(string $expected, callable $action): void
    {
        try {
            $action();
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf($expected, $previous);

            return;
        }

        self::fail(sprintf('Expected %s to be raised by the handler.', $expected));
    }

    /**
     * A product of the category, written straight through DBAL: hiding must never remove it
     * (R5/R6), so the read side keeps serving it.
     */
    private function seedProduct(): void
    {
        $this->db()->insert('storefront_product_view', $this->productRow(), [
            'price_minor' => ParameterType::INTEGER,
            'rating' => ParameterType::INTEGER,
            'reviews' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
        ]);
    }

    /** @return array<string, mixed> */
    private function productRow(): array
    {
        return [
            'id' => Uuid::v7()->toRfc4122(),
            'slug' => 'int-cat-product-'.$this->slug,
            'name' => 'Produit de catégorie intégration',
            'brand' => 'Sopal',
            'ref' => 'INT-1',
            'category_slug' => $this->slug,
            'subcategory' => 'Alpha',
            'price_minor' => 200,
            'currency' => 'TND',
            'stock' => 'in',
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image_path' => '/p-int.jpg',
            'rating' => 0,
            'reviews' => 0,
            'is_new' => false,
            'is_best' => false,
            'headrest' => false,
            'description' => 'Produit de test.',
            'variants' => '[]',
        ];
    }
}

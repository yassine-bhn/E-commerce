<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\DeleteCategory;
use App\Catalog\Application\CommandHandler\DeleteCategoryHandler;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CategoryNotEmptyException;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use PHPUnit\Framework\TestCase;

final class DeleteCategoryHandlerTest extends TestCase
{
    /**
     * @param list<array<string, mixed>|false>          $rows
     * @param list<array{children: int, products: int}> $counts
     */
    private function handler(array $rows, array $counts, bool $refuseWrite = false): InMemoryCategoryStore
    {
        $categories = new InMemoryCategoryStore();
        $categories->rows = [$rows[0] ?? false];
        $categories->counts = [$counts[0] ?? ['children' => 0, 'products' => 0]];
        if ($refuseWrite) {
            $categories->expectsNoWrite();
        }

        return $categories;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return array<string, mixed>
     */
    private function row(array $nodes, bool $hidden = false): array
    {
        return [
            'slug' => 'wc',
            'label' => 'WC',
            'subcategories' => json_encode($nodes, JSON_THROW_ON_ERROR),
            'quote_eligible' => false,
            'hidden' => $hidden,
            'sort_order' => 2,
            'parent_slug' => null,
        ];
    }

    public function testDeletesACategoryThatHasNoSubLevelAndNoProduct(): void
    {
        $categories = $this->handler([$this->row([])], [['children' => 0, 'products' => 0]]);
        $audit = new RecordingCatalogAudit();

        (new DeleteCategoryHandler($categories, $categories, $categories, $categories->submissions, $audit))(new DeleteCategory('wc', 'super-admin'));

        $write = $categories->lastWrite();
        self::assertNotNull($write);
        self::assertSame('delete', $write['method']);
        self::assertSame('wc', $categories->lastWrittenSlug());
        self::assertSame(
            ['action' => 'CATALOG_CATEGORY_DELETED', 'target' => 'category', 'slug' => 'wc', 'actor' => 'super-admin', 'reason' => null],
            $audit->last(),
        );
        self::assertSame(
            ['method' => 'delete', 'args' => [CatalogSubmissionKind::Category, 'wc']],
            $categories->submissions->lastCall(),
            'slug reuse must not inherit the deleted category approval',
        );
    }

    public function testARefusedDeletionWritesNeitherTheRowNorAnAuditEntry(): void
    {
        $categories = $this->handler(
            [$this->row([['label' => 'Cuvette']])],
            [['children' => 0, 'products' => 0]],
            refuseWrite: true,
        );
        $audit = new RecordingCatalogAudit();

        try {
            (new DeleteCategoryHandler($categories, $categories, $categories, $categories->submissions, $audit))(new DeleteCategory('wc', 'super-admin'));
            self::fail('a category that still owns a sub-level must not be deleted');
        } catch (CategoryNotEmptyException) {
            self::assertSame([], $audit->records, 'a refused deletion leaves no audit row (R45)');
            self::assertSame([], $categories->submissions->calls, 'a refused deletion keeps the submission row');
        }
    }

    public function testRefusesToDropTheSubLevelsAndProductsOfACategoryWithItsCounts(): void
    {
        $categories = $this->handler(
            [$this->row([['label' => 'Cuvette'], ['label' => 'Abattant', 'children' => [['label' => 'Siège']]]])],
            [['children' => 1, 'products' => 5]],
            refuseWrite: true,
        );

        $this->expectException(CategoryNotEmptyException::class);
        $this->expectExceptionMessage('Cannot delete category "wc": it contains 4 subcategories and 5 products.');

        (new DeleteCategoryHandler($categories, $categories, $categories, $categories->submissions, new RecordingCatalogAudit()))(new DeleteCategory('wc'));
    }

    public function testRefusesToDeleteACategoryThatOnlyHasSubLevels(): void
    {
        $categories = $this->handler([$this->row([['label' => 'Cuvette']])], [['children' => 0, 'products' => 0]], refuseWrite: true);

        $this->expectException(CategoryNotEmptyException::class);

        (new DeleteCategoryHandler($categories, $categories, $categories, $categories->submissions, new RecordingCatalogAudit()))(new DeleteCategory('wc'));
    }

    public function testDeletingAnUnknownCategoryIsNotFoundRatherThanSuccessful(): void
    {
        $categories = $this->handler([false], [], refuseWrite: true);

        $this->expectException(CategoryNotFoundException::class);

        (new DeleteCategoryHandler($categories, $categories, $categories, $categories->submissions, new RecordingCatalogAudit()))(new DeleteCategory('wc'));
    }

    public function testHidingACategoryNeverDeletesIt(): void
    {
        $categories = $this->handler([$this->row([['label' => 'Cuvette']], hidden: true)], [['children' => 0, 'products' => 0]], refuseWrite: true);

        $this->expectException(CategoryNotEmptyException::class);

        (new DeleteCategoryHandler($categories, $categories, $categories, $categories->submissions, new RecordingCatalogAudit()))(new DeleteCategory('wc'));
    }
}

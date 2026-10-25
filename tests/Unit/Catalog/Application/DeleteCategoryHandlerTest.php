<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\DeleteCategory;
use App\Catalog\Application\CommandHandler\DeleteCategoryHandler;
use App\Catalog\Domain\Model\CategoryNotEmptyException;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use PHPUnit\Framework\TestCase;

final class DeleteCategoryHandlerTest extends TestCase
{
    /**
     * @param list<array<string, mixed>|false> $rows
     * @param list<array<string, int|string>>  $counts
     */
    private function handler(array $rows, array $counts, bool $refuseWrite = false): CategoryConnectionFake
    {
        $connection = new CategoryConnectionFake();
        $connection->rows = [$rows[0] ?? false, $counts[0] ?? ['child_rows' => 0, 'products' => 0]];
        $connection->rowLists = [];
        if ($refuseWrite) {
            $connection->expectsNoWrite();
        }

        return $connection;
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
        $connection = $this->handler([$this->row([])], [['child_rows' => 0, 'products' => 0]]);
        $audit = new RecordingCatalogAudit();

        (new DeleteCategoryHandler($connection, $audit))(new DeleteCategory('wc', 'super-admin'));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertStringContainsString('DELETE FROM storefront_category', $write['sql']);
        self::assertSame('wc', $write['params']['slug']);
        self::assertSame(
            ['action' => 'CATALOG_CATEGORY_DELETED', 'target' => 'category', 'slug' => 'wc', 'actor' => 'super-admin', 'reason' => null],
            $audit->last(),
        );
    }

    public function testARefusedDeletionWritesNeitherTheRowNorAnAuditEntry(): void
    {
        $connection = $this->handler(
            [$this->row([['label' => 'Cuvette']])],
            [['child_rows' => 0, 'products' => 0]],
            refuseWrite: true,
        );
        $audit = new RecordingCatalogAudit();

        try {
            (new DeleteCategoryHandler($connection, $audit))(new DeleteCategory('wc', 'super-admin'));
            self::fail('a category that still owns a sub-level must not be deleted');
        } catch (CategoryNotEmptyException) {
            self::assertSame([], $audit->records, 'a refused deletion leaves no audit row (R45)');
        }
    }

    public function testRefusesToDropTheSubLevelsAndProductsOfACategoryWithItsCounts(): void
    {
        $connection = $this->handler(
            [$this->row([['label' => 'Cuvette'], ['label' => 'Abattant', 'children' => [['label' => 'Siège']]]])],
            [['child_rows' => 1, 'products' => 5]],
            refuseWrite: true,
        );

        $this->expectException(CategoryNotEmptyException::class);
        $this->expectExceptionMessage('Cannot delete category "wc": it contains 4 subcategories and 5 products.');

        (new DeleteCategoryHandler($connection, new RecordingCatalogAudit()))(new DeleteCategory('wc'));
    }

    public function testRefusesToDeleteACategoryThatOnlyHasSubLevels(): void
    {
        $connection = $this->handler([$this->row([['label' => 'Cuvette']])], [['child_rows' => 0, 'products' => 0]], refuseWrite: true);

        $this->expectException(CategoryNotEmptyException::class);

        (new DeleteCategoryHandler($connection, new RecordingCatalogAudit()))(new DeleteCategory('wc'));
    }

    public function testDeletingAnUnknownCategoryIsNotFoundRatherThanSuccessful(): void
    {
        $connection = $this->handler([false], [], refuseWrite: true);

        $this->expectException(CategoryNotFoundException::class);

        (new DeleteCategoryHandler($connection, new RecordingCatalogAudit()))(new DeleteCategory('wc'));
    }

    public function testHidingACategoryNeverDeletesIt(): void
    {
        $connection = $this->handler([$this->row([['label' => 'Cuvette']], hidden: true)], [['child_rows' => 0, 'products' => 0]], refuseWrite: true);

        $this->expectException(CategoryNotEmptyException::class);

        (new DeleteCategoryHandler($connection, new RecordingCatalogAudit()))(new DeleteCategory('wc'));
    }
}

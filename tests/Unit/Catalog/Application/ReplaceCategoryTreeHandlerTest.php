<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\ReplaceCategoryTree;
use App\Catalog\Application\CommandHandler\ReplaceCategoryTreeHandler;
use App\Catalog\Domain\Model\CategoryLabelInvalidException;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use App\Catalog\Domain\Model\CategorySublevelInUseException;
use PHPUnit\Framework\TestCase;

final class ReplaceCategoryTreeHandlerTest extends TestCase
{
    /**
     * @param array<string, mixed>|false $row
     * @param list<array<string, mixed>> $usedSubLevels
     */
    private function handler(array|false $row, array $usedSubLevels = [], bool $refuseWrite = false): CategoryConnectionFake
    {
        $connection = new CategoryConnectionFake();
        $connection->rows = [$row];
        $connection->rowLists = [$usedSubLevels];
        if ($refuseWrite) {
            $connection->expectsNoWrite();
        }

        return $connection;
    }

    public function testWritesTheNewLabelFlagsAndSubLevelTreeOfTheCategory(): void
    {
        $connection = $this->handler([
            'slug' => 'wc',
            'label' => 'WC',
            'subcategories' => '[{"label":"Cuvette"}]',
            'quote_eligible' => false,
            'hidden' => true,
            'sort_order' => 4,
            'parent_slug' => null,
        ]);

        (new ReplaceCategoryTreeHandler($connection))(new ReplaceCategoryTree(
            slug: 'wc',
            label: '  Sanitaires  ',
            subcategories: [['label' => 'Cuvette'], ['label' => 'Baignoire']],
            quoteEligible: true,
        ));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertStringContainsString('UPDATE storefront_category', $write['sql']);
        self::assertSame('Sanitaires', $write['params']['label']);
        self::assertTrue($write['params']['quote']);
        self::assertStringNotContainsString('hidden', $write['sql'], 'editing the content does not change visibility');
        self::assertSame(
            [['label' => 'Cuvette'], ['label' => 'Baignoire']],
            json_decode((string) $write['params']['subs'], true),
        );
    }

    public function testRefusesToRemoveASubLevelAProductStillUsesAndWritesNothing(): void
    {
        $connection = $this->handler(
            [
                'slug' => 'wc',
                'label' => 'WC',
                'subcategories' => '[{"label":"Cuvette"},{"label":"Abattant"}]',
                'quote_eligible' => false,
                'hidden' => false,
                'sort_order' => 4,
                'parent_slug' => null,
            ],
            [['subcategory' => 'Cuvette']],
            refuseWrite: true,
        );

        $this->expectException(CategorySublevelInUseException::class);
        $this->expectExceptionMessage('Cuvette');

        (new ReplaceCategoryTreeHandler($connection))(new ReplaceCategoryTree('wc', 'WC', [['label' => 'Abattant']], false));
    }

    public function testRefusesADuplicatedSiblingLabelAndWritesNothing(): void
    {
        $connection = $this->handler([
            'slug' => 'wc',
            'label' => 'WC',
            'subcategories' => '[]',
            'quote_eligible' => false,
            'hidden' => false,
            'sort_order' => 4,
            'parent_slug' => null,
        ], refuseWrite: true);

        $this->expectException(\DomainException::class);

        (new ReplaceCategoryTreeHandler($connection))(new ReplaceCategoryTree('wc', 'WC', [['label' => 'A'], ['label' => 'A']], false));
    }

    public function testRefusesAnEmptyLabelAndWritesNothing(): void
    {
        $connection = $this->handler(['slug' => 'wc', 'label' => 'WC', 'subcategories' => '[]', 'quote_eligible' => false, 'hidden' => false, 'sort_order' => 4, 'parent_slug' => null], refuseWrite: true);

        $this->expectException(CategoryLabelInvalidException::class);

        (new ReplaceCategoryTreeHandler($connection))(new ReplaceCategoryTree('wc', '', [], false));
    }

    public function testAnUnknownCategoryIsNotFoundAndWritesNothing(): void
    {
        $connection = $this->handler(false, refuseWrite: true);

        $this->expectException(CategoryNotFoundException::class);

        (new ReplaceCategoryTreeHandler($connection))(new ReplaceCategoryTree('introuvable', 'WC', [], false));
    }
}

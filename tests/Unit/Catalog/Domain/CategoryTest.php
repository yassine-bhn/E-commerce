<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Model\Category;
use App\Catalog\Domain\Model\CategoryLabelInvalidException;
use App\Catalog\Domain\Model\CategorySublevelException;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testRootCategoryRequiresANonEmptyLabel(): void
    {
        $this->expectException(CategoryLabelInvalidException::class);

        Category::createRoot('vide-grenier', '   ');
    }

    public function testRootCategoryLabelIsLimitedTo128Characters(): void
    {
        $this->expectException(CategoryLabelInvalidException::class);

        Category::createRoot('une-categorie', str_repeat('a', 129));
    }

    public function testCreatedRootCategoryIsVisibleWithAnEmptySubLevelTree(): void
    {
        $category = Category::createRoot('test-cat', '  Test cat  ');

        self::assertSame('test-cat', $category->slug);
        self::assertSame('Test cat', $category->label);
        self::assertSame([], $category->subcategories);
        self::assertFalse($category->isHidden());
        self::assertFalse($category->quoteEligible);
        self::assertSame(0, $category->subLevelCount());
    }

    public function testAddingASubLevelReturnsANewCategoryWithTheNodeAppended(): void
    {
        $category = Category::restore('wc', 'WC', [['label' => 'Cuvette']]);

        $updated = $category->withSubLevel('Abattant');

        self::assertSame([['label' => 'Cuvette']], $category->subcategories(), 'the original category is untouched');
        self::assertSame([['label' => 'Cuvette'], ['label' => 'Abattant']], $updated->subcategories());
        self::assertSame(2, $updated->subLevelCount());
        self::assertSame(['Cuvette', 'Abattant'], $updated->subLevelLabels());
        self::assertFalse($updated->isHidden(), 'a taxonomy write never changes visibility');
    }

    public function testHidingIsReversibleAndIdempotent(): void
    {
        $category = Category::restore('wc', 'WC');

        self::assertTrue($category->hide()->isHidden());
        self::assertTrue($category->hide()->hide()->isHidden(), 'hiding twice answers hidden');
        self::assertFalse($category->hide()->show()->isHidden());
        self::assertFalse($category->show()->isHidden(), 'showing a visible category answers visible');
        self::assertFalse($category->isHidden(), 'the original category is untouched');
    }

    public function testReplacingTheContentKeepsTheSlugAndTheVisibilityButAdoptsTheNewTree(): void
    {
        $category = Category::restore('wc', 'WC', [['label' => 'Cuvette']], quoteEligible: true, hidden: true, sortOrder: 7);

        $edited = $category->withContent('Sanitaires', [['label' => 'Baignoire'], ['label' => 'WC', 'children' => [['label' => 'Cuvette']]]], false);

        self::assertSame('wc', $edited->slug);
        self::assertSame('Sanitaires', $edited->label);
        self::assertTrue($edited->isHidden(), 'an edit does not un-hide a hidden category');
        self::assertSame(7, $edited->sortOrder);
        self::assertFalse($edited->quoteEligible);
        self::assertSame(['Baignoire', 'WC', 'Cuvette'], $edited->subLevelLabels());
    }

    public function testReplacingTheContentRefusesAnInvalidTree(): void
    {
        $category = Category::restore('wc', 'WC');

        $this->expectException(CategorySublevelException::class);

        $category->withContent('Sanitaires', [['label' => 'WC'], ['label' => 'WC']], false);
    }

    public function testReplacingTheContentRefusesAnEmptyLabel(): void
    {
        $category = Category::restore('wc', 'WC');

        $this->expectException(CategoryLabelInvalidException::class);

        $category->withContent('  ', [['label' => 'WC']], false);
    }
}

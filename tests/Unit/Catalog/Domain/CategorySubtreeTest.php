<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Model\CategoryLabelInvalidException;
use App\Catalog\Domain\Model\CategorySublevelException;
use App\Catalog\Domain\Model\CategorySubtree;
use PHPUnit\Framework\TestCase;

final class CategorySubtreeTest extends TestCase
{
    public function testLabelsFlattenEveryNodeOfTheTree(): void
    {
        $tree = [
            ['label' => 'Alpha', 'children' => [['label' => 'Alpha un']]],
            ['label' => 'Beta'],
        ];

        self::assertSame(['Alpha', 'Alpha un', 'Beta'], CategorySubtree::labels($tree));
    }

    public function testLabelsAcceptTheLegacyFlatStringTree(): void
    {
        self::assertSame(['Eau', 'Tubes'], CategorySubtree::labels(['Eau', 'Tubes']));
    }

    public function testNormalizeTurnsLegacyStringsIntoNodesAndTrimsLabels(): void
    {
        $tree = CategorySubtree::normalize([['label' => ' Alpha '], 'Eau']);

        self::assertSame([['label' => 'Alpha'], ['label' => 'Eau']], $tree);
    }

    public function testAddAppendsANodeAtTheTopLevelOfALegacyStringTree(): void
    {
        $tree = CategorySubtree::add(['Eau', 'Tubes'], 'Raccords');

        self::assertSame([['label' => 'Eau'], ['label' => 'Tubes'], ['label' => 'Raccords']], $tree);
    }

    public function testAddNestsANodeInsideTheMatchingParent(): void
    {
        $tree = CategorySubtree::add([['label' => 'WC']], 'Cuvette', 'WC');

        self::assertSame([['label' => 'WC', 'children' => [['label' => 'Cuvette']]]], $tree);
    }

    public function testAddRefusesAnUnknownParentAndLeavesTheTreeAlone(): void
    {
        $stored = [['label' => 'WC']];

        $this->expectException(CategorySublevelException::class);
        $this->expectExceptionMessage('Unknown parent subcategory "Introuvable".');

        CategorySubtree::add($stored, 'Cuvette', 'Introuvable');
    }

    public function testAddRefusesALabelAlreadyUsedByASibling(): void
    {
        $this->expectException(CategorySublevelException::class);
        $this->expectExceptionMessage('The subcategory "WC" already exists at this level.');

        CategorySubtree::add([['label' => 'WC']], 'WC');
    }

    public function testAddRefusesToNestDeeperThanFourLevels(): void
    {
        $stored = [['label' => 'L1', 'children' => [['label' => 'L2', 'children' => [['label' => 'L3', 'children' => [['label' => 'L4']]]]]]]];

        $this->expectException(CategorySublevelException::class);
        $this->expectExceptionMessage('Subcategories may not be nested deeper than 4 levels, 5 given.');

        CategorySubtree::add($stored, 'L5', 'L4');
    }

    public function testAddAllowsTheFourthNestingLevel(): void
    {
        $stored = [['label' => 'L1', 'children' => [['label' => 'L2', 'children' => [['label' => 'L3']]]]]];

        $tree = CategorySubtree::add($stored, 'L4', 'L3');

        self::assertSame('L4', $tree[0]['children'][0]['children'][0]['children'][0]['label']);
    }

    public function testAddRefusesAnEmptySubLabel(): void
    {
        $this->expectException(CategoryLabelInvalidException::class);

        CategorySubtree::add([['label' => 'WC']], '   ');
    }
}

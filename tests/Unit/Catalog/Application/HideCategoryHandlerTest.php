<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\HideCategory;
use App\Catalog\Application\Command\ShowCategory;
use App\Catalog\Application\CommandHandler\HideCategoryHandler;
use App\Catalog\Application\CommandHandler\ShowCategoryHandler;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use PHPUnit\Framework\TestCase;

final class HideCategoryHandlerTest extends TestCase
{
    /** @param array<string, mixed>|false $row */
    private function categories(array|false $row): InMemoryCategoryStore
    {
        $store = new InMemoryCategoryStore();
        $store->rows = [$row];
        $store->submission();

        return $store;
    }

    private function hide(InMemoryCategoryStore $categories, HideCategory $command): void
    {
        (new HideCategoryHandler($categories, $categories, $categories->accessPolicy()))($command);
    }

    private function show(InMemoryCategoryStore $categories, ShowCategory $command): void
    {
        (new ShowCategoryHandler($categories, $categories, $categories->accessPolicy()))($command);
    }

    /** @return array<string, mixed> */
    private function row(bool $hidden): array
    {
        return [
            'slug' => 'sanitaires',
            'label' => 'Sanitaires',
            'subcategories' => '[{"label":"WC"}]',
            'quote_eligible' => true,
            'hidden' => $hidden,
            'sort_order' => 3,
            'parent_slug' => null,
        ];
    }

    public function testHidingPersistsTheFlagAndLeavesTheRestOfTheRowAlone(): void
    {
        $categories = $this->categories($this->row(hidden: false));

        $this->hide($categories, new HideCategory('sanitaires'));

        self::assertCount(1, $categories->writes, 'hiding is one port call');
        $write = $categories->lastWrite();
        self::assertNotNull($write);
        self::assertSame('setHidden', $write['method']);
        self::assertTrue($write['hidden']);
        self::assertSame('sanitaires', $write['slug']);
        self::assertNotContains('replaceContent', array_column($categories->writes, 'method'), 'hiding does not rewrite the tree');
    }

    public function testHidingATwiceHiddenCategoryStillWritesAHiddenRow(): void
    {
        $categories = $this->categories($this->row(hidden: true));

        $this->hide($categories, new HideCategory('sanitaires'));

        $write = $categories->lastWrite();
        self::assertNotNull($write);
        self::assertTrue($write['hidden']);
    }

    public function testHidingAnUnknownCategoryIsNotFound(): void
    {
        $categories = $this->categories(false);
        $categories->expectsNoWrite();

        $this->expectException(CategoryNotFoundException::class);

        $this->hide($categories, new HideCategory('introuvable'));
    }

    public function testUnHidingPersistsTheVisibleFlagWithoutTouchingTheTree(): void
    {
        $categories = $this->categories($this->row(hidden: true));

        $this->show($categories, new ShowCategory('sanitaires'));

        $write = $categories->lastWrite();
        self::assertNotNull($write);
        self::assertSame('setHidden', $write['method']);
        self::assertFalse($write['hidden']);
        self::assertSame('sanitaires', $write['slug']);
    }

    public function testUnHidingAnUnknownCategoryIsNotFound(): void
    {
        $categories = $this->categories(false);
        $categories->expectsNoWrite();

        $this->expectException(CategoryNotFoundException::class);

        $this->show($categories, new ShowCategory('introuvable'));
    }
}

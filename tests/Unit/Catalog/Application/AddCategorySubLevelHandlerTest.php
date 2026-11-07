<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\AddCategorySubLevel;
use App\Catalog\Application\CommandHandler\AddCategorySubLevelHandler;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use App\Catalog\Domain\Model\CategorySublevelException;
use PHPUnit\Framework\TestCase;

final class AddCategorySubLevelHandlerTest extends TestCase
{
    /**
     * @param list<array<string, mixed>|false> $rows
     */
    private function handler(array $rows, bool $refuseWrite = false): InMemoryCategoryStore
    {
        $categories = new InMemoryCategoryStore();
        $categories->rows = $rows;
        $categories->submission();
        if ($refuseWrite) {
            $categories->expectsNoWrite();
        }

        return $categories;
    }

    private function add(InMemoryCategoryStore $categories, AddCategorySubLevel $command): void
    {
        (new AddCategorySubLevelHandler($categories, $categories, $categories->accessPolicy(), new RecordingCatalogAudit(), $categories->submissions))($command);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return array<string, mixed>
     */
    private function row(array $nodes, string $slug = 'wc', bool $hidden = false): array
    {
        return [
            'slug' => $slug,
            'label' => 'WC',
            'subcategories' => json_encode($nodes, JSON_THROW_ON_ERROR),
            'quote_eligible' => false,
            'hidden' => $hidden,
            'sort_order' => 2,
            'parent_slug' => null,
        ];
    }

    public function testAppendsASubLevelToTheParentRowTreeAndKeepsItsVisibility(): void
    {
        $categories = $this->handler([$this->row([['label' => 'Cuvette']], hidden: true)]);

        $this->add($categories, new AddCategorySubLevel('wc', ' Abattant '));

        $write = $categories->lastWrite();
        self::assertNotNull($write);
        self::assertSame('addSubLevel', $write['method'], 'adding a sub-level goes through the tree writer');
        $category = $categories->lastWrittenCategory();
        self::assertNotNull($category);
        self::assertSame('wc', $category->slug);
        self::assertTrue($category->isHidden(), 'adding a sub-level keeps the stored visibility');
        self::assertSame(
            [['label' => 'Cuvette'], ['label' => 'Abattant']],
            $category->subcategories(),
        );
        self::assertNotContains('setHidden', $categories->writtenMethods(), 'adding a sub-level does not touch visibility');
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'wc', 'system']],
            $categories->submissions->lastCall(),
            'the write is applied as a reviewer save so the tree stays approved',
        );
    }

    public function testNestsASubLevelBelowAnExistingNode(): void
    {
        $categories = $this->handler([$this->row([['label' => 'Cuvette']])]);

        $this->add($categories, new AddCategorySubLevel('wc', 'Abattant', 'Cuvette'));

        $category = $categories->lastWrittenCategory();
        self::assertNotNull($category);
        self::assertSame(
            [['label' => 'Cuvette', 'children' => [['label' => 'Abattant']]]],
            $category->subcategories(),
        );
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'wc', 'system']],
            $categories->submissions->lastCall(),
        );
    }

    public function testASubLevelOfAnUnknownCategoryIsNotFoundAndWritesNothing(): void
    {
        $categories = $this->handler([false], refuseWrite: true);

        $this->expectException(CategoryNotFoundException::class);

        try {
            $this->add($categories, new AddCategorySubLevel('introuvable', 'Alpha'));
        } finally {
            self::assertSame([], $categories->submissions->calls, 'a refused write never reaches the store');
        }
    }

    public function testASubLevelDuplicatingASiblingIsRefusedAndTheParentIsUnchanged(): void
    {
        $categories = $this->handler([$this->row([['label' => 'Cuvette'], ['label' => 'Abattant']])], refuseWrite: true);

        $this->expectException(CategorySublevelException::class);
        $this->expectExceptionMessage('The subcategory "Abattant" already exists at this level.');

        try {
            $this->add($categories, new AddCategorySubLevel('wc', 'Abattant'));
        } finally {
            self::assertSame([], $categories->submissions->calls, 'a refused write never reaches the store');
        }
    }

    public function testASubLevelBelowAnUnknownNodeIsRefusedAndTheParentIsUnchanged(): void
    {
        $categories = $this->handler([$this->row([['label' => 'Cuvette']])], refuseWrite: true);

        $this->expectException(CategorySublevelException::class);

        try {
            $this->add($categories, new AddCategorySubLevel('wc', 'Abattant', 'Introuvable'));
        } finally {
            self::assertSame([], $categories->submissions->calls, 'a refused write never reaches the store');
        }
    }
}

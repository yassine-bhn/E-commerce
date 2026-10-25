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
    private function connection(array|false $row): CategoryConnectionFake
    {
        $connection = new CategoryConnectionFake();
        $connection->rows = [$row];
        $connection->submission();

        return $connection;
    }

    private function hide(CategoryConnectionFake $connection, HideCategory $command): void
    {
        (new HideCategoryHandler($connection, $connection->accessPolicy()))($command);
    }

    private function show(CategoryConnectionFake $connection, ShowCategory $command): void
    {
        (new ShowCategoryHandler($connection, $connection->accessPolicy()))($command);
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
        $connection = $this->connection($this->row(hidden: false));

        $this->hide($connection, new HideCategory('sanitaires'));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertSame(1, count($connection->writes), 'hiding is one statement');
        self::assertStringContainsString('UPDATE storefront_category', $write['sql']);
        self::assertStringContainsString('hidden', $write['sql']);
        self::assertStringNotContainsString('subcategories', $write['sql'], 'hiding does not rewrite the tree');
        self::assertStringNotContainsString('DELETE', $write['sql']);
        self::assertTrue($write['params']['hidden']);
        self::assertSame('sanitaires', $write['params']['slug']);
    }

    public function testHidingATwiceHiddenCategoryStillWritesAHiddenRow(): void
    {
        $connection = $this->connection($this->row(hidden: true));

        $this->hide($connection, new HideCategory('sanitaires'));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertTrue($write['params']['hidden']);
    }

    public function testHidingAnUnknownCategoryIsNotFound(): void
    {
        $connection = $this->connection(false);
        $connection->expectsNoWrite();

        $this->expectException(CategoryNotFoundException::class);

        $this->hide($connection, new HideCategory('introuvable'));
    }

    public function testUnHidingPersistsTheVisibleFlagWithoutTouchingTheTree(): void
    {
        $connection = $this->connection($this->row(hidden: true));

        $this->show($connection, new ShowCategory('sanitaires'));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertStringNotContainsString('subcategories', $write['sql']);
        self::assertFalse($write['params']['hidden']);
        self::assertSame('sanitaires', $write['params']['slug']);
    }

    public function testUnHidingAnUnknownCategoryIsNotFound(): void
    {
        $connection = $this->connection(false);
        $connection->expectsNoWrite();

        $this->expectException(CategoryNotFoundException::class);

        $this->show($connection, new ShowCategory('introuvable'));
    }
}

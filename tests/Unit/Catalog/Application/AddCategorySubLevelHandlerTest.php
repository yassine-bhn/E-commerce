<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\AddCategorySubLevel;
use App\Catalog\Application\CommandHandler\AddCategorySubLevelHandler;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use App\Catalog\Domain\Model\CategorySublevelException;
use PHPUnit\Framework\TestCase;

final class AddCategorySubLevelHandlerTest extends TestCase
{
    /** @param list<array<string, mixed>|false> $rows */
    private function handler(array $rows, bool $refuseWrite = false): CategoryConnectionFake
    {
        $connection = new CategoryConnectionFake();
        $connection->rows = $rows;
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
        $connection = $this->handler([$this->row([['label' => 'Cuvette']], hidden: true)]);

        (new AddCategorySubLevelHandler($connection))(new AddCategorySubLevel('wc', ' Abattant '));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertStringContainsString('UPDATE storefront_category', $write['sql']);
        self::assertStringNotContainsString('hidden', $write['sql'], 'adding a sub-level does not touch visibility');
        self::assertSame('wc', $write['params']['slug']);
        self::assertSame(
            [['label' => 'Cuvette'], ['label' => 'Abattant']],
            json_decode((string) $write['params']['subs'], true),
        );
    }

    public function testNestsASubLevelBelowAnExistingNode(): void
    {
        $connection = $this->handler([$this->row([['label' => 'Cuvette']])]);

        (new AddCategorySubLevelHandler($connection))(new AddCategorySubLevel('wc', 'Abattant', 'Cuvette'));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertSame(
            [['label' => 'Cuvette', 'children' => [['label' => 'Abattant']]]],
            json_decode((string) $write['params']['subs'], true),
        );
    }

    public function testASubLevelOfAnUnknownCategoryIsNotFoundAndWritesNothing(): void
    {
        $connection = $this->handler([false], refuseWrite: true);

        $this->expectException(CategoryNotFoundException::class);

        (new AddCategorySubLevelHandler($connection))(new AddCategorySubLevel('introuvable', 'Alpha'));
    }

    public function testASubLevelDuplicatingASiblingIsRefusedAndTheParentIsUnchanged(): void
    {
        $connection = $this->handler([$this->row([['label' => 'Cuvette'], ['label' => 'Abattant']])], refuseWrite: true);

        $this->expectException(CategorySublevelException::class);
        $this->expectExceptionMessage('The subcategory "Abattant" already exists at this level.');

        (new AddCategorySubLevelHandler($connection))(new AddCategorySubLevel('wc', 'Abattant'));
    }

    public function testASubLevelBelowAnUnknownNodeIsRefusedAndTheParentIsUnchanged(): void
    {
        $connection = $this->handler([$this->row([['label' => 'Cuvette']])], refuseWrite: true);

        $this->expectException(CategorySublevelException::class);

        (new AddCategorySubLevelHandler($connection))(new AddCategorySubLevel('wc', 'Abattant', 'Introuvable'));
    }
}

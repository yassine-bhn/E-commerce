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
     * The guard row is pinned by its statement so the queued row stays the content row.
     *
     * @param list<array<string, mixed>|false> $rows
     */
    private function handler(array $rows, bool $refuseWrite = false): CategoryConnectionFake
    {
        $connection = new CategoryConnectionFake();
        $connection->rows = $rows;
        $connection->submission();
        if ($refuseWrite) {
            $connection->expectsNoWrite();
        }

        return $connection;
    }

    private function add(CategoryConnectionFake $connection, AddCategorySubLevel $command): void
    {
        (new AddCategorySubLevelHandler($connection, $connection->accessPolicy(), new RecordingCatalogAudit(), $connection->submissions))($command);
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

        $this->add($connection, new AddCategorySubLevel('wc', ' Abattant '));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertStringContainsString('UPDATE storefront_category', $write['sql']);
        self::assertStringNotContainsString('hidden', $write['sql'], 'adding a sub-level does not touch visibility');
        self::assertSame('wc', $write['params']['slug']);
        self::assertSame(
            [['label' => 'Cuvette'], ['label' => 'Abattant']],
            json_decode((string) $write['params']['subs'], true),
        );
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'wc', 'system']],
            $connection->submissions->lastCall(),
            'the write is applied as a reviewer save so the tree stays approved',
        );
        self::assertStringNotContainsString('approval_status', $write['sql'], 'the handler writes content only');
    }

    public function testNestsASubLevelBelowAnExistingNode(): void
    {
        $connection = $this->handler([$this->row([['label' => 'Cuvette']])]);

        $this->add($connection, new AddCategorySubLevel('wc', 'Abattant', 'Cuvette'));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertSame(
            [['label' => 'Cuvette', 'children' => [['label' => 'Abattant']]]],
            json_decode((string) $write['params']['subs'], true),
        );
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'wc', 'system']],
            $connection->submissions->lastCall(),
        );
    }

    public function testASubLevelOfAnUnknownCategoryIsNotFoundAndWritesNothing(): void
    {
        $connection = $this->handler([false], refuseWrite: true);

        $this->expectException(CategoryNotFoundException::class);

        try {
            $this->add($connection, new AddCategorySubLevel('introuvable', 'Alpha'));
        } finally {
            self::assertSame([], $connection->submissions->calls, 'a refused write never reaches the store');
        }
    }

    public function testASubLevelDuplicatingASiblingIsRefusedAndTheParentIsUnchanged(): void
    {
        $connection = $this->handler([$this->row([['label' => 'Cuvette'], ['label' => 'Abattant']])], refuseWrite: true);

        $this->expectException(CategorySublevelException::class);
        $this->expectExceptionMessage('The subcategory "Abattant" already exists at this level.');

        try {
            $this->add($connection, new AddCategorySubLevel('wc', 'Abattant'));
        } finally {
            self::assertSame([], $connection->submissions->calls, 'a refused write never reaches the store');
        }
    }

    public function testASubLevelBelowAnUnknownNodeIsRefusedAndTheParentIsUnchanged(): void
    {
        $connection = $this->handler([$this->row([['label' => 'Cuvette']])], refuseWrite: true);

        $this->expectException(CategorySublevelException::class);

        try {
            $this->add($connection, new AddCategorySubLevel('wc', 'Abattant', 'Introuvable'));
        } finally {
            self::assertSame([], $connection->submissions->calls, 'a refused write never reaches the store');
        }
    }
}

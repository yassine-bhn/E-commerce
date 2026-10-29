<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\ReplaceCategoryTree;
use App\Catalog\Application\CommandHandler\ReplaceCategoryTreeHandler;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use App\Catalog\Domain\Model\CategoryLabelInvalidException;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use App\Catalog\Domain\Model\CategorySublevelInUseException;
use PHPUnit\Framework\TestCase;

final class ReplaceCategoryTreeHandlerTest extends TestCase
{
    /**
     * The category row is answered twice — once to the ownership guard, once to the handler —
     * so the guard row is pinned by its statement and the queued row stays the content row.
     *
     * @param array<string, mixed>|false $row
     * @param list<array<string, mixed>> $usedSubLevels
     */
    private function handler(array|false $row, array $usedSubLevels = [], bool $refuseWrite = false): CategoryConnectionFake
    {
        $connection = new CategoryConnectionFake();
        $connection->rows = [$row];
        $connection->rowLists = [$usedSubLevels];
        $connection->submission();
        if ($refuseWrite) {
            $connection->expectsNoWrite();
        }

        return $connection;
    }

    private function rewrite(CategoryConnectionFake $connection, ReplaceCategoryTree $command): void
    {
        (new ReplaceCategoryTreeHandler($connection, $connection->accessPolicy(), new RecordingCatalogAudit(), $connection->submissions))($command);
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

        $this->rewrite($connection, new ReplaceCategoryTree(
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
        self::assertSame(
            ['method' => 'ownerModified', 'args' => [CatalogSubmissionKind::Category, 'wc']],
            $connection->submissions->lastCall(),
            'a regular Admin edit leaves the approval status to the store',
        );
        self::assertStringNotContainsString('approval_status', $write['sql'], 'the handler writes content only');
    }

    /**
     * spec-run-8 R35 — an Admin rewriting a row they did not submit is refused, whatever the
     * payload says; the row is read before anything is written.
     */
    public function testAnAdminCannotRewriteASubmissionTheyDoNotOwn(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->submission('PENDING_APPROVAL', 'other-admin');
        $connection->expectsNoWrite();

        $this->expectException(CatalogSubmissionNotOwnedException::class);

        try {
            $this->rewrite($connection, new ReplaceCategoryTree('wc', 'WC', [], false, 'admin-1'));
        } finally {
            self::assertSame([], $connection->submissions->calls, 'a refused write never reaches the store');
        }
    }

    /**
     * The correction's rule — a Super Admin's own save is the validation, so a submission waiting
     * for review comes out approved with the reviewer stamped on it.
     */
    public function testASuperAdminEditOfAPendingCategoryApprovesIt(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->submission('PENDING_APPROVAL', 'admin-1');
        $connection->rows = [[
            'slug' => 'wc',
            'label' => 'WC',
            'subcategories' => '[]',
            'quote_eligible' => false,
            'hidden' => false,
            'sort_order' => 1,
            'parent_slug' => null,
        ]];
        $connection->rowLists = [[]];

        $this->rewrite($connection, new ReplaceCategoryTree('wc', 'WC bis', [], false, 'super-admin', true));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertStringNotContainsString('approval_status', $write['sql'], 'the view write stays content-only');
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'wc', 'super-admin']],
            $connection->submissions->lastCall(),
            'the reviewer\'s own save is applied by the store, which owns the audit and the status',
        );
    }

    /**
     * An approved row the reviewer rewrites stays approved: the store is still asked, and it
     * writes no second approval entry because the row is already where the reviewer wants it.
     */
    public function testASuperAdminEditOfAnApprovedCategoryStillDelegatesToTheReviewerSave(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->submission('APPROVED', 'admin-1');
        $connection->rows = [[
            'slug' => 'wc',
            'label' => 'WC',
            'subcategories' => '[]',
            'quote_eligible' => false,
            'hidden' => false,
            'sort_order' => 1,
            'parent_slug' => null,
        ]];
        $connection->rowLists = [[]];

        $this->rewrite($connection, new ReplaceCategoryTree('wc', 'WC bis', [], false, 'super-admin', true));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertStringNotContainsString('approval_status', $write['sql'], 'the approval is already where the reviewer wants it');
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'wc', 'super-admin']],
            $connection->submissions->lastCall(),
            'the store decides whether the already-approved save is a transition',
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

        try {
            $this->rewrite($connection, new ReplaceCategoryTree('wc', 'WC', [['label' => 'Abattant']], false));
        } finally {
            self::assertSame([], $connection->submissions->calls, 'a refused write never reaches the store');
        }
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

        try {
            $this->rewrite($connection, new ReplaceCategoryTree('wc', 'WC', [['label' => 'A'], ['label' => 'A']], false));
        } finally {
            self::assertSame([], $connection->submissions->calls, 'a refused write never reaches the store');
        }
    }

    public function testRefusesAnEmptyLabelAndWritesNothing(): void
    {
        $connection = $this->handler(['slug' => 'wc', 'label' => 'WC', 'subcategories' => '[]', 'quote_eligible' => false, 'hidden' => false, 'sort_order' => 4, 'parent_slug' => null], refuseWrite: true);

        $this->expectException(CategoryLabelInvalidException::class);

        try {
            $this->rewrite($connection, new ReplaceCategoryTree('wc', '', [], false));
        } finally {
            self::assertSame([], $connection->submissions->calls, 'a refused write never reaches the store');
        }
    }

    public function testAnUnknownCategoryIsNotFoundAndWritesNothing(): void
    {
        $connection = $this->handler(false, refuseWrite: true);

        $this->expectException(CategoryNotFoundException::class);

        try {
            $this->rewrite($connection, new ReplaceCategoryTree('introuvable', 'WC', [], false));
        } finally {
            self::assertSame([], $connection->submissions->calls, 'a refused write never reaches the store');
        }
    }
}

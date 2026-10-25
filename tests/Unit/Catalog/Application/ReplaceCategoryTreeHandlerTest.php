<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\ReplaceCategoryTree;
use App\Catalog\Application\CommandHandler\ReplaceCategoryTreeHandler;
use App\Catalog\Domain\Model\ApprovalStatus;
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
        (new ReplaceCategoryTreeHandler($connection, $connection->accessPolicy(), new RecordingCatalogAudit()))($command);
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

        $this->rewrite($connection, new ReplaceCategoryTree('wc', 'WC', [], false, 'admin-1'));
    }

    /**
     * spec-run-8 R25 — a Super Admin editing an approved category sends it back to review in the
     * same statement, clearing the reviewer and the rejection reason it carried.
     */
    public function testASuperAdminEditOfAnApprovedCategoryReopensIt(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->submission('APPROVED', 'other-admin');
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
        self::assertStringContainsString('approval_status', $write['sql'], 'an approved row is reopened for review');
        self::assertSame(ApprovalStatus::PendingApproval->value, $write['params']['status']);
        self::assertStringContainsString('reviewed_by = NULL', $write['sql']);
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

        $this->rewrite($connection, new ReplaceCategoryTree('wc', 'WC', [['label' => 'Abattant']], false));
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

        $this->rewrite($connection, new ReplaceCategoryTree('wc', 'WC', [['label' => 'A'], ['label' => 'A']], false));
    }

    public function testRefusesAnEmptyLabelAndWritesNothing(): void
    {
        $connection = $this->handler(['slug' => 'wc', 'label' => 'WC', 'subcategories' => '[]', 'quote_eligible' => false, 'hidden' => false, 'sort_order' => 4, 'parent_slug' => null], refuseWrite: true);

        $this->expectException(CategoryLabelInvalidException::class);

        $this->rewrite($connection, new ReplaceCategoryTree('wc', '', [], false));
    }

    public function testAnUnknownCategoryIsNotFoundAndWritesNothing(): void
    {
        $connection = $this->handler(false, refuseWrite: true);

        $this->expectException(CategoryNotFoundException::class);

        $this->rewrite($connection, new ReplaceCategoryTree('introuvable', 'WC', [], false));
    }
}

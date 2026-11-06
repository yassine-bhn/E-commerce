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
    private function handler(array|false $row, array $usedSubLevels = [], bool $refuseWrite = false): InMemoryCategoryStore
    {
        $categories = new InMemoryCategoryStore();
        $categories->rows = [$row];
        $categories->usedSubLevels = [$usedSubLevels];
        $categories->submission();
        if ($refuseWrite) {
            $categories->expectsNoWrite();
        }

        return $categories;
    }

    private function rewrite(InMemoryCategoryStore $categories, ReplaceCategoryTree $command): void
    {
        (new ReplaceCategoryTreeHandler($categories, $categories, $categories->accessPolicy(), new RecordingCatalogAudit(), $categories->submissions))($command);
    }

    public function testWritesTheNewLabelFlagsAndSubLevelTreeOfTheCategory(): void
    {
        $categories = $this->handler([
            'slug' => 'wc',
            'label' => 'WC',
            'subcategories' => '[{"label":"Cuvette"}]',
            'quote_eligible' => false,
            'hidden' => true,
            'sort_order' => 4,
            'parent_slug' => null,
        ]);

        $this->rewrite($categories, new ReplaceCategoryTree(
            slug: 'wc',
            label: '  Sanitaires  ',
            subcategories: [['label' => 'Cuvette'], ['label' => 'Baignoire']],
            quoteEligible: true,
        ));

        $write = $categories->lastWrite();
        self::assertNotNull($write);
        self::assertSame('replaceContent', $write['method'], 'the rewrite goes through the tree writer');
        self::assertSame('Sanitaires', $write['category']->label);
        self::assertTrue($write['category']->quoteEligible);
        self::assertTrue($write['category']->isHidden(), 'editing the content keeps the stored visibility');
        self::assertSame(
            [['label' => 'Cuvette'], ['label' => 'Baignoire']],
            $write['category']->subcategories(),
        );
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'wc', 'system']],
            $categories->submissions->lastCall(),
            'the rewrite is applied as a reviewer save so the tree stays approved',
        );
        self::assertNotContains('setHidden', array_column($categories->writes, 'method'), 'editing the content does not change visibility');
    }

    /**
     * spec-run-8 R35 — an Admin rewriting a row they did not submit is refused, whatever the
     * payload says; the row is read before anything is written.
     */
    public function testAnAdminCannotRewriteASubmissionTheyDoNotOwn(): void
    {
        $categories = new InMemoryCategoryStore();
        $categories->submission('PENDING_APPROVAL', 'other-admin');
        $categories->expectsNoWrite();

        $this->expectException(CatalogSubmissionNotOwnedException::class);

        try {
            $this->rewrite($categories, new ReplaceCategoryTree('wc', 'WC', [], false, 'admin-1'));
        } finally {
            self::assertSame([], $categories->submissions->calls, 'a refused write never reaches the store');
        }
    }

    /**
     * The correction's rule — a Super Admin's own save is the validation, so a submission waiting
     * for review comes out approved with the reviewer stamped on it.
     */
    public function testASuperAdminEditOfAPendingCategoryApprovesIt(): void
    {
        $categories = new InMemoryCategoryStore();
        $categories->submission('PENDING_APPROVAL', 'admin-1');
        $categories->rows = [[
            'slug' => 'wc',
            'label' => 'WC',
            'subcategories' => '[]',
            'quote_eligible' => false,
            'hidden' => false,
            'sort_order' => 1,
            'parent_slug' => null,
        ]];
        $categories->usedSubLevels = [[]];

        $this->rewrite($categories, new ReplaceCategoryTree('wc', 'WC bis', [], false, 'super-admin', true));

        $write = $categories->lastWrite();
        self::assertNotNull($write);
        self::assertSame('replaceContent', $write['method']);
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'wc', 'super-admin']],
            $categories->submissions->lastCall(),
            'the reviewer\'s own save is applied by the store, which owns the audit and the status',
        );
    }

    /**
     * An approved row the reviewer rewrites stays approved: the store is still asked, and it
     * writes no second approval entry because the row is already where the reviewer wants it.
     */
    public function testASuperAdminEditOfAnApprovedCategoryStillDelegatesToTheReviewerSave(): void
    {
        $categories = new InMemoryCategoryStore();
        $categories->submission('APPROVED', 'admin-1');
        $categories->rows = [[
            'slug' => 'wc',
            'label' => 'WC',
            'subcategories' => '[]',
            'quote_eligible' => false,
            'hidden' => false,
            'sort_order' => 1,
            'parent_slug' => null,
        ]];
        $categories->usedSubLevels = [[]];

        $this->rewrite($categories, new ReplaceCategoryTree('wc', 'WC bis', [], false, 'super-admin', true));

        $write = $categories->lastWrite();
        self::assertNotNull($write);
        self::assertSame('replaceContent', $write['method']);
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'wc', 'super-admin']],
            $categories->submissions->lastCall(),
            'the store decides whether the already-approved save is a transition',
        );
    }

    public function testRefusesToRemoveASubLevelAProductStillUsesAndWritesNothing(): void
    {
        $categories = $this->handler(
            [
                'slug' => 'wc',
                'label' => 'WC',
                'subcategories' => '[{"label":"Cuvette"},{"label":"Abattant"}]',
                'quote_eligible' => false,
                'hidden' => false,
                'sort_order' => 4,
                'parent_slug' => null,
            ],
            ['Cuvette'],
            refuseWrite: true,
        );

        $this->expectException(CategorySublevelInUseException::class);
        $this->expectExceptionMessage('Cuvette');

        try {
            $this->rewrite($categories, new ReplaceCategoryTree('wc', 'WC', [['label' => 'Abattant']], false));
        } finally {
            self::assertSame([], $categories->submissions->calls, 'a refused write never reaches the store');
        }
    }

    public function testRefusesADuplicatedSiblingLabelAndWritesNothing(): void
    {
        $categories = $this->handler([
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
            $this->rewrite($categories, new ReplaceCategoryTree('wc', 'WC', [['label' => 'A'], ['label' => 'A']], false));
        } finally {
            self::assertSame([], $categories->submissions->calls, 'a refused write never reaches the store');
        }
    }

    public function testRefusesAnEmptyLabelAndWritesNothing(): void
    {
        $categories = $this->handler(['slug' => 'wc', 'label' => 'WC', 'subcategories' => '[]', 'quote_eligible' => false, 'hidden' => false, 'sort_order' => 4, 'parent_slug' => null], refuseWrite: true);

        $this->expectException(CategoryLabelInvalidException::class);

        try {
            $this->rewrite($categories, new ReplaceCategoryTree('wc', '', [], false));
        } finally {
            self::assertSame([], $categories->submissions->calls, 'a refused write never reaches the store');
        }
    }

    public function testAnUnknownCategoryIsNotFoundAndWritesNothing(): void
    {
        $categories = $this->handler(false, refuseWrite: true);

        $this->expectException(CategoryNotFoundException::class);

        try {
            $this->rewrite($categories, new ReplaceCategoryTree('introuvable', 'WC', [], false));
        } finally {
            self::assertSame([], $categories->submissions->calls, 'a refused write never reaches the store');
        }
    }
}

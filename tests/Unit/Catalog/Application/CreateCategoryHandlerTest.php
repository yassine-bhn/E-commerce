<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\CommandHandler\CreateCategoryHandler;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CategoryAlreadyExistsException;
use PHPUnit\Framework\TestCase;

final class CreateCategoryHandlerTest extends TestCase
{
    public function testCreatesAVisibleTopLevelCategoryAfterTheExistingOnes(): void
    {
        $categories = new InMemoryCategoryStore();

        (new CreateCategoryHandler($categories, $categories->submissions, $categories->submissions))(new CreateCategory(
            slug: 'test-cat',
            label: '  Test cat  ',
            subcategories: [['label' => 'Alpha', 'children' => [['label' => 'Alpha un']]]],
            quoteEligible: true,
            actorId: 'admin-1',
        ));

        $write = $categories->lastWrite();
        self::assertNotNull($write, 'the category is handed to the store once');
        self::assertCount(1, $categories->writes);
        self::assertSame('create', $write['method']);
        self::assertSame('test-cat', $write['category']->slug);
        self::assertSame('Test cat', $write['category']->label);
        self::assertTrue($write['category']->quoteEligible);
        self::assertFalse($write['category']->isHidden(), 'a new category is visible (R5 default)');
        self::assertSame(
            [['label' => 'Alpha', 'children' => [['label' => 'Alpha un']]]],
            $write['category']->subcategories(),
        );
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'test-cat', 'admin-1']],
            $categories->submissions->lastCall(),
            'the store creates the submission and approves it, in the same transaction',
        );
        self::assertSame(['create', 'reviewerSave'], $categories->submissions->methods(), 'the handler does not audit or decide on its own');
    }

    /**
     * spec-run-8 R2/R10/R39, spec-run-15 WP3 — a new category is a submission owned by its
     * creator, approved by the same write so it can reach the storefront at once; the store,
     * not the handler, writes the audit entries and the outbox events.
     */
    public function testANewCategoryIsAPendingSubmissionOwnedByItsCreator(): void
    {
        $categories = new InMemoryCategoryStore();

        (new CreateCategoryHandler($categories, $categories->submissions, $categories->submissions))(new CreateCategory(
            slug: 'pompes',
            label: 'Pompes',
            actorId: 'admin-1',
        ));

        $write = $categories->lastWrite();
        self::assertNotNull($write);
        self::assertSame('pompes', $write['category']->slug);
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Category, 'pompes', 'admin-1']],
            $categories->submissions->lastCall(),
            'the creator becomes the owner of the submission and its immediate approval',
        );
        self::assertSame(['create', 'reviewerSave'], $categories->submissions->methods(), 'the handler does not audit or decide on its own');
    }

    public function testAnEmptyLabelIsRefusedBeforeAnythingIsWritten(): void
    {
        $categories = new InMemoryCategoryStore();
        $categories->expectsNoWrite();

        $this->expectException(\DomainException::class);

        try {
            (new CreateCategoryHandler($categories, $categories->submissions, $categories->submissions))(new CreateCategory('vide', '   '));
        } finally {
            self::assertSame([], $categories->submissions->calls, 'a refused creation never reaches the store');
        }
    }

    public function testADuplicateSlugIsRefusedAndNothingIsWritten(): void
    {
        $categories = new InMemoryCategoryStore();
        $categories->failNextCreate = CategoryAlreadyExistsException::forSlug('sanitaires');

        $this->expectException(CategoryAlreadyExistsException::class);
        $this->expectExceptionMessage('A category with the slug "sanitaires" already exists.');

        try {
            (new CreateCategoryHandler($categories, $categories->submissions, $categories->submissions))(new CreateCategory('sanitaires', 'Plomberie bis'));
        } finally {
            self::assertSame([], $categories->submissions->calls, 'a refused creation writes no submission (R45)');
        }
    }
}

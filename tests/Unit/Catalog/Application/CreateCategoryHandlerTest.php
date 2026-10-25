<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\CommandHandler\CreateCategoryHandler;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\CategoryAlreadyExistsException;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

final class CreateCategoryHandlerTest extends TestCase
{
    public function testCreatesAVisibleTopLevelCategoryAfterTheExistingOnes(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->scalars = [5];
        $audit = new RecordingCatalogAudit();

        (new CreateCategoryHandler($connection, $audit))(new CreateCategory(
            slug: 'test-cat',
            label: '  Test cat  ',
            subcategories: [['label' => 'Alpha', 'children' => [['label' => 'Alpha un']]]],
            quoteEligible: true,
            actorId: 'admin-1',
        ));

        $write = $connection->lastWrite();
        self::assertNotNull($write, 'the category row is written once');
        self::assertCount(1, $connection->writes);
        self::assertStringContainsString('INSERT INTO storefront_category', $write['sql']);
        self::assertSame('test-cat', $write['params']['slug']);
        self::assertSame('Test cat', $write['params']['label']);
        self::assertTrue($write['params']['quote']);
        self::assertFalse($write['params']['hidden'], 'a new category is visible (R5 default)');
        self::assertSame(6, $write['params']['ord'], 'the sort order follows the last category');
        self::assertSame(
            [['label' => 'Alpha', 'children' => [['label' => 'Alpha un']]]],
            json_decode((string) $write['params']['subs'], true),
        );
    }

    /**
     * spec-run-8 R2/R10/R39 — a new category is a pending submission owned by its creator, and
     * the creation is audited with the same actor.
     */
    public function testANewCategoryIsAPendingSubmissionOwnedByItsCreatorAndAudited(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->scalars = [0];
        $audit = new RecordingCatalogAudit();

        (new CreateCategoryHandler($connection, $audit))(new CreateCategory(
            slug: 'pompes',
            label: 'Pompes',
            actorId: 'admin-1',
        ));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertSame(ApprovalStatus::PendingApproval->value, $write['params']['approval'], 'no field of the request can make it approved');
        self::assertSame('admin-1', $write['params']['submitted_by']);
        self::assertNotNull($write['params']['submitted_at']);
        self::assertSame(
            ['action' => 'CATALOG_SUBMISSION_CREATED', 'target' => 'category', 'slug' => 'pompes', 'actor' => 'admin-1', 'reason' => null],
            $audit->last(),
        );
    }

    public function testAnEmptyLabelIsRefusedBeforeAnythingIsWritten(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->expectsNoWrite();

        $this->expectException(\DomainException::class);

        (new CreateCategoryHandler($connection, new RecordingCatalogAudit()))(new CreateCategory('vide', '   '));
    }

    public function testADuplicateSlugIsRefusedAndNothingIsWritten(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->scalars = [3];
        $connection->failNextWrite = new UniqueConstraintViolationException(
            new class extends \RuntimeException implements Driver\Exception {
                public function getSQLState(): string
                {
                    return '23505';
                }
            },
            null,
        );
        $audit = new RecordingCatalogAudit();

        $this->expectException(CategoryAlreadyExistsException::class);
        $this->expectExceptionMessage('A category with the slug "sanitaires" already exists.');

        try {
            (new CreateCategoryHandler($connection, $audit))(new CreateCategory('sanitaires', 'Plomberie bis'));
        } finally {
            self::assertSame([], $audit->records, 'a refused creation writes no audit row (R45)');
        }
    }
}

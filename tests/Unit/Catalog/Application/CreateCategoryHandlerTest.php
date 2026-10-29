<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\CommandHandler\CreateCategoryHandler;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
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

        (new CreateCategoryHandler($connection, $connection->submissions))(new CreateCategory(
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
        self::assertArrayNotHasKey('approval', $write['params'], 'the view insert writes content only');
        self::assertSame(
            [['label' => 'Alpha', 'children' => [['label' => 'Alpha un']]]],
            json_decode((string) $write['params']['subs'], true),
        );
        self::assertSame(
            ['method' => 'create', 'args' => [CatalogSubmissionKind::Category, 'test-cat', 'admin-1']],
            $connection->submissions->lastCall(),
            'the store creates the pending submission in the same transaction',
        );
    }

    /**
     * spec-run-8 R2/R10/R39, spec-run-15 WP3 — a new category is a pending submission owned by
     * its creator; the store, not the handler, writes the audit entry and the outbox event.
     */
    public function testANewCategoryIsAPendingSubmissionOwnedByItsCreator(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->scalars = [0];

        (new CreateCategoryHandler($connection, $connection->submissions))(new CreateCategory(
            slug: 'pompes',
            label: 'Pompes',
            actorId: 'admin-1',
        ));

        $write = $connection->lastWrite();
        self::assertNotNull($write);
        self::assertArrayNotHasKey('approval', $write['params'], 'no handler-written approval column');
        self::assertSame(
            ['method' => 'create', 'args' => [CatalogSubmissionKind::Category, 'pompes', 'admin-1']],
            $connection->submissions->lastCall(),
            'the creator becomes the owner of a fresh pending submission',
        );
        self::assertSame(['create'], $connection->submissions->methods(), 'the handler does not audit or decide on its own');
    }

    public function testAnEmptyLabelIsRefusedBeforeAnythingIsWritten(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->expectsNoWrite();

        $this->expectException(\DomainException::class);

        try {
            (new CreateCategoryHandler($connection, $connection->submissions))(new CreateCategory('vide', '   '));
        } finally {
            self::assertSame([], $connection->submissions->calls, 'a refused creation never reaches the store');
        }
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
        $this->expectException(CategoryAlreadyExistsException::class);
        $this->expectExceptionMessage('A category with the slug "sanitaires" already exists.');

        try {
            (new CreateCategoryHandler($connection, $connection->submissions))(new CreateCategory('sanitaires', 'Plomberie bis'));
        } finally {
            self::assertSame([], $connection->submissions->calls, 'a refused creation writes no submission (R45)');
        }
    }
}

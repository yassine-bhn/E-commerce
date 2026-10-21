<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\CommandHandler\CreateCategoryHandler;
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

        (new CreateCategoryHandler($connection))(new CreateCategory(
            slug: 'test-cat',
            label: '  Test cat  ',
            subcategories: [['label' => 'Alpha', 'children' => [['label' => 'Alpha un']]]],
            quoteEligible: true,
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

    public function testAnEmptyLabelIsRefusedBeforeAnythingIsWritten(): void
    {
        $connection = new CategoryConnectionFake();
        $connection->expectsNoWrite();

        $this->expectException(\DomainException::class);

        (new CreateCategoryHandler($connection))(new CreateCategory('vide', '   '));
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

        (new CreateCategoryHandler($connection))(new CreateCategory('sanitaires', 'Plomberie bis'));
    }
}

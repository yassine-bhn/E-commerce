<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Service\CategoryHierarchyValidator;
use App\Catalog\Application\Service\ProductDeletionGuard;
use App\Catalog\Domain\Model\CategoryHierarchyCycleException;
use App\Catalog\Domain\Model\CategoryNotEmptyException;
use App\Catalog\Domain\Model\ProductHasOrdersCannotBeDeletedException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class CatalogBusinessRulesTest extends TestCase
{
    public function testHierarchyValidatorThrowsWhenParentIsSelf(): void
    {
        $connection = $this->createStub(Connection::class);
        $validator = new CategoryHierarchyValidator($connection);

        $this->expectException(CategoryHierarchyCycleException::class);
        $this->expectExceptionMessage('Category "piscine" cannot be set as its own parent.');

        $validator->validateParentAssignment('piscine', 'piscine');
    }

    public function testHierarchyValidatorThrowsWhenParentIsADescendant(): void
    {
        $connection = $this->createMock(Connection::class);

        // Target parent 'piscine-pompes' has parent chain: piscine-pompes -> piscine-technique -> piscine
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['slug' => 'piscine-pompes', 'parent_slug' => 'piscine-technique'],
                ['slug' => 'piscine-technique', 'parent_slug' => 'piscine'],
                ['slug' => 'piscine', 'parent_slug' => null],
            ]);

        $validator = new CategoryHierarchyValidator($connection);

        $this->expectException(CategoryHierarchyCycleException::class);
        $this->expectExceptionMessage('Circular category hierarchy detected: "piscine-pompes" is a descendant of "piscine".');

        $validator->validateParentAssignment('piscine', 'piscine-pompes');
    }

    public function testCategoryDeletionThrowsWhenCategoryHasSubcategoriesOrProducts(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['subcategories_count' => 2, 'products_count' => 5]);

        $validator = new CategoryHierarchyValidator($connection);

        $this->expectException(CategoryNotEmptyException::class);
        $this->expectExceptionMessage('Cannot delete category "plomberie": it contains 2 subcategories and 5 products.');

        $validator->ensureCanDeleteCategory('plomberie');
    }

    public function testProductDeletionThrowsWhenProductHasHistoricalOrders(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(3); // 3 orders contain this product

        $guard = new ProductDeletionGuard($connection);

        $this->expectException(ProductHasOrdersCannotBeDeletedException::class);
        $this->expectExceptionMessage('Product "baignoire-d-code" cannot be deleted because it is associated with 3 existing orders. Please unpublish or hide the product instead.');

        $guard->ensureCanDeleteProduct('baignoire-d-code');
    }
}

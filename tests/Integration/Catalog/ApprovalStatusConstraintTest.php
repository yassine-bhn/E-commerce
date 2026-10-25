<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Tests\Integration\Catalog\Support\ApprovalAttempt;
use App\Tests\Integration\Catalog\Support\CatalogRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * R19 — the three approval states are a storage rule, not only a domain rule.
 *
 * `ApprovalStatus` refuses an unknown value before the workflow can write it, so the `CHECK`
 * constraints added by Version20261023000000 are the last line of defence for every writer that
 * bypasses the enum — an import, a manual fix, a future context. These tests write an invalid
 * status straight into the tables to prove PostgreSQL itself refuses it and keeps the stored row.
 */
final class ApprovalStatusConstraintTest extends KernelTestCase
{
    private const string PRODUCT_SLUG = 'it-status-constraint-product';
    private const string CATEGORY_SLUG = 'it-status-constraint-category';

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::connection();
        $this->removeRows($connection);

        CatalogRow::product($connection, self::PRODUCT_SLUG);
        CatalogRow::category($connection, self::CATEGORY_SLUG);
    }

    protected function tearDown(): void
    {
        $this->removeRows(self::connection());

        parent::tearDown();
    }

    public function testTheProductTableRefusesAnApprovalStatusTheWorkflowNeverWrites(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/chk_storefront_product_approval_status/');

        self::connection()->executeStatement(
            'UPDATE storefront_product_view SET approval_status = :status WHERE slug = :slug',
            ['status' => 'BOGUS', 'slug' => self::PRODUCT_SLUG],
        );
    }

    public function testARefusedProductStatusLeavesTheStoredStatusUnchanged(): void
    {
        $failure = ApprovalAttempt::failureOf(static fn () => self::connection()->executeStatement(
            'UPDATE storefront_product_view SET approval_status = :status WHERE slug = :slug',
            ['status' => 'BOGUS', 'slug' => self::PRODUCT_SLUG],
        ));

        self::assertInstanceOf(DriverException::class, $failure, 'the product CHECK constraint is what refuses the write');
        self::assertSame('23514', $failure->getSQLState(), 'SQLSTATE 23514 is a check violation');
        self::assertStringContainsString('chk_storefront_product_approval_status', $failure->getMessage());
        self::assertSame('APPROVED', $this->storedApprovalStatus('storefront_product_view', self::PRODUCT_SLUG), 'the refused UPDATE leaves the row as it was');
    }

    public function testTheCategoryTableRefusesAnApprovalStatusTheWorkflowNeverWrites(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/chk_storefront_category_approval_status/');

        self::connection()->executeStatement(
            'UPDATE storefront_category SET approval_status = :status WHERE slug = :slug',
            ['status' => 'BOGUS', 'slug' => self::CATEGORY_SLUG],
        );
    }

    public function testARefusedCategoryStatusLeavesTheStoredStatusUnchanged(): void
    {
        $failure = ApprovalAttempt::failureOf(static fn () => self::connection()->executeStatement(
            'UPDATE storefront_category SET approval_status = :status WHERE slug = :slug',
            ['status' => 'BOGUS', 'slug' => self::CATEGORY_SLUG],
        ));

        self::assertInstanceOf(DriverException::class, $failure, 'the category CHECK constraint is what refuses the write');
        self::assertSame('23514', $failure->getSQLState(), 'SQLSTATE 23514 is a check violation');
        self::assertStringContainsString('chk_storefront_category_approval_status', $failure->getMessage());
        self::assertSame('APPROVED', $this->storedApprovalStatus('storefront_category', self::CATEGORY_SLUG), 'the refused UPDATE leaves the row as it was');
    }

    private function storedApprovalStatus(string $table, string $slug): string
    {
        $status = self::connection()->fetchOne(
            sprintf('SELECT approval_status FROM %s WHERE slug = :slug', $table),
            ['slug' => $slug],
        );
        self::assertIsString($status, 'the fixture row must survive the refused statement');

        return $status;
    }

    private function removeRows(Connection $connection): void
    {
        CatalogRow::deleteProducts($connection, self::PRODUCT_SLUG);
        CatalogRow::deleteCategories($connection, self::CATEGORY_SLUG);
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }
}

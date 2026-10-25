<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Command\DecideCatalogSubmission;
use App\Catalog\Application\CommandHandler\DecideCatalogSubmissionHandler;
use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Infrastructure\Audit\DbalCatalogAuditWriter;
use App\Tests\Integration\Catalog\Support\ApprovalAttempt;
use App\Tests\Integration\Catalog\Support\CatalogRow;
use App\Tests\Integration\Catalog\Support\FailingCatalogAuditPort;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * R38 — the decision and its audit entry are one write: the adapter joins the transaction the
 * handler opens, so either both are committed or neither is.
 *
 * The handler is built here with the real connection and a port that refuses to write the trail.
 * That is the only way to fail the second half of the transaction deterministically; a real audit
 * failure would need a broken database.
 */
final class CatalogAuditTransactionTest extends KernelTestCase
{
    private const string REVIEWED_PRODUCT = 'it-audit-tx-product';
    private const string AUDIT_TARGET = 'it-audit-tx-target';
    private const string REVIEWER_ID = '01929312-0000-7000-8000-0000000000aa';

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::connection();
        $this->removeRows($connection);

        CatalogRow::product($connection, self::REVIEWED_PRODUCT, 'PENDING_APPROVAL');
    }

    protected function tearDown(): void
    {
        $this->removeRows(self::connection());

        parent::tearDown();
    }

    public function testAnApprovalWhoseAuditWriteFailsLeavesTheSubmissionPending(): void
    {
        $handler = new DecideCatalogSubmissionHandler(self::connection(), new FailingCatalogAuditPort());
        $command = new DecideCatalogSubmission(
            CatalogSubmissionKind::Product,
            self::REVIEWED_PRODUCT,
            CatalogDecision::Approve,
            self::REVIEWER_ID,
        );

        $failure = ApprovalAttempt::failureOf(static function () use ($handler, $command): void {
            $handler($command);
        });

        self::assertInstanceOf(\RuntimeException::class, $failure, 'the audit failure reaches the caller');
        self::assertSame(FailingCatalogAuditPort::MESSAGE, $failure->getMessage());

        $row = $this->reviewedRow();
        self::assertSame('PENDING_APPROVAL', $row['approval_status'], 'the decision is not committed without its audit entry');
        self::assertNull($row['reviewed_by'], 'the reviewer is not committed without the audit entry');
        self::assertNull($row['reviewed_at'], 'the decision time is not committed without the audit entry');
    }

    public function testAnAuditEntryJoinsTheCallersTransactionAndDisappearsWhenItRollsBack(): void
    {
        $connection = self::connection();
        self::assertFalse($connection->isTransactionActive(), 'the test must own the outermost transaction');

        $connection->beginTransaction();
        (new DbalCatalogAuditWriter($connection))->record(
            'CATALOG_SUBMISSION_APPROVED',
            'product',
            self::AUDIT_TARGET,
            self::REVIEWER_ID,
        );
        $insideTransaction = $this->auditCount();
        $connection->rollBack();

        self::assertSame(1, $insideTransaction, 'the adapter writes through the transaction the caller opened');
        self::assertSame(0, $this->auditCount(), 'rolling the caller back takes the audit entry with it');
    }

    /** @return array<string, mixed> */
    private function reviewedRow(): array
    {
        $row = self::connection()->fetchAssociative(
            'SELECT approval_status, reviewed_by, reviewed_at FROM storefront_product_view WHERE slug = :slug',
            ['slug' => self::REVIEWED_PRODUCT],
        );
        self::assertIsArray($row, 'the pending fixture row must still exist');

        return $row;
    }

    private function auditCount(): int
    {
        return (int) self::connection()->fetchOne(
            'SELECT count(*) FROM catalog_audit_log WHERE target_type = :type AND target_slug = :slug',
            ['type' => 'product', 'slug' => self::AUDIT_TARGET],
        );
    }

    private function removeRows(Connection $connection): void
    {
        CatalogRow::deleteProducts($connection, self::REVIEWED_PRODUCT);
        CatalogRow::deleteAuditEntries($connection, self::AUDIT_TARGET);
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }
}

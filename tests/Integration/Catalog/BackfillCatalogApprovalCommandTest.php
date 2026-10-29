<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Port\CatalogSubmissionStore;
use App\Catalog\Infrastructure\Audit\DbalCatalogAuditWriter;
use App\Catalog\Infrastructure\Console\BackfillCatalogApprovalCommand;
use App\Catalog\Infrastructure\Persistence\DbalCatalogSubmissionStore;
use App\Catalog\Infrastructure\Projection\ProjectStorefrontApproval;
use App\Shared\Infrastructure\Outbox\DoctrineOutbox;
use App\Tests\Integration\Catalog\Support\CatalogRow;
use App\Tests\Integration\Catalog\Support\MigrationProbeDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * spec-run-12 R13 / spec-run-15 R11 — the legacy backfill seeds the write model through its store.
 *
 * It replaces the two blanket UPDATEs the approval migration used to run (MIG-002). Every view row
 * without a submission is imported exactly as it stands, then the rows that predate the workflow
 * are approved through the store, so their audit and outbox entries are the same ones a reviewer
 * would have produced. A second run imports nothing, approves nothing and writes nothing.
 *
 * It runs against the disposable probe database so the suite's own catalogue is never re-approved.
 */
final class BackfillCatalogApprovalCommandTest extends KernelTestCase
{
    private const string LEGACY_PRODUCT = 'it-backfill-legacy-product';
    private const string SUBMITTED_PRODUCT = 'it-backfill-submitted-product';
    private const string LEGACY_APPROVED_PRODUCT = 'it-backfill-approved-product';
    private const string LEGACY_CATEGORY = 'it-backfill-legacy-category';

    private static ?MigrationProbeDatabase $probe = null;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::$probe = MigrationProbeDatabase::inTestEnvironment(self::connection(), $kernel->getEnvironment());
    }

    public static function tearDownAfterClass(): void
    {
        self::$probe?->drop();
        self::$probe = null;

        parent::tearDownAfterClass();
    }

    public function testTheBackfillImportsAndApprovesThroughTheStoreAndASecondRunWritesNothing(): void
    {
        $probe = self::probe();
        $probe->create();
        $probe->migrateUpToTheCatalogSubmissionMigration();
        $this->seedLegacyCatalogue();

        self::assertSame(0, $this->submissionCount(), 'the migration itself seeds nothing; that is this command\'s job');
        self::assertSame(1, $this->approvedProducts(), 'only the fixture that was already approved is approved before the command');

        $dryRun = $this->runBackfill();
        self::assertSame(0, $dryRun->getStatusCode());
        self::assertStringContainsString('Dry run', $this->display($dryRun));
        self::assertStringContainsString('would import', $this->display($dryRun));
        self::assertStringContainsString('Nothing was written', $this->display($dryRun));
        self::assertSame(0, $this->submissionCount(), 'the dry run changed no row');
        self::assertSame('PENDING_APPROVAL', $probe->stringValue('storefront_product_view', 'approval_status', self::LEGACY_PRODUCT));

        $applied = $this->runBackfill(['--apply' => true]);
        self::assertSame(0, $applied->getStatusCode());
        self::assertStringContainsString('Imported', $this->display($applied));

        self::assertSame('APPROVED', $probe->stringValue('storefront_product_view', 'approval_status', self::LEGACY_PRODUCT), 'the legacy product is approved');
        self::assertTrue($probe->booleanValue('storefront_product_view', 'published', self::LEGACY_PRODUCT), 'the legacy product is published, so the storefront shows it as before the workflow');
        self::assertSame('APPROVED', $probe->stringValue('storefront_category', 'approval_status', self::LEGACY_CATEGORY), 'the legacy category is approved, so its products stay visible');

        self::assertSame('APPROVED', $this->submissionStatus(self::LEGACY_PRODUCT), 'the decision is stored in the write model');
        self::assertSame('system:backfill-approval', $this->submissionColumn(self::LEGACY_PRODUCT, 'reviewed_by'));
        self::assertSame('APPROVED', $this->submissionStatus(self::LEGACY_CATEGORY));

        self::assertSame('PENDING_APPROVAL', $this->submissionStatus(self::SUBMITTED_PRODUCT), 'a submitted product is imported, not decided');
        self::assertSame('admin-who-submitted', $this->submissionColumn(self::SUBMITTED_PRODUCT, 'submitted_by'), 'the import preserves the submitter');
        self::assertSame('2026-10-20 10:00:00+00', $this->submissionColumn(self::SUBMITTED_PRODUCT, 'submitted_at'), 'the import preserves the submission moment');
        self::assertSame('PENDING_APPROVAL', $probe->stringValue('storefront_product_view', 'approval_status', self::SUBMITTED_PRODUCT), 'a submitted product is not legacy data and is left alone');
        self::assertFalse($probe->booleanValue('storefront_product_view', 'published', self::SUBMITTED_PRODUCT), 'the submitted product stays unpublished until it is decided');

        self::assertSame('APPROVED', $this->submissionStatus(self::LEGACY_APPROVED_PRODUCT), 'an already-approved row is imported as approved');
        self::assertSame('legacy-reviewer', $this->submissionColumn(self::LEGACY_APPROVED_PRODUCT, 'reviewed_by'), 'the import preserves the original reviewer');

        self::assertSame(
            [['CATALOG_SUBMISSION_APPROVED', 'product', 'system:backfill-approval']],
            $this->audit(self::LEGACY_PRODUCT),
            'the approval the command performs is audited by the store',
        );
        self::assertSame(
            [['CATALOG_SUBMISSION_APPROVED', 'category', 'system:backfill-approval']],
            $this->audit(self::LEGACY_CATEGORY),
        );
        self::assertSame([], $this->audit(self::LEGACY_APPROVED_PRODUCT), 'importing existing state is not a transition');
        self::assertSame([], $this->audit(self::SUBMITTED_PRODUCT));

        self::assertSame(1, $this->outboxCount(self::LEGACY_PRODUCT), 'the store emits the approval event');
        self::assertSame(1, $this->outboxCount(self::LEGACY_CATEGORY));
        self::assertSame(0, $this->outboxCount(self::LEGACY_APPROVED_PRODUCT), 'an import emits no event');

        // The rows the seed migration (`Version20261017000002`) inserts carry no metadata either, so
        // this is the assertion that covers the fresh-database path CI and the release take: after the
        // command, no legacy row is left pending or unpublished, whatever the fixture contains.
        self::assertSame(0, $this->pendingLegacyRows(), 'the catalogue seeded before the workflow is approved and published too');

        $submissionsOnce = $this->submissionCount();
        $approvedOnce = $this->approvedProducts();
        $trailOnce = $this->auditRowCount();
        $secondRun = $this->runBackfill(['--apply' => true]);

        self::assertSame(0, $secondRun->getStatusCode());
        self::assertStringContainsString('Imported 0', $this->display($secondRun), 'a second run imports nothing');
        self::assertStringContainsString('approved 0 product(s)', $this->display($secondRun), 'a second run approves nothing');
        self::assertSame($submissionsOnce, $this->submissionCount(), 'the backfill is idempotent');
        self::assertSame($approvedOnce, $this->approvedProducts(), 'the backfill is idempotent');
        self::assertSame($trailOnce, $this->auditRowCount(), 'a second run writes no audit entry');
    }

    private function seedLegacyCatalogue(): void
    {
        $connection = self::probe()->connection();

        CatalogRow::category($connection, self::LEGACY_CATEGORY, 'PENDING_APPROVAL', ['label' => 'Catégorie héritée du backfill']);

        // No submitted_*, no reviewed_*: exactly what a row written before the workflow looks like.
        CatalogRow::product($connection, self::LEGACY_PRODUCT, 'PENDING_APPROVAL', ['category_slug' => self::LEGACY_CATEGORY]);

        // Somebody's submission: it has metadata, so the backfill must not decide it.
        CatalogRow::product($connection, self::SUBMITTED_PRODUCT, 'PENDING_APPROVAL', [
            'category_slug' => self::LEGACY_CATEGORY,
            'submitted_by' => 'admin-who-submitted',
            'submitted_at' => '2026-10-20 10:00:00+00',
        ]);

        // A row that was already approved before the write model existed: import preserves it.
        CatalogRow::product($connection, self::LEGACY_APPROVED_PRODUCT, 'APPROVED', [
            'category_slug' => self::LEGACY_CATEGORY,
            'reviewed_by' => 'legacy-reviewer',
            'reviewed_at' => '2026-10-21 11:00:00+00',
            'published' => true,
        ]);
    }

    /**
     * @param array<string, bool> $options
     */
    private function runBackfill(array $options = []): CommandTester
    {
        $tester = new CommandTester(new BackfillCatalogApprovalCommand(self::probe()->connection(), $this->store()));
        $tester->execute($options);

        return $tester;
    }

    private function store(): CatalogSubmissionStore
    {
        $connection = self::probe()->connection();
        /** @var SerializerInterface $serializer */
        $serializer = static::getContainer()->get(SerializerInterface::class);

        return new DbalCatalogSubmissionStore(
            $connection,
            new DoctrineOutbox($connection, $serializer),
            new DbalCatalogAuditWriter($connection),
            new ProjectStorefrontApproval($connection),
        );
    }

    private function display(CommandTester $tester): string
    {
        return $tester->getDisplay();
    }

    private function approvedProducts(): int
    {
        return (int) self::probe()->connection()->fetchOne(
            "SELECT count(*) FROM storefront_product_view WHERE approval_status = 'APPROVED'",
        );
    }

    private function submissionCount(): int
    {
        return (int) self::probe()->connection()->fetchOne('SELECT count(*) FROM catalog_submission');
    }

    private function submissionStatus(string $slug): string
    {
        return self::probe()->stringValue('catalog_submission', 'approval_status', $slug);
    }

    private function submissionColumn(string $slug, string $column): string
    {
        return self::probe()->stringValue('catalog_submission', $column, $slug);
    }

    private function outboxCount(string $slug): int
    {
        return (int) self::probe()->connection()->fetchOne(
            'SELECT count(*) FROM outbox WHERE payload::text LIKE :slug',
            ['slug' => '%'.$slug.'%'],
        );
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function audit(string $slug): array
    {
        $rows = self::probe()->connection()->fetchAllAssociative(
            'SELECT action, target_type, actor_id FROM catalog_audit_log WHERE target_slug = :slug ORDER BY created_at ASC, id ASC',
            ['slug' => $slug],
        );

        return array_map(
            static fn (array $row): array => [(string) $row['action'], (string) $row['target_type'], (string) $row['actor_id']],
            $rows,
        );
    }

    private function auditRowCount(): int
    {
        return (int) self::probe()->connection()->fetchOne('SELECT count(*) FROM catalog_audit_log');
    }

    /** The rows the command is responsible for but has not approved and published. */
    private function pendingLegacyRows(): int
    {
        return (int) self::probe()->connection()->fetchOne(
            "SELECT count(*) FROM storefront_product_view
             WHERE submitted_by IS NULL AND submitted_at IS NULL AND reviewed_by IS NULL AND reviewed_at IS NULL
               AND (approval_status <> 'APPROVED' OR published = false)",
        );
    }

    private static function probe(): MigrationProbeDatabase
    {
        assert(self::$probe instanceof MigrationProbeDatabase);

        return self::$probe;
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }
}

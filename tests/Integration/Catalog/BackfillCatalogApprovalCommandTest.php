<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Infrastructure\Console\BackfillCatalogApprovalCommand;
use App\Tests\Integration\Catalog\Support\CatalogRow;
use App\Tests\Integration\Catalog\Support\MigrationProbeDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * spec-run-12 R13 — the legacy backfill is a command, and the command is careful.
 *
 * It replaces the two blanket UPDATEs the approval migration used to run (R12, MIG-002). The rows it
 * is allowed to touch are the ones that carry no workflow metadata at all; a product a Super Admin
 * submitted or reviewed is somebody's decision, not legacy data, and the second run must write
 * nothing because the first one already did.
 *
 * It runs against the disposable probe database so the suite's own catalogue is never re-approved.
 */
final class BackfillCatalogApprovalCommandTest extends KernelTestCase
{
    private const string LEGACY_PRODUCT = 'it-backfill-legacy-product';
    private const string SUBMITTED_PRODUCT = 'it-backfill-submitted-product';
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

    public function testTheBackfillWritesNothingUntilItIsAppliedAndThenOnlyTouchesLegacyRows(): void
    {
        $probe = self::probe();
        $probe->create();
        $probe->migrateUpToTheApprovalMigration();
        $this->seedLegacyCatalogue();

        self::assertSame(0, $this->approvedProducts(), 'the migration itself approves nothing, which is what makes this command necessary');

        $dryRun = $this->runBackfill();
        self::assertSame(0, $dryRun->getStatusCode());
        self::assertStringContainsString('Dry run', $this->display($dryRun));
        self::assertStringContainsString('Nothing was written', $this->display($dryRun));
        self::assertSame(0, $this->approvedProducts(), 'the dry run changed no row');
        self::assertSame('PENDING_APPROVAL', $probe->stringValue('storefront_product_view', 'approval_status', self::LEGACY_PRODUCT));

        $applied = $this->runBackfill(['--apply' => true]);
        self::assertSame(0, $applied->getStatusCode());
        self::assertStringContainsString('Approved', $this->display($applied));

        self::assertSame('APPROVED', $probe->stringValue('storefront_product_view', 'approval_status', self::LEGACY_PRODUCT), 'the legacy product is approved');
        self::assertTrue($probe->booleanValue('storefront_product_view', 'published', self::LEGACY_PRODUCT), 'the legacy product is published, so the storefront shows it as before the workflow');
        self::assertSame('APPROVED', $probe->stringValue('storefront_category', 'approval_status', self::LEGACY_CATEGORY), 'the legacy category is approved, so its products stay visible');

        self::assertSame('PENDING_APPROVAL', $probe->stringValue('storefront_product_view', 'approval_status', self::SUBMITTED_PRODUCT), 'a submitted product is not legacy data and is left alone');
        self::assertFalse($probe->booleanValue('storefront_product_view', 'published', self::SUBMITTED_PRODUCT), 'the submitted product stays unpublished until it is decided');

        // The rows the seed migration (`Version20261017000002`) inserts carry no metadata either, so
        // this is the assertion that covers the fresh-database path CI and the release take: after the
        // command, no legacy row is left pending or unpublished, whatever the fixture contains.
        self::assertSame(0, $this->pendingLegacyRows(), 'the catalogue seeded before the workflow is approved and published too');

        $approvedOnce = $this->approvedProducts();
        $secondRun = $this->runBackfill(['--apply' => true]);

        self::assertSame(0, $secondRun->getStatusCode());
        self::assertStringContainsString('Approved 0 product(s)', $this->display($secondRun), 'a second run finds nothing left to approve');
        self::assertSame($approvedOnce, $this->approvedProducts(), 'the backfill is idempotent');
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
    }

    /**
     * @param array<string, bool> $options
     */
    private function runBackfill(array $options = []): CommandTester
    {
        $tester = new CommandTester(new BackfillCatalogApprovalCommand(self::probe()->connection()));
        $tester->execute($options);

        return $tester;
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

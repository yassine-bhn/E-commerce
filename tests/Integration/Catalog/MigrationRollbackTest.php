<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Tests\Integration\Catalog\Support\CatalogRowCounts;
use App\Tests\Integration\Catalog\Support\LegacyCatalogRow;
use App\Tests\Integration\Catalog\Support\MigrationProbeDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * R41 / MIG-001 — the approval migration is reversible, and reversible means the data it did not
 * create is still there afterwards.
 *
 * Both behaviours run inside a disposable database that MigrationProbeDatabase creates next to
 * app_test: the development and test databases are read at most, never migrated down. The probe is
 * dropped in tearDownAfterClass, and create() recreates one a crashed run left behind.
 *
 * spec-run-12 R14 turns the old "deliberately not asserted" observation into an assertion: up() no
 * longer backfills anything (R12, MIG-002), so a row a Super Admin rejected and unpublished does not
 * come back approved and published after a down()/up() cycle — it comes back PENDING_APPROVAL and
 * unpublished, which is the safe direction the runbook documents.
 */
final class MigrationRollbackTest extends KernelTestCase
{
    private const string PRODUCT_SLUG = 'it-migration-probe-product';
    private const string CATEGORY_SLUG = 'it-migration-probe-category';

    /** @var list<string> */
    private const array PRODUCT_APPROVAL_COLUMNS = [
        'approval_status',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'published',
    ];

    /** @var list<string> */
    private const array CATEGORY_APPROVAL_COLUMNS = [
        'approval_status',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

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

    public function testRollingBackTheApprovalMigrationDropsItsSchemaAndKeepsTheCatalogueRows(): void
    {
        $appCatalogueBefore = CatalogRowCounts::catalogue(self::connection());
        $appAuditBefore = CatalogRowCounts::audit(self::connection());

        $probe = self::probe();
        $probe->create();
        self::assertSame(0, $probe->tableCount(), 'the probe starts empty, so the next call builds the whole schema');

        $probe->migrateUpToTheApprovalMigration();
        LegacyCatalogRow::seed($probe->connection(), self::PRODUCT_SLUG, self::CATEGORY_SLUG);
        $productsBeforeRollback = $probe->rowCount('storefront_product_view');
        $categoriesBeforeRollback = $probe->rowCount('storefront_category');

        $probe->rollBackTheApprovalMigration();

        self::assertSame([], $probe->existingColumns('storefront_product_view', ...self::PRODUCT_APPROVAL_COLUMNS), 'down() drops every approval column and the publication flag from products');
        self::assertSame([], $probe->existingColumns('storefront_category', ...self::CATEGORY_APPROVAL_COLUMNS), 'down() drops every approval column from categories');
        self::assertFalse($probe->hasTable('catalog_audit_log'), 'down() drops the audit trail it created');
        self::assertSame($productsBeforeRollback, $probe->rowCount('storefront_product_view'), 'down() removes columns, never product rows');
        self::assertSame($categoriesBeforeRollback, $probe->rowCount('storefront_category'), 'down() removes columns, never category rows');
        self::assertSame(LegacyCatalogRow::PRODUCT_NAME, $probe->stringValue('storefront_product_view', 'name', self::PRODUCT_SLUG), 'the product keeps its slug and name through the rollback');
        self::assertSame(LegacyCatalogRow::CATEGORY_LABEL, $probe->stringValue('storefront_category', 'label', self::CATEGORY_SLUG), 'the category keeps its slug and label through the rollback');
        self::assertSame($appCatalogueBefore, CatalogRowCounts::catalogue(self::connection()), 'the rollback ran in the probe: the test database catalogue is unchanged');
        self::assertSame($appAuditBefore, CatalogRowCounts::audit(self::connection()), 'the rollback ran in the probe: the test database audit trail is unchanged');
    }

    public function testMigratingUpAgainRestoresTheApprovalSchemaWithoutRepublishingWhatTheRollbackForgot(): void
    {
        $probe = self::probe();
        $probe->create();
        $probe->migrateUpToTheApprovalMigration();
        LegacyCatalogRow::seed($probe->connection(), self::PRODUCT_SLUG, self::CATEGORY_SLUG);
        // A Super Admin's decision, which down() cannot keep: the columns that hold it are dropped.
        $probe->connection()->executeStatement(
            "UPDATE storefront_product_view SET approval_status = 'REJECTED', published = false WHERE slug = :slug",
            ['slug' => self::PRODUCT_SLUG],
        );
        $probe->connection()->executeStatement(
            "UPDATE storefront_category SET approval_status = 'PENDING_APPROVAL' WHERE slug = :slug",
            ['slug' => self::CATEGORY_SLUG],
        );
        $productsBeforeRollback = $probe->rowCount('storefront_product_view');
        $categoriesBeforeRollback = $probe->rowCount('storefront_category');

        $probe->rollBackTheApprovalMigration();
        $probe->migrateUpToTheApprovalMigration();

        self::assertSame(self::PRODUCT_APPROVAL_COLUMNS, $probe->existingColumns('storefront_product_view', ...self::PRODUCT_APPROVAL_COLUMNS), 'up() restores every product approval column and the publication flag');
        self::assertSame(self::CATEGORY_APPROVAL_COLUMNS, $probe->existingColumns('storefront_category', ...self::CATEGORY_APPROVAL_COLUMNS), 'up() restores every category approval column');
        self::assertTrue($probe->hasTable('catalog_audit_log'), 'up() restores the audit trail');
        self::assertTrue($probe->hasConstraint('storefront_product_view', 'chk_storefront_product_approval_status'), 'the product status CHECK constraint is back');
        self::assertTrue($probe->hasConstraint('storefront_category', 'chk_storefront_category_approval_status'), 'the category status CHECK constraint is back');
        self::assertSame($productsBeforeRollback, $probe->rowCount('storefront_product_view'), 'the product rows seeded before the rollback are still there');
        self::assertSame($categoriesBeforeRollback, $probe->rowCount('storefront_category'), 'the category rows seeded before the rollback are still there');
        self::assertSame('PENDING_APPROVAL', $probe->stringValue('storefront_product_view', 'approval_status', self::PRODUCT_SLUG), 'up() no longer re-approves a product the rollback stripped: it takes the column default');
        self::assertFalse($probe->booleanValue('storefront_product_view', 'published', self::PRODUCT_SLUG), 'up() no longer re-publishes anything, so the rejected product stays invisible');
        self::assertSame('PENDING_APPROVAL', $probe->stringValue('storefront_category', 'approval_status', self::CATEGORY_SLUG), 'up() no longer re-approves a category either');
    }

    public function testNoMigrationOfTheApprovalFeatureWritesReadModelRows(): void
    {
        // R12 / MIG-002 in its cheapest form: the migrations of this feature may only describe
        // schema. The behavioural half of the same claim is the re-up test above, which would see an
        // APPROVED row if a backfill came back.
        foreach (['Version20261023000000.php', 'Version20261024000000.php'] as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 3).'/migrations/'.$file);
            self::assertDoesNotMatchRegularExpression(
                '/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(storefront_\w+|customer_profile)/i',
                $source,
                $file.' must describe schema only: read-model data is rebuilt by a command, never by a migration',
            );
        }
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

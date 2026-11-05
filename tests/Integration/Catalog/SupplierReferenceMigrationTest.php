<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Tests\Integration\Catalog\Support\MigrationProbeDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * spec-run-16 R1 / MIG-001 — the supplier reference is an optional, admin-only product field.
 *
 * The migration must add one nullable VARCHAR(64) column to storefront_product_view, leave
 * existing rows at NULL (MIG-002: no backfill), and drop only that column on rollback.
 *
 * Up and down run inside the disposable probe database owned by MigrationProbeDatabase; the
 * development and test databases are never migrated down.
 */
final class SupplierReferenceMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261105000000';
    private const string PREVIOUS_MIGRATION = 'DoctrineMigrations\Version20261101000000';
    private const string PRODUCT_SLUG = 'it-supplier-reference-migration-product';

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

    public function testAddsNullableSupplierReferenceAndRollsBackOnlyThatColumn(): void
    {
        $probe = self::probe();
        $probe->create();

        $probe->migrateUpTo(self::PREVIOUS_MIGRATION);
        self::seedProduct($probe->connection());
        $columnsBeforeMigration = self::productColumns($probe->connection());

        $probe->migrateUpTo(self::MIGRATION);

        $column = $probe->connection()->fetchAssociative(
            "SELECT data_type, character_maximum_length, is_nullable
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'storefront_product_view'
                  AND column_name = 'supplier_reference'",
        );

        self::assertIsArray($column, 'the migration adds the supplier_reference column');
        self::assertSame('character varying', $column['data_type']);
        self::assertSame(64, (int) $column['character_maximum_length']);
        self::assertSame('YES', $column['is_nullable']);
        self::assertNull(
            $probe->connection()->fetchOne(
                'SELECT supplier_reference FROM storefront_product_view WHERE slug = :slug',
                ['slug' => self::PRODUCT_SLUG],
            ),
            'existing rows keep NULL because the migration performs no backfill',
        );
        self::assertSame(
            0,
            (int) $probe->connection()->fetchOne('SELECT count(*) FROM storefront_product_view WHERE supplier_reference IS NOT NULL'),
            'every pre-existing row reads NULL, including rows seeded by earlier migrations',
        );

        $columnsAfterMigration = self::productColumns($probe->connection());
        self::assertSame(
            ['supplier_reference'],
            array_values(array_diff($columnsAfterMigration, $columnsBeforeMigration)),
            'up() adds exactly the supplier_reference column',
        );

        $rowsBeforeRollback = $probe->rowCount('storefront_product_view');

        self::rollBackSupplierReferenceMigration($probe);

        $columnsAfterRollback = self::productColumns($probe->connection());
        self::assertSame(
            ['supplier_reference'],
            array_values(array_diff($columnsAfterMigration, $columnsAfterRollback)),
            'down() drops exactly the supplier_reference column',
        );
        self::assertSame(
            $columnsBeforeMigration,
            $columnsAfterRollback,
            'down() restores the previous column list without touching any other column',
        );
        self::assertSame($rowsBeforeRollback, $probe->rowCount('storefront_product_view'), 'down() drops a column, never the product rows');
        self::assertSame(
            'SUPPLIER-PROBE-REF',
            $probe->stringValue('storefront_product_view', 'ref', self::PRODUCT_SLUG),
            'the existing customer-facing ref survives the rollback',
        );
    }

    public function testMigrationContainsNoDataStatements(): void
    {
        $path = dirname(__DIR__, 3).'/migrations/Version20261105000000.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertDoesNotMatchRegularExpression(
            '/\b(INSERT|UPDATE|DELETE)\b/i',
            $source,
            'the migration is schema-only: read-model data is never written by a migration (MIG-002)',
        );
    }

    private static function seedProduct(Connection $connection): void
    {
        $connection->executeStatement(
            'INSERT INTO storefront_product_view (
                id, slug, name, brand, ref, category_slug, subcategory,
                price_minor, currency, stock, material, dims, image_path,
                rating, reviews, description
            ) VALUES (
                gen_random_uuid(), :slug, :name, :brand, :ref, :category_slug, :subcategory,
                :price_minor, :currency, :stock, :material, :dims, :image_path,
                :rating, :reviews, :description
            )',
            [
                'slug' => self::PRODUCT_SLUG,
                'name' => 'Supplier reference probe product',
                'brand' => 'Probe',
                'ref' => 'SUPPLIER-PROBE-REF',
                'category_slug' => 'probe-category',
                'subcategory' => 'probe-subcategory',
                'price_minor' => 1990,
                'currency' => 'TND',
                'stock' => 'in_stock',
                'material' => 'wood',
                'dims' => '10x10x10',
                'image_path' => 'probe.jpg',
                'rating' => 4.5,
                'reviews' => 0,
                'description' => 'Seeded before the migration to prove existing rows stay NULL.',
            ],
        );
    }

    /**
     * Migrates down one version: the supplier-reference migration, applied last by the chain above.
     * MigrationProbeDatabase only exposes rollback helpers for the migrations it was originally
     * written for, so the test drives Doctrine's programmatic API against the probe directly.
     */
    private static function rollBackSupplierReferenceMigration(MigrationProbeDatabase $probe): void
    {
        $dependencyFactory = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => ['DoctrineMigrations' => dirname(__DIR__, 3).'/migrations'],
            ]),
            new ExistingConnection($probe->connection()),
        );

        $metadataStorage = $dependencyFactory->getMetadataStorage();
        $metadataStorage->ensureInitialized();

        $version = new Version(self::MIGRATION);
        $executed = $metadataStorage->getExecutedMigrations();

        if (!$executed->hasMigration($version)) {
            self::fail(sprintf('The probe database never ran %s; migrate it up before rolling it back.', (string) $version));
        }

        if (!$executed->getLast()->getVersion()->equals($version)) {
            self::fail(sprintf(
                '%s is not the last executed migration ("%s" is); the rollback must undo only the step under test.',
                (string) $version,
                (string) $executed->getLast()->getVersion(),
            ));
        }

        $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanForVersions([$version], Direction::DOWN);
        $dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    /**
     * @return list<string>
     */
    private static function productColumns(Connection $connection): array
    {
        $columns = $connection->fetchFirstColumn(
            "SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = current_schema() AND table_name = 'storefront_product_view'
                ORDER BY ordinal_position",
        );

        return array_map(static fn (mixed $column): string => (string) $column, $columns);
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

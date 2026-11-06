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

final class PricingColumnsMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261106000000';
    private const string PREVIOUS_MIGRATION = 'DoctrineMigrations\Version20261105000000';
    private const string PRODUCT_SLUG = 'it-pricing-columns-migration-product';

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

    public function testAddsNullablePricingColumnsAndRollsBackOnlyThoseColumns(): void
    {
        $probe = self::probe();
        $probe->create();
        $probe->migrateUpTo(self::PREVIOUS_MIGRATION);
        self::seedProduct($probe->connection());
        $columnsBeforeMigration = self::productColumns($probe->connection());

        $probe->migrateUpTo(self::MIGRATION);

        $vatColumn = $probe->connection()->fetchAssociative(
            "SELECT data_type, numeric_precision, numeric_scale, is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'storefront_product_view' AND column_name = 'vat_rate'",
        );
        self::assertIsArray($vatColumn, 'the migration adds the vat_rate column');
        self::assertSame('numeric', $vatColumn['data_type']);
        self::assertSame(5, (int) $vatColumn['numeric_precision']);
        self::assertSame(2, (int) $vatColumn['numeric_scale']);
        self::assertSame('YES', $vatColumn['is_nullable']);

        $salePriceColumn = $probe->connection()->fetchAssociative(
            "SELECT data_type, is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'storefront_product_view' AND column_name = 'sale_price_ttc_minor'",
        );
        self::assertIsArray($salePriceColumn, 'the migration adds the sale_price_ttc_minor column');
        self::assertSame('integer', $salePriceColumn['data_type']);
        self::assertSame('YES', $salePriceColumn['is_nullable']);

        self::assertNull($probe->connection()->fetchOne('SELECT vat_rate FROM storefront_product_view WHERE slug = :slug', ['slug' => self::PRODUCT_SLUG]));
        self::assertNull($probe->connection()->fetchOne('SELECT sale_price_ttc_minor FROM storefront_product_view WHERE slug = :slug', ['slug' => self::PRODUCT_SLUG]));
        self::assertSame(0, (int) $probe->connection()->fetchOne('SELECT count(*) FROM storefront_product_view WHERE vat_rate IS NOT NULL'));
        self::assertSame(0, (int) $probe->connection()->fetchOne('SELECT count(*) FROM storefront_product_view WHERE sale_price_ttc_minor IS NOT NULL'));

        $columnsAfterMigration = self::productColumns($probe->connection());
        self::assertSame(['vat_rate', 'sale_price_ttc_minor'], array_values(array_diff($columnsAfterMigration, $columnsBeforeMigration)));

        $rowsBeforeRollback = $probe->rowCount('storefront_product_view');
        self::rollBackPricingColumnsMigration($probe);

        $columnsAfterRollback = self::productColumns($probe->connection());
        self::assertSame(['vat_rate', 'sale_price_ttc_minor'], array_values(array_diff($columnsAfterMigration, $columnsAfterRollback)));
        self::assertSame($columnsBeforeMigration, $columnsAfterRollback);
        self::assertSame($rowsBeforeRollback, $probe->rowCount('storefront_product_view'));
        self::assertSame('PRICING-PROBE-REF', $probe->stringValue('storefront_product_view', 'ref', self::PRODUCT_SLUG));
    }

    public function testMigrationContainsNoDataStatements(): void
    {
        $path = dirname(__DIR__, 3).'/migrations/Version20261106000000.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE)\b/i', $source);
    }

    private static function seedProduct(Connection $connection): void
    {
        $connection->executeStatement(
            'INSERT INTO storefront_product_view (id, slug, name, brand, ref, category_slug, subcategory, price_minor, currency, stock, material, dims, image_path, rating, reviews, description) VALUES (gen_random_uuid(), :slug, :name, :brand, :ref, :category_slug, :subcategory, :price_minor, :currency, :stock, :material, :dims, :image_path, :rating, :reviews, :description)',
            [
                'slug' => self::PRODUCT_SLUG,
                'name' => 'Pricing columns probe product',
                'brand' => 'Probe',
                'ref' => 'PRICING-PROBE-REF',
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

    private static function rollBackPricingColumnsMigration(MigrationProbeDatabase $probe): void
    {
        $dependencyFactory = DependencyFactory::fromConnection(
            new ConfigurationArray(['migrations_paths' => ['DoctrineMigrations' => dirname(__DIR__, 3).'/migrations']]),
            new ExistingConnection($probe->connection()),
        );
        $metadataStorage = $dependencyFactory->getMetadataStorage();
        $metadataStorage->ensureInitialized();
        $version = new Version(self::MIGRATION);
        $executed = $metadataStorage->getExecutedMigrations();
        if (!$executed->hasMigration($version)) {
            self::fail(sprintf('The probe database never ran %s.', (string) $version));
        }
        if (!$executed->getLast()->getVersion()->equals($version)) {
            self::fail(sprintf('%s is not the last executed migration.', (string) $version));
        }
        $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanForVersions([$version], Direction::DOWN);
        $dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    private static function productColumns(Connection $connection): array
    {
        $columns = $connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'storefront_product_view' ORDER BY ordinal_position");

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

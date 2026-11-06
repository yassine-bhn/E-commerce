<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Infrastructure\Audit\DbalCatalogAuditWriter;
use App\Catalog\Infrastructure\Console\RegisterProductBrandsCommand;
use App\Catalog\Infrastructure\Persistence\DbalCatalogSubmissionStore;
use App\Catalog\Infrastructure\Projection\ProjectStorefrontApproval;
use App\Shared\Infrastructure\Outbox\DoctrineOutbox;
use App\Tests\Integration\Catalog\Support\MigrationProbeDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Serializer\SerializerInterface;

final class RegisterProductBrandsCommandTest extends KernelTestCase
{
    private const string PRODUCT_A = 'it-register-brand-product-a';
    private const string PRODUCT_B = 'it-register-brand-product-b';
    private const string BRAND_A = 'ItRegisterBrandA';
    private const string BRAND_B = 'ItRegisterBrandB';

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

    public function testItRegistersMissingBrandsFromProductsAndIsIdempotent(): void
    {
        $probe = self::probe();
        $probe->create();
        $probe->migrateUpTo(MigrationProbeDatabase::BRAND_APPROVAL_MIGRATION);

        $connection = $probe->connection();
        $this->seedProducts($connection);

        $store = $this->store($connection);

        $dryRun = $this->executeCommand($connection, $store, []);
        self::assertSame(0, $dryRun->getStatusCode());
        self::assertStringContainsString('Dry run', $this->display($dryRun));
        self::assertStringContainsString('would register 2', $this->display($dryRun));
        self::assertStringContainsString('Nothing was written', $this->display($dryRun));
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM storefront_brand WHERE name IN (:a,:b)', ['a' => self::BRAND_A, 'b' => self::BRAND_B]));

        $applied = $this->executeCommand($connection, $store, ['--apply' => true]);
        self::assertSame(0, $applied->getStatusCode());
        self::assertStringContainsString('Registered 2', $this->display($applied));

        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM storefront_brand WHERE name = :n', ['n' => self::BRAND_A]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM storefront_brand WHERE name = :n', ['n' => self::BRAND_B]));
        self::assertSame('APPROVED', (string) $connection->fetchOne("SELECT approval_status FROM catalog_submission WHERE kind = 'brands' AND slug = :s", ['s' => self::BRAND_A]));

        $secondRun = $this->executeCommand($connection, $store, ['--apply' => true]);
        self::assertSame(0, $secondRun->getStatusCode());
        self::assertStringContainsString('Registered 0', $this->display($secondRun));
    }

    private function seedProducts(Connection $connection): void
    {
        Support\CatalogRow::product($connection, self::PRODUCT_A, 'APPROVED', ['brand' => self::BRAND_A]);
        Support\CatalogRow::product($connection, self::PRODUCT_B, 'APPROVED', ['brand' => self::BRAND_B]);
    }

    /**
     * @param array<string, bool> $options
     */
    private function executeCommand(Connection $connection, DbalCatalogSubmissionStore $store, array $options): CommandTester
    {
        $tester = new CommandTester(new RegisterProductBrandsCommand($connection, $store, $store));
        $tester->execute($options);

        return $tester;
    }

    private function store(Connection $connection): DbalCatalogSubmissionStore
    {
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

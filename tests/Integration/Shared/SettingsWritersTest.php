<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Application\Port\PlatformSettingsWriter;
use App\Shared\Application\Port\ShippingPolicyWriter;
use App\Shared\Infrastructure\Persistence\DbalPlatformSettingsWriter;
use App\Shared\Infrastructure\Persistence\DbalShippingPolicyWriter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * solid Task 3.5 — the singleton-row writers: insert then update, and the JSON encoding of the
 * payment methods survives the write.
 */
final class SettingsWritersTest extends KernelTestCase
{
    /**
     * @var array<string, mixed>|false
     */
    private array|false $shippingBefore = false;

    /**
     * @var array<string, mixed>|false
     */
    private array|false $settingsBefore = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        // the singletons are shared state: every other test quotes against the shipping policy,
        // so the rows are seeded to the canonical migration state and handed back in tearDown —
        // seeding here also repairs a row a previous crashed run may have left behind
        self::connection()->executeStatement(
            'UPDATE storefront_shipping_policy SET delivery_fee_minor = 15000, free_delivery_threshold_minor = 1000000 WHERE id = 1',
        );
        self::connection()->executeStatement(
            "UPDATE platform_setting SET contact_phone = '29524088', contact_whatsapp = '29524088', auto_cancel_minutes = 30, payment_methods = '[\"Carte bancaire\",\"Virement / Paiement à la livraison\",\"Espèces à la livraison\"]' WHERE id = 1",
        );
        $this->shippingBefore = self::connection()->fetchAssociative('SELECT * FROM storefront_shipping_policy WHERE id = 1');
        $this->settingsBefore = self::connection()->fetchAssociative('SELECT * FROM platform_setting WHERE id = 1');
    }

    protected function tearDown(): void
    {
        $connection = self::connection();

        if (false !== $this->shippingBefore) {
            $connection->executeStatement(
                'UPDATE storefront_shipping_policy SET delivery_fee_minor = :fee, free_delivery_threshold_minor = :threshold WHERE id = 1',
                ['fee' => (int) $this->shippingBefore['delivery_fee_minor'], 'threshold' => (int) $this->shippingBefore['free_delivery_threshold_minor']],
            );
        }
        if (false !== $this->settingsBefore) {
            $connection->executeStatement(
                'UPDATE platform_setting SET contact_phone = :phone, contact_whatsapp = :whatsapp, auto_cancel_minutes = :minutes, payment_methods = :methods WHERE id = 1',
                [
                    'phone' => (string) $this->settingsBefore['contact_phone'],
                    'whatsapp' => (string) $this->settingsBefore['contact_whatsapp'],
                    'minutes' => (int) $this->settingsBefore['auto_cancel_minutes'],
                    'methods' => (string) $this->settingsBefore['payment_methods'],
                ],
            );
        }

        parent::tearDown();
    }

    public function testPlatformSettingsWriterUpsertsTheSingletonRow(): void
    {
        $writer = $this->writer();

        $writer->save('+216 71 000 001', '+216 98 000 001', 45, ['cod', 'bank_transfer']);

        $row = self::connection()->fetchAssociative('SELECT * FROM platform_setting WHERE id = 1');
        self::assertNotFalse($row, 'the singleton row exists');
        self::assertSame('+216 71 000 001', $row['contact_phone']);
        self::assertSame(45, (int) $row['auto_cancel_minutes']);
        self::assertSame(['cod', 'bank_transfer'], json_decode((string) $row['payment_methods'], true));

        $writer->save('+216 71 000 002', '+216 98 000 002', 30, ['cod']);

        $row = self::connection()->fetchAssociative('SELECT * FROM platform_setting WHERE id = 1');
        self::assertNotFalse($row);
        self::assertSame('+216 71 000 002', $row['contact_phone'], 'the second save updates the row');
        self::assertSame(30, (int) $row['auto_cancel_minutes']);
    }

    public function testShippingPolicyWriterUpsertsTheSingletonRow(): void
    {
        $writer = static::getContainer()->get(ShippingPolicyWriter::class);
        \assert($writer instanceof DbalShippingPolicyWriter);

        $writer->save(700, 50000);

        $row = self::connection()->fetchAssociative('SELECT * FROM storefront_shipping_policy WHERE id = 1');
        self::assertNotFalse($row, 'the singleton row exists');
        self::assertSame(700, (int) $row['delivery_fee_minor']);
        self::assertSame(50000, (int) $row['free_delivery_threshold_minor']);

        $writer->save(900, 80000);

        $row = self::connection()->fetchAssociative('SELECT * FROM storefront_shipping_policy WHERE id = 1');
        self::assertNotFalse($row);
        self::assertSame(900, (int) $row['delivery_fee_minor'], 'the second save updates the row');
    }

    private function writer(): DbalPlatformSettingsWriter
    {
        $writer = static::getContainer()->get(PlatformSettingsWriter::class);
        \assert($writer instanceof DbalPlatformSettingsWriter);

        return $writer;
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}

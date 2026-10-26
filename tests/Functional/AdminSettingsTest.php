<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminSettingsTest extends WebTestCase
{
    private const string SUPER_ADMIN_ID = '01929312-7000-7000-8000-000000000093';
    private const string ADMIN_ID = '01929312-7000-7000-8000-000000000092';

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $connection->executeStatement('DELETE FROM customer_profile WHERE id IN (:ids)', ['ids' => [self::SUPER_ADMIN_ID, self::ADMIN_ID]], ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $connection->executeStatement("UPDATE platform_setting SET contact_phone = '', contact_whatsapp = '', auto_cancel_minutes = 30, updated_at = NOW() WHERE id = 1");
        $connection->executeStatement('UPDATE storefront_shipping_policy SET delivery_fee_minor = 15, free_delivery_threshold_minor = 1000 WHERE id = 1');

        parent::tearDown();
    }

    public function testSuperAdminReadsPlatformSettingsWithDefaults(): void
    {
        $client = static::createClient();
        $this->loginAs($client, self::SUPER_ADMIN_ID, 'ROLE_SUPER_ADMIN');

        $client->jsonRequest('GET', '/api/v1/admin/settings');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('contactPhone', $body);
        self::assertArrayHasKey('contactWhatsapp', $body);
        self::assertArrayHasKey('autoCancelMinutes', $body);
        self::assertSame(30, $body['autoCancelMinutes']);
        self::assertSame('', $body['contactPhone']);
        self::assertSame('', $body['contactWhatsapp']);
    }

    public function testRegularAdminReadsSettings(): void
    {
        $client = static::createClient();
        $this->loginAs($client, self::ADMIN_ID, 'ROLE_ADMIN');

        $client->jsonRequest('GET', '/api/v1/admin/settings');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('contactPhone', $body);
    }

    public function testSuperAdminUpdatesPlatformSettings(): void
    {
        $client = static::createClient();
        $this->loginAs($client, self::SUPER_ADMIN_ID, 'ROLE_SUPER_ADMIN');

        $client->jsonRequest('PUT', '/api/v1/admin/settings', [
            'contactPhone' => '+212600112233',
            'contactWhatsapp' => '+212611223344',
            'autoCancelMinutes' => 45,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('+212600112233', $body['contactPhone']);
        self::assertSame('+212611223344', $body['contactWhatsapp']);
        self::assertSame(45, $body['autoCancelMinutes']);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        /** @var array{contact_phone: string, contact_whatsapp: string, auto_cancel_minutes: int} $row */
        $row = $connection->fetchAssociative('SELECT contact_phone, contact_whatsapp, auto_cancel_minutes FROM platform_setting WHERE id = 1');
        self::assertSame('+212600112233', $row['contact_phone']);
        self::assertSame('+212611223344', $row['contact_whatsapp']);
        self::assertSame(45, (int) $row['auto_cancel_minutes']);
    }

    public function testUpdateRejectsInvalidAutoCancelMinutes(): void
    {
        $client = static::createClient();
        $this->loginAs($client, self::SUPER_ADMIN_ID, 'ROLE_SUPER_ADMIN');

        $client->jsonRequest('PUT', '/api/v1/admin/settings', [
            'contactPhone' => '+212600112233',
            'contactWhatsapp' => '+212611223344',
            'autoCancelMinutes' => 0,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(30, (int) $connection->fetchOne('SELECT auto_cancel_minutes FROM platform_setting WHERE id = 1'));
        self::assertSame('', (string) $connection->fetchOne('SELECT contact_phone FROM platform_setting WHERE id = 1'));
    }

    public function testUpdateRejectsOverlongContactChannel(): void
    {
        $client = static::createClient();
        $this->loginAs($client, self::SUPER_ADMIN_ID, 'ROLE_SUPER_ADMIN');

        $client->jsonRequest('PUT', '/api/v1/admin/settings', [
            'contactPhone' => '+'.str_repeat('9', 32),
            'contactWhatsapp' => '+212611223344',
            'autoCancelMinutes' => 30,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRegularAdminUpdatesSettings(): void
    {
        $client = static::createClient();
        $this->loginAs($client, self::ADMIN_ID, 'ROLE_ADMIN');

        $client->jsonRequest('PUT', '/api/v1/admin/settings', [
            'contactPhone' => '+212600112233',
            'contactWhatsapp' => '+212611223344',
            'autoCancelMinutes' => 45,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(45, (int) $connection->fetchOne('SELECT auto_cancel_minutes FROM platform_setting WHERE id = 1'));
    }

    private function loginAs(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $id, string $role): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $email = sprintf('settings.%s@example.com', substr(Uuid::v4()->toRfc4122(), 0, 8));
        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Settings Test',
            'email' => $email,
            'role' => $role,
            'status' => 'active',
        ]);

        $client->loginUser(new CustomerUser($id, $email, null, $role, 'active'));
    }
}

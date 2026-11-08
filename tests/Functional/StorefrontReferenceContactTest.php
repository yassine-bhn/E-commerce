<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * I-15 — the storefront must be able to show the shop's phone and WhatsApp, so the reference
 * payload carries whatever the operator stored in the platform settings.
 */
final class StorefrontReferenceContactTest extends WebTestCase
{
    private const BRAND_NAME = 'ReferenceLogoTestBrand';

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE platform_setting SET contact_phone = '', contact_whatsapp = '' WHERE id = 1");
        $connection->executeStatement('DELETE FROM storefront_brand WHERE name = :name', ['name' => self::BRAND_NAME]);

        parent::tearDown();
    }

    public function testReferenceExposesApprovedBrandLogoReferences(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "INSERT INTO storefront_brand (name, image_path, approval_status) VALUES (:name, :image, 'APPROVED')",
            ['name' => self::BRAND_NAME, 'image' => '/uploads/brands/reference-logo.webp'],
        );

        $client->request('GET', '/api/v1/storefront/reference');
        self::assertResponseIsSuccessful();

        /** @var array{brands: list<array{name: string, image: string|null}>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $brand = array_values(array_filter($body['brands'], static fn (array $item): bool => self::BRAND_NAME === $item['name']))[0] ?? null;

        self::assertSame(
            ['name' => self::BRAND_NAME, 'image' => '/uploads/brands/reference-logo.webp'],
            $brand,
        );
    }

    public function testReferenceExposesTheConfiguredContactChannels(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE platform_setting SET contact_phone = '+212600112233', contact_whatsapp = '+212611223344' WHERE id = 1",
        );

        $client->jsonRequest('GET', '/api/v1/storefront/reference');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('contact', $body);
        self::assertSame('+212600112233', $body['contact']['phone']);
        self::assertSame('+212611223344', $body['contact']['whatsapp']);
    }

    public function testReferenceKeepsTheContactKeysWhenNothingIsConfigured(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', '/api/v1/storefront/reference');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('', $body['contact']['phone']);
        self::assertSame('', $body['contact']['whatsapp']);
    }
}

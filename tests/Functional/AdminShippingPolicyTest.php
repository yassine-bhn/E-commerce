<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * I-16 — the delivery fee and the free-delivery threshold are operator parameters, not code.
 */
final class AdminShippingPolicyTest extends WebTestCase
{
    private const string SUPER_ADMIN_ID = '01929312-7000-7000-8000-000000000091';
    private const string ADMIN_ID = '01929312-7000-7000-8000-000000000090';

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $connection->executeStatement(
            'DELETE FROM customer_profile WHERE id IN (:ids)',
            ['ids' => [self::SUPER_ADMIN_ID, self::ADMIN_ID]],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $connection->executeStatement('UPDATE storefront_shipping_policy SET delivery_fee_minor = 15000, free_delivery_threshold_minor = 1000000 WHERE id = 1');

        parent::tearDown();
    }

    /**
     * R22/R21 — the historical 15/1000 seed is converted once to 15000/1000000 millimes by
     * Version20261029000003, so the read API reports 15 DT and 1000 DT.
     */
    public function testTheSeededShippingPolicyIsExpressedInMillimes(): void
    {
        $client = static::createClient();
        $this->loginAs($client, self::SUPER_ADMIN_ID, 'ROLE_SUPER_ADMIN');

        $client->jsonRequest('GET', '/api/v1/storefront/reference');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array<string, mixed> $reference */
        $reference = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(15000, $reference['shipping']['deliveryFee']);
        self::assertSame(1000000, $reference['shipping']['freeDeliveryThreshold']);
    }

    public function testSuperAdminUpdatesTheShippingPolicy(): void
    {
        $client = static::createClient();
        $this->loginAs($client, self::SUPER_ADMIN_ID, 'ROLE_SUPER_ADMIN');

        $client->jsonRequest('PUT', '/api/v1/admin/settings/shipping', [
            'deliveryFee' => 25000,
            'freeDeliveryThreshold' => 500000,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(25000, $body['deliveryFee']);
        self::assertSame(500000, $body['freeDeliveryThreshold']);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        /** @var array{delivery_fee_minor: int, free_delivery_threshold_minor: int} $row */
        $row = $connection->fetchAssociative('SELECT delivery_fee_minor, free_delivery_threshold_minor FROM storefront_shipping_policy WHERE id = 1');
        self::assertSame(25000, $row['delivery_fee_minor']);
        self::assertSame(500000, $row['free_delivery_threshold_minor']);

        $client->jsonRequest('GET', '/api/v1/storefront/reference');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array<string, mixed> $reference */
        $reference = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(25000, $reference['shipping']['deliveryFee']);
        self::assertSame(500000, $reference['shipping']['freeDeliveryThreshold']);
    }

    public function testUpdateRejectsNegativeAmounts(): void
    {
        $client = static::createClient();
        $this->loginAs($client, self::SUPER_ADMIN_ID, 'ROLE_SUPER_ADMIN');

        $client->jsonRequest('PUT', '/api/v1/admin/settings/shipping', [
            'deliveryFee' => -1,
            'freeDeliveryThreshold' => 500000,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(15000, (int) $connection->fetchOne('SELECT delivery_fee_minor FROM storefront_shipping_policy WHERE id = 1'));
    }

    public function testRegularAdminUpdatesTheShippingPolicy(): void
    {
        $client = static::createClient();
        $this->loginAs($client, self::ADMIN_ID, 'ROLE_ADMIN');

        $client->jsonRequest('PUT', '/api/v1/admin/settings/shipping', [
            'deliveryFee' => 25000,
            'freeDeliveryThreshold' => 500000,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(25000, (int) $connection->fetchOne('SELECT delivery_fee_minor FROM storefront_shipping_policy WHERE id = 1'));
    }

    private function loginAs(KernelBrowser $client, string $id, string $role): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $email = sprintf('shipping.%s@example.com', substr(Uuid::v4()->toRfc4122(), 0, 8));
        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Shipping Policy Test',
            'email' => $email,
            'role' => $role,
            'status' => 'active',
        ]);

        $client->loginUser(new CustomerUser($id, $email, null, $role, 'active'));
    }
}

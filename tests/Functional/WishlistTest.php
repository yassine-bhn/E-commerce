<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class WishlistTest extends WebTestCase
{
    private const PRODUCT_SLUG = 'baignoire-d-code';

    /** @var list<string> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdUserIds) {
            /** @var Connection $connection */
            $connection = static::getContainer()->get(Connection::class);
            $connection->executeStatement(
                'DELETE FROM storefront_wishlist_item WHERE customer_id IN (:ids)',
                ['ids' => $this->createdUserIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->createdUserIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $this->createdUserIds = [];
        }

        parent::tearDown();
    }

    private function createCustomer(): CustomerUser
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('wishlist.%s@example.com', substr($id, 0, 8));

        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Wishlist Tester',
            'email' => $email,
            'password_hash' => password_hash('Wishlist!234', PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $this->createdUserIds[] = $id;

        return new CustomerUser($id, $email, null, 'ROLE_USER', 'active');
    }

    public function testUnauthenticatedListIsRejected(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/storefront/wishlist');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAddThenListReturnsProductPayload(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/wishlist', ['slug' => self::PRODUCT_SLUG]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        /** @var array<string, mixed> $added */
        $added = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('added', $added['status'] ?? null);
        self::assertSame(self::PRODUCT_SLUG, $added['slug'] ?? null);

        $client->request('GET', '/api/v1/storefront/wishlist');
        self::assertResponseIsSuccessful();
        /** @var array{items: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['items']);

        $item = $payload['items'][0];
        self::assertSame(self::PRODUCT_SLUG, $item['slug']);
        self::assertArrayHasKey('name', $item);
        self::assertArrayHasKey('price', $item);
        self::assertIsInt($item['price']);
    }

    public function testAddingTwiceIsIdempotent(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/wishlist', ['slug' => self::PRODUCT_SLUG]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->jsonRequest('POST', '/api/v1/storefront/wishlist', ['slug' => self::PRODUCT_SLUG]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/storefront/wishlist');
        self::assertResponseIsSuccessful();
        /** @var array{items: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['items']);
    }

    public function testRemoveDeletesFromList(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/wishlist', ['slug' => self::PRODUCT_SLUG]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('DELETE', '/api/v1/storefront/wishlist/'.self::PRODUCT_SLUG);
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $removed */
        $removed = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('removed', $removed['status'] ?? null);

        $client->request('GET', '/api/v1/storefront/wishlist');
        self::assertResponseIsSuccessful();
        /** @var array{items: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(0, $payload['items']);
    }

    public function testAddUnknownProductReturns404(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/wishlist', [
            'slug' => 'produit-inexistant-'.Uuid::v4()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Product not found', $payload['error'] ?? null);
    }

    public function testAddWithoutSlugReturns400(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/wishlist', ['slug' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testDeleteIsIdempotent(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->request('DELETE', '/api/v1/storefront/wishlist/'.self::PRODUCT_SLUG);
        self::assertResponseIsSuccessful();

        $client->request('DELETE', '/api/v1/storefront/wishlist/'.self::PRODUCT_SLUG);
        self::assertResponseIsSuccessful();
    }
}

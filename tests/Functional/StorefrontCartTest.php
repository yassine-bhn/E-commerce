<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class StorefrontCartTest extends WebTestCase
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
                'DELETE FROM storefront_cart_item WHERE cart_id IN (SELECT id FROM storefront_cart WHERE customer_id IN (:ids))',
                ['ids' => $this->createdUserIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM storefront_cart WHERE customer_id IN (:ids)',
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
        $email = sprintf('cart.%s@example.com', substr($id, 0, 8));

        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Cart Tester',
            'email' => $email,
            'password_hash' => password_hash('Cart!234', PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $this->createdUserIds[] = $id;

        return new CustomerUser($id, $email, null, 'ROLE_USER', 'active');
    }

    /**
     * @return array{id: ?string, items: list<array<string, mixed>>, itemCount: int}
     */
    private function getCart(KernelBrowser $client): array
    {
        $client->request('GET', '/api/v1/storefront/cart');
        self::assertResponseIsSuccessful();

        /** @var array{id: ?string, items: list<array<string, mixed>>, itemCount: int} $cart */
        $cart = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $cart;
    }

    public function testGetEmptyCartReturnsEmptyShape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $cart = $this->getCart($client);

        self::assertNull($cart['id']);
        self::assertSame([], $cart['items']);
        self::assertSame(0, $cart['itemCount']);
    }

    public function testAddItemThenGetPersistsCart(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => self::PRODUCT_SLUG,
            'quantity' => 2,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        /** @var array{item: array<string, mixed>, cart: array<string, mixed>} $added */
        $added = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $added['item']['quantity'] ?? null);

        $cart = $this->getCart($client);

        self::assertSame(2, $cart['itemCount']);
        self::assertCount(1, $cart['items']);
        self::assertSame(self::PRODUCT_SLUG, $cart['items'][0]['slug']);
    }

    public function testUpdateItemQuantity(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => self::PRODUCT_SLUG,
            'quantity' => 2,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        /** @var array{item: array<string, mixed>, cart: array<string, mixed>} $added */
        $added = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $itemId = $added['item']['id'] ?? null;
        self::assertIsString($itemId);
        self::assertNotSame('', $itemId);

        $client->jsonRequest('PATCH', '/api/v1/storefront/cart/items/'.$itemId, ['quantity' => 5]);
        self::assertResponseIsSuccessful();
        /** @var array{item: array<string, mixed>|null, cart: array<string, mixed>} $updated */
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotNull($updated['item']);
        self::assertSame(5, $updated['item']['quantity'] ?? null);

        $cart = $this->getCart($client);
        self::assertSame(5, $cart['itemCount']);
        self::assertCount(1, $cart['items']);
        self::assertSame(5, $cart['items'][0]['quantity']);
    }

    public function testRemoveItemThenClearCart(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => self::PRODUCT_SLUG,
            'quantity' => 2,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        /** @var array{item: array<string, mixed>, cart: array<string, mixed>} $added */
        $added = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $itemId = $added['item']['id'] ?? null;
        self::assertIsString($itemId);
        self::assertNotSame('', $itemId);

        $client->request('DELETE', '/api/v1/storefront/cart/items/'.$itemId);
        self::assertResponseIsSuccessful();
        /** @var array{items: list<array<string, mixed>>, itemCount: int} $removed */
        $removed = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $removed['itemCount']);
        self::assertSame([], $removed['items']);

        $cart = $this->getCart($client);
        self::assertSame(0, $cart['itemCount']);
        self::assertSame([], $cart['items']);

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => self::PRODUCT_SLUG,
            'quantity' => 1,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('DELETE', '/api/v1/storefront/cart');
        self::assertResponseIsSuccessful();
        /** @var array{id: ?string, items: list<array<string, mixed>>, itemCount: int} $cleared */
        $cleared = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $cleared['itemCount']);
        self::assertSame([], $cleared['items']);
    }

    public function testAddUnknownProductIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => 'produit-inexistant-'.Uuid::v4()->toRfc4122(),
            'quantity' => 1,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $cart = $this->getCart($client);
        self::assertSame(0, $cart['itemCount']);
        self::assertSame([], $cart['items']);
    }

    public function testAddWithoutSlugReturns400(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', ['slug' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testAnonymousCartEndpointsAreRejected(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/storefront/cart');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => self::PRODUCT_SLUG,
            'quantity' => 1,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('DELETE', '/api/v1/storefront/cart');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnonymousQuoteRemainsReachable(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/v1/storefront/cart/quote', [
            'lines' => [
                ['slug' => self::PRODUCT_SLUG, 'quantity' => 1],
            ],
        ]);

        self::assertResponseIsSuccessful();
    }
}

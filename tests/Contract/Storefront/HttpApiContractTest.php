<?php

declare(strict_types=1);

namespace App\Tests\Contract\Storefront;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Pins the HTTP JSON contract consumed by the storefront frontend for the
 * cart, catalog, quote and ordering endpoints. Assertions intentionally check
 * the exact key set and the PHP type of each value, not brittle business values.
 */
final class HttpApiContractTest extends WebTestCase
{
    private const PRODUCT_SLUG = 'baignoire-d-code';
    private const PASSWORD = 'Contract!234';

    /** @var list<string> */
    private array $createdUserIds = [];

    /** @var list<string> */
    private array $createdOrderIds = [];

    /** @var list<string> */
    private array $createdIdempotencyKeys = [];

    protected function tearDown(): void
    {
        if ([] === $this->createdUserIds && [] === $this->createdOrderIds && [] === $this->createdIdempotencyKeys) {
            parent::tearDown();

            return;
        }

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if ([] !== $this->createdUserIds) {
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
        }

        if ([] !== $this->createdOrderIds) {
            $connection->executeStatement(
                'DELETE FROM ordering_order_audit_trail WHERE order_id IN (:ids)',
                ['ids' => $this->createdOrderIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM ordering_order_lines WHERE order_id IN (:ids)',
                ['ids' => $this->createdOrderIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM outbox WHERE aggregate_id IN (:ids)',
                ['ids' => $this->createdOrderIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM ordering_orders WHERE id IN (:ids)',
                ['ids' => $this->createdOrderIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        if ([] !== $this->createdIdempotencyKeys) {
            $connection->executeStatement(
                'DELETE FROM processed_commands WHERE key IN (:keys)',
                ['keys' => $this->createdIdempotencyKeys],
                ['keys' => ArrayParameterType::STRING],
            );
        }

        if ([] !== $this->createdUserIds) {
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->createdUserIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        $this->createdUserIds = [];
        $this->createdOrderIds = [];
        $this->createdIdempotencyKeys = [];

        parent::tearDown();
    }

    // --- Shape contract: authentication ---------------------------------------

    public function testLoginWithRememberMeFlagReturnsTheIdentityShape(): void
    {
        $client = static::createClient();
        $customer = $this->createCustomer();

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $customer->getEmail(),
            'password' => self::PASSWORD,
            'remember_me' => true,
        ]);
        self::assertResponseIsSuccessful();

        $data = $this->decode($client);
        $this->assertShape($this->identityShape(), $data);

        self::assertSame($customer->getId(), $data['id']);
        self::assertSame($customer->getEmail(), $data['email']);
        self::assertSame(['ROLE_USER'], $data['roles']);
    }

    public function testLoginWithoutRememberMeFlagReturnsTheIdentityShape(): void
    {
        $client = static::createClient();
        $customer = $this->createCustomer();

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $customer->getEmail(),
            'password' => self::PASSWORD,
        ]);
        self::assertResponseIsSuccessful();

        $data = $this->decode($client);
        $this->assertShape($this->identityShape(), $data);
    }

    // --- Shape contract: cart -------------------------------------------------

    public function testEmptyCartResponseShape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->request('GET', '/api/v1/storefront/cart');
        self::assertResponseIsSuccessful();

        $cart = $this->decode($client);
        $this->assertShape($this->cartShape(), $cart);

        self::assertNull($cart['id']);
        self::assertSame([], $cart['items']);
        self::assertSame(0, $cart['itemCount']);
    }

    public function testCartResponseShapeWithItem(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => self::PRODUCT_SLUG,
            'quantity' => 2,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('GET', '/api/v1/storefront/cart');
        self::assertResponseIsSuccessful();

        $cart = $this->decode($client);
        $this->assertShape($this->cartShape(), $cart);

        $items = $cart['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);

        $item = array_values($items)[0] ?? null;
        self::assertIsArray($item);
        $this->assertShape($this->cartItemShape(), $item);

        self::assertSame(2, $cart['itemCount']);
    }

    public function testAddCartItemResponseShape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => self::PRODUCT_SLUG,
            'quantity' => 2,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $data = $this->decode($client);
        $this->assertShape(['item' => 'array', 'cart' => 'array'], $data);

        $item = $data['item'];
        self::assertIsArray($item);
        $this->assertShape($this->cartItemShape(), $item);

        $product = $item['product'];
        self::assertIsArray($product);
        $this->assertShape($this->productShape(), $product);

        $cart = $data['cart'];
        self::assertIsArray($cart);
        $this->assertShape($this->cartShape(), $cart);
    }

    public function testUpdateCartItemResponseShape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => self::PRODUCT_SLUG,
            'quantity' => 2,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $added = $this->decode($client);
        $addedItem = $added['item'];
        self::assertIsArray($addedItem);
        $itemId = $addedItem['id'];
        self::assertIsString($itemId);

        $client->jsonRequest('PATCH', '/api/v1/storefront/cart/items/'.$itemId, ['quantity' => 5]);
        self::assertResponseIsSuccessful();

        $data = $this->decode($client);
        $this->assertShape(['item' => 'array', 'cart' => 'array'], $data);

        $item = $data['item'];
        self::assertIsArray($item);
        $this->assertShape($this->cartItemShape(), $item);

        $cart = $data['cart'];
        self::assertIsArray($cart);
        $this->assertShape($this->cartShape(), $cart);
    }

    public function testRemoveCartItemResponseShape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => self::PRODUCT_SLUG,
            'quantity' => 2,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $added = $this->decode($client);
        $addedItem = $added['item'];
        self::assertIsArray($addedItem);
        $itemId = $addedItem['id'];
        self::assertIsString($itemId);

        $client->request('DELETE', '/api/v1/storefront/cart/items/'.$itemId);
        self::assertResponseIsSuccessful();

        $cart = $this->decode($client);
        $this->assertShape($this->cartShape(), $cart);
        self::assertSame(0, $cart['itemCount']);
    }

    public function testClearCartResponseShape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => self::PRODUCT_SLUG,
            'quantity' => 1,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('DELETE', '/api/v1/storefront/cart');
        self::assertResponseIsSuccessful();

        $cart = $this->decode($client);
        $this->assertShape($this->cartShape(), $cart);

        self::assertNull($cart['id']);
        self::assertSame([], $cart['items']);
        self::assertSame(0, $cart['itemCount']);
    }

    // --- Shape contract: catalog ---------------------------------------------

    public function testProductsListResponseShape(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/storefront/products');
        self::assertResponseIsSuccessful();

        $data = $this->decode($client);
        $this->assertShape(['items' => 'array', 'meta' => 'array', 'facets' => '?array'], $data);
        $this->assertShape([
            'page' => 'int',
            'size' => 'int',
            'total' => 'int',
            'totalPages' => 'int',
            'hasMore' => 'bool',
        ], $data['meta']);

        $items = $data['items'];
        self::assertIsArray($items);
        self::assertNotEmpty($items);

        foreach ($items as $item) {
            self::assertIsArray($item);
            $this->assertShape($this->productShape(), $item);
        }
    }

    public function testProductDetailResponseShape(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/storefront/products/'.self::PRODUCT_SLUG);
        self::assertResponseIsSuccessful();

        $product = $this->decode($client);
        $this->assertShape($this->productShape(), $product);

        $variants = $product['variants'];
        self::assertIsArray($variants);
        self::assertNotEmpty($variants);

        $variant = array_values($variants)[0] ?? null;
        self::assertIsArray($variant);
        $this->assertShape(['label' => 'string', 'price' => 'int'], $variant);
    }

    // --- Shape contract: quote -----------------------------------------------

    public function testCartQuoteResponseShape(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/v1/storefront/cart/quote', [
            'lines' => [
                ['slug' => self::PRODUCT_SLUG, 'quantity' => 1],
            ],
            'deliveryMethod' => 'home',
            'promoCode' => 'ZIDEN10',
        ]);
        self::assertResponseIsSuccessful();

        $quote = $this->decode($client);
        $this->assertShape([
            'count' => 'int',
            'subtotal' => 'int',
            'discount' => 'int',
            'delivery' => 'int',
            'total' => 'int',
            'currency' => 'string',
        ], $quote);
    }

    // --- Shape contract: ordering --------------------------------------------

    public function testOrderCreateResponseShape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/orders', $this->orderPayload(), [
            'HTTP_IDEMPOTENCY_KEY' => $this->rememberIdempotencyKey(),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $data = $this->decode($client);
        $this->assertShape(['orderId' => 'string', 'orderNumber' => 'string', 'status' => 'string'], $data);

        $orderId = $data['orderId'];
        self::assertIsString($orderId);
        $this->createdOrderIds[] = $orderId;
    }

    public function testOrderTrackResponseShape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/orders', $this->orderPayload(), [
            'HTTP_IDEMPOTENCY_KEY' => $this->rememberIdempotencyKey(),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $created = $this->decode($client);
        $orderId = $created['orderId'];
        self::assertIsString($orderId);
        $this->createdOrderIds[] = $orderId;

        $orderNumber = $created['orderNumber'];
        self::assertIsString($orderNumber);

        $client->request('GET', '/api/v1/storefront/orders/track/'.$orderNumber);
        self::assertResponseIsSuccessful();

        $order = $this->decode($client);
        $this->assertShape($this->orderShape(), $order);

        $lines = $order['lines'];
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);

        $line = array_values($lines)[0] ?? null;
        self::assertIsArray($line);
        $this->assertShape($this->orderLineShape(), $line);
    }

    // --- Error contract -------------------------------------------------------

    public function testAnonymousCartAccessReturns401Shape(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/storefront/cart');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $data = $this->decode($client);
        $this->assertShape(['error' => 'string'], $data);
    }

    public function testAddUnknownProductReturns404Shape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => 'produit-inexistant-'.Uuid::v4()->toRfc4122(),
            'quantity' => 1,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = $this->decode($client);
        $this->assertShape(['error' => 'string'], $data);
    }

    public function testAddEmptySlugReturns400Shape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', ['slug' => '']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = $this->decode($client);
        $this->assertShape(['error' => 'string'], $data);
    }

    // --- Helpers --------------------------------------------------------------

    /**
     * Asserts that $actual exposes exactly the $expected keys and that each value
     * matches the expected type. Prefix a type with "?" to accept null as well.
     * Supported types: string, int, float, number, bool, array, null.
     *
     * @param array<string, string>   $expected
     * @param array<array-key, mixed> $actual
     */
    private function assertShape(array $expected, array $actual): void
    {
        $expectedKeys = array_keys($expected);
        $actualKeys = array_keys($actual);
        sort($expectedKeys);
        sort($actualKeys);

        self::assertSame($expectedKeys, $actualKeys, 'JSON key set does not match the pinned contract.');

        foreach ($expected as $key => $type) {
            $value = $actual[$key];

            if (str_starts_with($type, '?')) {
                if (null === $value) {
                    continue;
                }
                $type = substr($type, 1);
            }

            switch ($type) {
                case 'string':
                    self::assertIsString($value, sprintf('Key "%s" must be a string.', $key));
                    break;
                case 'int':
                    self::assertIsInt($value, sprintf('Key "%s" must be an int.', $key));
                    break;
                case 'float':
                    self::assertIsFloat($value, sprintf('Key "%s" must be a float.', $key));
                    break;
                case 'number':
                    self::assertTrue(
                        is_int($value) || is_float($value),
                        sprintf('Key "%s" must be a JSON number.', $key),
                    );
                    break;
                case 'bool':
                    self::assertIsBool($value, sprintf('Key "%s" must be a bool.', $key));
                    break;
                case 'array':
                    self::assertIsArray($value, sprintf('Key "%s" must be an array.', $key));
                    break;
                case 'null':
                    self::assertNull($value, sprintf('Key "%s" must be null.', $key));
                    break;
                default:
                    self::fail(sprintf('Unsupported shape type "%s" for key "%s".', $type, $key));
            }
        }
    }

    /** @return array<string, string> */
    private function identityShape(): array
    {
        return [
            'id' => 'string',
            'email' => 'string',
            'roles' => 'array',
        ];
    }

    /** @return array<string, string> */
    private function cartShape(): array
    {
        return [
            'id' => '?string',
            'items' => 'array',
            'itemCount' => 'int',
        ];
    }

    /** @return array<string, string> */
    private function cartItemShape(): array
    {
        return [
            'id' => 'string',
            'slug' => 'string',
            'variant' => '?string',
            'quantity' => 'int',
            'unitPrice' => 'int',
            'lineTotal' => 'int',
            'currency' => 'string',
            'product' => 'array',
        ];
    }

    /** @return array<string, string> */
    private function productShape(): array
    {
        return [
            'id' => 'string',
            'slug' => 'string',
            'name' => 'string',
            'brand' => 'string',
            'ref' => 'string',
            'category' => 'string',
            'sub' => 'string',
            'price' => 'int',
            'oldPrice' => '?int',
            'currency' => 'string',
            'variants' => 'array',
            'headrest' => 'bool',
            'stock' => 'string',
            'material' => 'string',
            'dims' => 'string',
            'image' => 'string',
            'additionalImages' => 'array',
            'rating' => 'number',
            'reviews' => 'int',
            'isNew' => 'bool',
            'best' => 'bool',
            'description' => 'string',
        ];
    }

    /** @return array<string, string> */
    private function orderShape(): array
    {
        return [
            'id' => 'string',
            'orderId' => 'string',
            'date' => 'string',
            'status' => 'string',
            'subtotal' => 'int',
            'discount' => 'int',
            'delivery' => 'int',
            'total' => 'int',
            'currency' => 'string',
            'method' => 'string',
            'payment' => 'string',
            'promoCode' => '?string',
            'lines' => 'array',
        ];
    }

    /** @return array<string, string> */
    private function orderLineShape(): array
    {
        return [
            'slug' => 'string',
            'variant' => '?string',
            'price' => 'int',
            'qty' => 'int',
        ];
    }

    private function createCustomer(): CustomerUser
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('contract.%s@example.com', substr($id, 0, 8));

        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Contract Tester',
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $this->createdUserIds[] = $id;

        return new CustomerUser($id, $email, null, 'ROLE_USER', 'active');
    }

    private function rememberIdempotencyKey(): string
    {
        $key = Uuid::v7()->toRfc4122();
        $this->createdIdempotencyKeys[] = $key;

        return $key;
    }

    /** @return array<string, mixed> */
    private function orderPayload(): array
    {
        return [
            'lines' => [['slug' => self::PRODUCT_SLUG, 'qty' => 1]],
            'deliveryMethod' => 'home',
            'paymentMethod' => 'Carte bancaire',
            'promoCode' => 'ZIDEN10',
        ];
    }

    /** @return array<string, mixed> */
    private function decode(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}

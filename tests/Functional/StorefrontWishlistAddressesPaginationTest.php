<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * R9 — the wishlist and the customer addresses are bounded offset lists: they accept `page`,
 * `size` and `q`, answer the unified `{items, meta}` envelope, and `meta.total` counts the
 * filtered rows of the requester only.
 */
final class StorefrontWishlistAddressesPaginationTest extends WebTestCase
{
    private const string CATEGORY = 'it-wishlist';

    private KernelBrowser $client;

    private CustomerUser $customer;

    private string $brand;

    /** @var list<string> */
    private array $slugs = [];

    /** @var list<string> */
    private array $customerIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->brand = 'Atelier-'.$suffix;

        $this->insertProduct('chaise-longue', 'Chaise longue Alpha', 12_000);
        $this->insertProduct('chaise-bureau', 'Chaise de bureau Beta', 8_000);
        $this->insertProduct('table', 'Table Gamma', 20_000);
        $this->insertProduct('fauteuil', 'Fauteuil Delta', 5_000);

        $this->customer = $this->createCustomer('wishlist.pagination');
        $this->client->loginUser($this->customer);

        $this->insertWishlistItem($this->customer->getId(), $this->slugs[0], '2026-06-01 09:00:00+00');
        $this->insertWishlistItem($this->customer->getId(), $this->slugs[1], '2026-06-02 09:00:00+00');
        $this->insertWishlistItem($this->customer->getId(), $this->slugs[2], '2026-06-03 09:00:00+00');
        $this->insertWishlistItem($this->customer->getId(), $this->slugs[3], '2026-06-04 09:00:00+00');

        $this->insertAddress($this->customer->getId(), 'Atelier', 'Sousse', '2026-06-01 09:00:00+00');
        $this->insertAddress($this->customer->getId(), 'Bureau', 'Tunis', '2026-06-02 09:00:00+00');
        $this->insertAddress($this->customer->getId(), 'Maison', 'Sfax', '2026-06-03 09:00:00+00');
    }

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->customerIds) {
            $connection->executeStatement(
                'DELETE FROM storefront_wishlist_item WHERE customer_id IN (:ids)',
                ['ids' => $this->customerIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM customer_address WHERE customer_id IN (:ids)',
                ['ids' => $this->customerIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->customerIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        if ([] !== $this->slugs) {
            $connection->executeStatement(
                'DELETE FROM storefront_product_view WHERE slug IN (:slugs)',
                ['slugs' => $this->slugs],
                ['slugs' => ArrayParameterType::STRING],
            );
        }

        $this->customerIds = [];
        $this->slugs = [];

        parent::tearDown();
    }

    public function testTheWishlistDefaultsToTwentyFourPerPageAndNewestFirst(): void
    {
        $this->client->request('GET', '/api/v1/storefront/wishlist');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();

        self::assertSame(
            ['page' => 1, 'size' => 24, 'total' => 4, 'totalPages' => 1, 'hasMore' => false],
            $data['meta'],
        );
        self::assertSame(
            ['Fauteuil Delta', 'Table Gamma', 'Chaise de bureau Beta', 'Chaise longue Alpha'],
            array_column($data['items'], 'name'),
            'the default sort is the most recently added first',
        );
    }

    public function testTheWishlistQMatchesNameAndBrandAndKeepsTheFilteredTotal(): void
    {
        $this->client->request('GET', '/api/v1/storefront/wishlist?q=chaise');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(['Chaise de bureau Beta', 'Chaise longue Alpha'], array_column($data['items'], 'name'));
        self::assertSame(2, $data['meta']['total'], 'meta.total counts the filtered rows');
        self::assertSame(1, $data['meta']['totalPages']);

        $this->client->request('GET', '/api/v1/storefront/wishlist?q='.rawurlencode(strtolower($this->brand)));
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(4, $data['meta']['total'], 'q also matches the brand');
    }

    public function testTheWishlistSortWhitelistOrdersByNameAndPrice(): void
    {
        $this->client->request('GET', '/api/v1/storefront/wishlist?sort=name');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(
            ['Chaise de bureau Beta', 'Chaise longue Alpha', 'Fauteuil Delta', 'Table Gamma'],
            array_column($data['items'], 'name'),
        );

        $this->client->request('GET', '/api/v1/storefront/wishlist?sort=price_asc');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame([5_000, 8_000, 12_000, 20_000], array_column($data['items'], 'price'));

        $this->client->request('GET', '/api/v1/storefront/wishlist?sort=price_desc');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame([20_000, 12_000, 8_000, 5_000], array_column($data['items'], 'price'));

        $this->client->request('GET', '/api/v1/storefront/wishlist?sort=inconnu');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(
            ['Fauteuil Delta', 'Table Gamma', 'Chaise de bureau Beta', 'Chaise longue Alpha'],
            array_column($data['items'], 'name'),
            'an unknown sort falls back to recent',
        );
    }

    public function testTheWishlistPaginatesWithoutRepeatingRowsAndClampsTheSize(): void
    {
        $this->client->request('GET', '/api/v1/storefront/wishlist?size=2');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $first */
        $first = $this->json();
        self::assertSame(['page' => 1, 'size' => 2, 'total' => 4, 'totalPages' => 2, 'hasMore' => true], $first['meta']);

        $this->client->request('GET', '/api/v1/storefront/wishlist?size=2&page=2');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $second */
        $second = $this->json();
        self::assertSame(['page' => 2, 'size' => 2, 'total' => 4, 'totalPages' => 2, 'hasMore' => false], $second['meta']);
        self::assertSame(
            [],
            array_intersect(array_column($first['items'], 'slug'), array_column($second['items'], 'slug')),
            'pages never repeat a row',
        );

        $this->client->request('GET', '/api/v1/storefront/wishlist?page=0&size=5000');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $clamped */
        $clamped = $this->json();
        self::assertSame(1, $clamped['meta']['page']);
        self::assertSame(100, $clamped['meta']['size'], 'size is clamped instead of rejected');
    }

    public function testTheWishlistNeverShowsAnotherCustomersItems(): void
    {
        $other = $this->createCustomer('wishlist.other');
        $this->insertWishlistItem($other->getId(), $this->slugs[2], '2026-06-05 09:00:00+00');

        $this->client->request('GET', '/api/v1/storefront/wishlist');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();

        self::assertSame(4, $data['meta']['total'], 'the other customer row is out of scope');
        self::assertSame(
            [],
            array_diff(array_column($data['items'], 'slug'), $this->slugs),
            'only the requester rows are returned',
        );
    }

    public function testTheAddressesDefaultToTwentyPerPageSortedByName(): void
    {
        $this->client->request('GET', '/api/v1/storefront/customer/addresses');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();

        self::assertSame(
            ['page' => 1, 'size' => 20, 'total' => 3, 'totalPages' => 1, 'hasMore' => false],
            $data['meta'],
        );
        self::assertSame(['Atelier', 'Bureau', 'Maison'], array_column($data['items'], 'name'));
    }

    public function testTheAddressesQMatchesNameAndCityAndKeepsTheFilteredTotal(): void
    {
        $this->client->request('GET', '/api/v1/storefront/customer/addresses?q=bureau');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(['Bureau'], array_column($data['items'], 'name'));
        self::assertSame(1, $data['meta']['total']);

        $this->client->request('GET', '/api/v1/storefront/customer/addresses?q=tunis');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(['Bureau'], array_column($data['items'], 'name'), 'q matches the city too');
        self::assertSame(1, $data['meta']['total']);

        $this->client->request('GET', '/api/v1/storefront/customer/addresses?q=zzz');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame([], $data['items']);
        self::assertSame(0, $data['meta']['total']);
    }

    public function testTheAddressesPaginateAndStayScopedToTheRequester(): void
    {
        $other = $this->createCustomer('addresses.other');
        $this->insertAddress($other->getId(), 'Bureau Tunis', 'Tunis', '2026-06-04 09:00:00+00');

        $this->client->request('GET', '/api/v1/storefront/customer/addresses?size=2');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $first */
        $first = $this->json();
        self::assertSame(['page' => 1, 'size' => 2, 'total' => 3, 'totalPages' => 2, 'hasMore' => true], $first['meta']);
        self::assertSame(['Atelier', 'Bureau'], array_column($first['items'], 'name'));

        $this->client->request('GET', '/api/v1/storefront/customer/addresses?size=2&page=2');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $second */
        $second = $this->json();
        self::assertSame(['page' => 2, 'size' => 2, 'total' => 3, 'totalPages' => 2, 'hasMore' => false], $second['meta']);
        self::assertSame(['Maison'], array_column($second['items'], 'name'));
        self::assertSame(
            [],
            array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')),
        );
    }

    private function insertProduct(string $suffix, string $name, int $priceMinor): void
    {
        $slug = sprintf('%s-%s-%s', self::CATEGORY, $suffix, substr(bin2hex(random_bytes(3)), 0, 6));

        self::connection()->insert(
            'storefront_product_view',
            [
                'id' => Uuid::v4()->toRfc4122(),
                'slug' => $slug,
                'name' => $name,
                'brand' => $this->brand,
                'ref' => 'IT-'.strtoupper($suffix),
                'category_slug' => self::CATEGORY,
                'subcategory' => 'Test',
                'price_minor' => $priceMinor,
                'old_price_minor' => null,
                'currency' => 'TND',
                'stock' => 'in',
                'material' => 'Bois',
                'dims' => '10 x 20 cm',
                'image_path' => '/uploads/products/it-wishlist.jpg',
                'rating' => 4.5,
                'reviews' => 2,
                'is_new' => false,
                'is_best' => false,
                'headrest' => false,
                'description' => $name.' description',
            ],
            [
                'rating' => ParameterType::STRING,
                'reviews' => ParameterType::INTEGER,
                'price_minor' => ParameterType::INTEGER,
                'is_new' => ParameterType::BOOLEAN,
                'is_best' => ParameterType::BOOLEAN,
                'headrest' => ParameterType::BOOLEAN,
            ],
        );

        $this->slugs[] = $slug;
    }

    private function createCustomer(string $prefix): CustomerUser
    {
        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('%s.%s@example.com', $prefix, substr($id, 0, 8));

        self::connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Pagination Tester',
            'email' => $email,
            'password_hash' => password_hash('Pagination!234', PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);
        $this->customerIds[] = $id;

        return new CustomerUser($id, $email, null, 'ROLE_USER', 'active');
    }

    private function insertWishlistItem(string $customerId, string $slug, string $createdAt): void
    {
        self::connection()->insert('storefront_wishlist_item', [
            'id' => Uuid::v4()->toRfc4122(),
            'customer_id' => $customerId,
            'product_slug' => $slug,
            'created_at' => $createdAt,
        ]);
    }

    private function insertAddress(string $customerId, string $name, string $city, string $createdAt): void
    {
        self::connection()->insert('customer_address', [
            'id' => Uuid::v4()->toRfc4122(),
            'customer_id' => $customerId,
            'name' => $name,
            'phone' => '+216 20 00 00 00',
            'street' => 'Rue de test',
            'city' => $city,
            'governorate' => $city,
            'zip' => '1000',
            'created_at' => $createdAt,
        ]);
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

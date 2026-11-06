<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-17 WP6 (R12, R13, R18, R19, R21) — the VAT rate and sale price TTC never
 * reach any public backend surface, and the admin product routes stay closed to
 * anonymous / customer callers.
 */
final class StorefrontPricingNonExposureTest extends WebTestCase
{
    private const VAT_RATE_VALUE = 12.34;
    private const SALE_PRICE_TTC_VALUE = 777777;

    /** @var list<string> */
    private array $slugs = [];
    /** @var list<string> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if ([] !== $this->slugs) {
            foreach ($this->slugs as $slug) {
                $this->indexer()->forget($slug);
            }
            $connection->executeStatement(
                'DELETE FROM storefront_product_view WHERE slug IN (:v)',
                ['v' => $this->slugs],
                ['v' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->userIds) {
            $connection->executeStatement(
                'DELETE FROM storefront_wishlist_item WHERE customer_id IN (:ids)',
                ['ids' => $this->userIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM storefront_cart_item WHERE cart_id IN (SELECT id FROM storefront_cart WHERE customer_id IN (:ids))',
                ['ids' => $this->userIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM storefront_cart WHERE customer_id IN (:ids)',
                ['ids' => $this->userIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->userIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        parent::tearDown();
    }

    /** @return array{0: string, 1: string} */
    private function seedProduct(): array
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $slug = 'pricing-leak-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $token = 'LEAKTOKEN'.substr(str_replace('-', '', Uuid::v4()->toRfc4122()), 0, 8);

        $connection->insert('storefront_product_view', [
            'id' => Uuid::v7()->toRfc4122(),
            'slug' => $slug,
            'name' => 'Produit '.$token,
            'brand' => 'Sopal',
            'ref' => $token,
            'category_slug' => 'sanitaires',
            'subcategory' => 'WC',
            'price_minor' => 100000,
            'old_price_minor' => null,
            'currency' => 'TND',
            'stock' => 'in',
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image_path' => '/p-wc.jpg',
            'rating' => 0,
            'reviews' => 0,
            'is_new' => false,
            'is_best' => false,
            'headrest' => false,
            'description' => 'Produit de test non-exposition.',
            'variants' => '[]',
            'approval_status' => 'APPROVED',
            'published' => true,
            'vat_rate' => self::VAT_RATE_VALUE,
            'sale_price_ttc_minor' => self::SALE_PRICE_TTC_VALUE,
        ], [
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
            'published' => ParameterType::BOOLEAN,
            'vat_rate' => ParameterType::STRING,
            'sale_price_ttc_minor' => ParameterType::INTEGER,
        ]);

        $this->slugs[] = $slug;
        $this->indexer()->rebuildAll();

        return [$slug, $token];
    }

    private function createCustomer(): CustomerUser
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('leak.%s@example.com', substr($id, 0, 8));

        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Leak Tester',
            'email' => $email,
            'password_hash' => password_hash('Leak!234', PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $this->userIds[] = $id;

        return new CustomerUser($id, $email, null, 'ROLE_USER', 'active');
    }

    private function indexer(): StorefrontProductSearchIndexer
    {
        /** @var StorefrontProductSearchIndexer $indexer */
        $indexer = static::getContainer()->get(StorefrontProductSearchIndexer::class);

        return $indexer;
    }

    private function assertNoLeak(string $body): void
    {
        foreach (['vatRate', 'vat_rate', 'salePriceTtc', 'sale_price_ttc_minor'] as $key) {
            self::assertStringNotContainsString($key, $body);
        }
        foreach (['12.34', '777777'] as $value) {
            self::assertStringNotContainsString($value, $body);
        }
    }

    public function testProductListOmitsPricing(): void
    {
        $client = static::createClient();
        [$slug, $token] = $this->seedProduct();

        $client->request('GET', '/api/v1/storefront/products', ['q' => $token]);
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $this->assertNoLeak($body);

        /** @var array{items: list<array<string, mixed>>} $data */
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $slugs = array_column($data['items'], 'slug');
        self::assertContains($slug, $slugs, 'the seeded product must actually be served');
    }

    public function testProductSearchOmitsPricing(): void
    {
        $client = static::createClient();
        [$slug, $token] = $this->seedProduct();

        $client->request('GET', '/api/v1/storefront/products/search', ['q' => $token]);
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $this->assertNoLeak($body);

        /** @var array{items: list<array<string, mixed>>} $data */
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $slugs = array_column($data['items'], 'slug');
        self::assertContains($slug, $slugs, 'the seeded product must actually be served');
    }

    public function testProductDetailOmitsPricing(): void
    {
        $client = static::createClient();
        [$slug] = $this->seedProduct();

        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $this->assertNoLeak($body);

        /** @var array<string, mixed> $data */
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($slug, $data['slug']);
    }

    public function testWishlistOmitsPricing(): void
    {
        $client = static::createClient();
        [$slug] = $this->seedProduct();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/wishlist', ['slug' => $slug]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('GET', '/api/v1/storefront/wishlist');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $this->assertNoLeak($body);

        /** @var array{items: list<array<string, mixed>>} $data */
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['items']);
        self::assertSame($slug, $data['items'][0]['slug']);
    }

    public function testCartOmitsPricing(): void
    {
        $client = static::createClient();
        [$slug] = $this->seedProduct();
        $client->loginUser($this->createCustomer());

        $client->jsonRequest('POST', '/api/v1/storefront/cart/items', [
            'slug' => $slug,
            'quantity' => 1,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->assertNoLeak((string) $client->getResponse()->getContent());

        $client->request('GET', '/api/v1/storefront/cart');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $this->assertNoLeak($body);

        /** @var array{items: list<array<string, mixed>>} $data */
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['items']);
        self::assertSame($slug, $data['items'][0]['slug']);

        // Anonymous quote surface too.
        $client->jsonRequest('POST', '/api/v1/storefront/cart/quote', [
            'lines' => [['slug' => $slug, 'quantity' => 1]],
        ]);
        self::assertResponseIsSuccessful();
        $this->assertNoLeak((string) $client->getResponse()->getContent());
    }

    public function testAdminProductRoutesRejectAnonymousAndCustomerCallers(): void
    {
        $client = static::createClient();
        [$slug] = $this->seedProduct();

        // Anonymous.
        $client->request('GET', '/api/v1/admin/catalog/products');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Customer (ROLE_USER) under the admin guard.
        $client->loginUser($this->createCustomer());
        $client->request('GET', '/api/v1/admin/catalog/products');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * R11 — the three admin back-office lists accept `page`/`size`, their documented filters and
 * sorts, and answer with the unified offset envelope `{items, meta}`.
 */
final class AdminCatalogPaginationTest extends WebTestCase
{
    private KernelBrowser $client;

    private CustomerUser $admin;

    /** Random token so every assertion only sees the rows this test seeded. */
    private string $token;

    /** @var list<string> */
    private array $categorySlugs = [];

    /** @var list<string> */
    private array $brandNames = [];

    /** @var list<string> */
    private array $promoCodes = [];

    /** @var list<string> */
    private array $productSlugs = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->token = strtolower(substr(str_replace('-', '', Uuid::v4()->toRfc4122()), 0, 8));
        $this->admin = $this->createSuperAdmin();
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->productSlugs) {
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug IN (:v)', ['v' => $this->productSlugs], ['v' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->brandNames) {
            $connection->executeStatement('DELETE FROM storefront_brand WHERE name IN (:v)', ['v' => $this->brandNames], ['v' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->promoCodes) {
            $connection->executeStatement('DELETE FROM storefront_promo_code WHERE code IN (:v)', ['v' => $this->promoCodes], ['v' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->categorySlugs) {
            $connection->executeStatement('DELETE FROM storefront_category WHERE slug IN (:v)', ['v' => $this->categorySlugs], ['v' => ArrayParameterType::STRING]);
        }
        $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => $this->admin->getId()]);

        parent::tearDown();
    }

    public function testTheCategoryListFiltersOnSearchVisibilityAndParentAndClampsTheSize(): void
    {
        $parent = $this->insertCategory('cat-'.$this->token.'-parent', 'Zulu '.$this->token, 900);
        $visible = $this->insertCategory('cat-'.$this->token.'-visible', 'Yankee '.$this->token, 901);
        $hidden = $this->insertCategory('cat-'.$this->token.'-hidden', 'Xray '.$this->token, 902, hidden: true);
        $childOne = $this->insertCategory('cat-'.$this->token.'-child-one', 'Alpha '.$this->token, 903, parentSlug: $parent);
        $childTwo = $this->insertCategory('cat-'.$this->token.'-child-two', 'Bravo '.$this->token, 904, parentSlug: $parent);

        $all = $this->get('/api/v1/admin/catalog/categories?q='.$this->token);
        self::assertSame(
            ['page' => 1, 'size' => 50, 'total' => 5, 'totalPages' => 1, 'hasMore' => false],
            $all['meta'],
            'the default page is 50 and the total counts the filtered rows, hidden rows included for an admin',
        );
        self::assertSame([$parent, $visible, $hidden, $childOne, $childTwo], array_column($all['items'], 'slug'), 'sort_order is the default sort');
        self::assertSame(
            ['slug', 'label', 'parentSlug', 'subcategories', 'subLevelCount', 'hidden', 'quoteEligible', 'productCount', 'sortOrder'],
            array_keys($all['items'][0]),
            'the item shape is unchanged by pagination',
        );
        self::assertIsInt($all['items'][0]['productCount']);

        self::assertSame([$hidden], array_column($this->get('/api/v1/admin/catalog/categories?q='.$this->token.'&hidden=true')['items'], 'slug'));
        self::assertCount(4, $this->get('/api/v1/admin/catalog/categories?q='.$this->token.'&hidden=false')['items']);

        $children = $this->get('/api/v1/admin/catalog/categories?q='.$this->token.'&parent='.$parent);
        self::assertSame([$childOne, $childTwo], array_column($children['items'], 'slug'), 'parent returns the direct children only');
        self::assertSame(2, $children['meta']['total']);

        $byLabel = $this->get('/api/v1/admin/catalog/categories?q='.$this->token.'&sort=label');
        self::assertSame([$childOne, $childTwo, $hidden, $visible, $parent], array_column($byLabel['items'], 'slug'));

        $unknownSort = $this->get('/api/v1/admin/catalog/categories?q='.$this->token.'&sort=not-a-sort');
        self::assertSame([$parent, $visible, $hidden, $childOne, $childTwo], array_column($unknownSort['items'], 'slug'), 'an unknown sort falls back to sort_order');

        $clamped = $this->get('/api/v1/admin/catalog/categories?q='.$this->token.'&page=0&size=0');
        self::assertSame(1, $clamped['meta']['page']);
        self::assertSame(1, $clamped['meta']['size']);
        self::assertCount(1, $clamped['items']);

        $capped = $this->get('/api/v1/admin/catalog/categories?q='.$this->token.'&size=5000');
        self::assertSame(200, $capped['meta']['size']);

        $none = $this->get('/api/v1/admin/catalog/categories?q=zzz'.$this->token);
        self::assertSame(0, $none['meta']['total']);
        self::assertSame([], $none['items']);
    }

    public function testTheBrandListFiltersOnSearchSortsByProductCountAndClampsTheSize(): void
    {
        $alpha = 'Alpha'.$this->token;
        $beta = 'Beta'.$this->token;
        $this->createBrand($alpha);
        $this->createBrand($beta);
        $this->seedProduct('brand-product-'.$this->token, $beta);

        $all = $this->get('/api/v1/admin/catalog/brands?q='.$this->token);
        self::assertSame(
            ['page' => 1, 'size' => 50, 'total' => 2, 'totalPages' => 1, 'hasMore' => false],
            $all['meta'],
        );
        self::assertSame([$alpha, $beta], array_column($all['items'], 'name'), 'name is the default sort');
        self::assertSame(['name' => $alpha, 'productCount' => 0], $all['items'][0]);
        self::assertSame(['name' => $beta, 'productCount' => 1], $all['items'][1]);

        $byCount = $this->get('/api/v1/admin/catalog/brands?q='.$this->token.'&sort=productCount');
        self::assertSame([$beta, $alpha], array_column($byCount['items'], 'name'));

        $clamped = $this->get('/api/v1/admin/catalog/brands?q='.$this->token.'&size=0');
        self::assertSame(1, $clamped['meta']['size']);
        self::assertCount(1, $clamped['items']);

        $capped = $this->get('/api/v1/admin/catalog/brands?q='.$this->token.'&size=5000');
        self::assertSame(200, $capped['meta']['size']);

        self::assertSame(0, $this->get('/api/v1/admin/catalog/brands?q=zzz'.$this->token)['meta']['total']);
    }

    public function testThePromoCodeListFiltersOnSearchAndActiveAndClampsTheSize(): void
    {
        $active = 'ACT'.strtoupper($this->token);
        $inactive = 'INA'.strtoupper($this->token);
        $this->createPromoCode($active, true);
        $this->createPromoCode($inactive, false);

        $all = $this->get('/api/v1/admin/promotions/promo-codes?q='.$this->token);
        self::assertSame(
            ['page' => 1, 'size' => 50, 'total' => 2, 'totalPages' => 1, 'hasMore' => false],
            $all['meta'],
        );
        self::assertSame([$active, $inactive], array_column($all['items'], 'code'), 'code is the fixed order');
        self::assertSame(['code' => $active, 'discountBasisPoints' => 1_500, 'active' => true], $all['items'][0]);

        self::assertSame([$active], array_column($this->get('/api/v1/admin/promotions/promo-codes?q='.$this->token.'&active=true')['items'], 'code'));
        self::assertSame([$inactive], array_column($this->get('/api/v1/admin/promotions/promo-codes?q='.$this->token.'&active=false')['items'], 'code'));

        $clamped = $this->get('/api/v1/admin/promotions/promo-codes?q='.$this->token.'&size=0');
        self::assertSame(1, $clamped['meta']['size']);
        self::assertCount(1, $clamped['items']);

        $capped = $this->get('/api/v1/admin/promotions/promo-codes?q='.$this->token.'&size=5000');
        self::assertSame(200, $capped['meta']['size']);

        self::assertSame(0, $this->get('/api/v1/admin/promotions/promo-codes?q=zzz'.$this->token)['meta']['total']);
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function insertCategory(string $slug, string $label, int $sortOrder, bool $hidden = false, ?string $parentSlug = null): string
    {
        $this->categorySlugs[] = $slug;

        self::connection()->insert('storefront_category', [
            'slug' => $slug,
            'label' => $label,
            'subcategories' => '[]',
            'quote_eligible' => false,
            'hidden' => $hidden,
            'sort_order' => $sortOrder,
            'parent_slug' => $parentSlug,
        ], [
            'quote_eligible' => ParameterType::BOOLEAN,
            'hidden' => ParameterType::BOOLEAN,
            'sort_order' => ParameterType::INTEGER,
        ]);

        return $slug;
    }

    private function createBrand(string $name): void
    {
        $this->brandNames[] = $name;
        $this->client->jsonRequest('POST', '/api/v1/admin/catalog/brands', ['name' => $name]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function createPromoCode(string $code, bool $active): void
    {
        $this->promoCodes[] = $code;
        $this->client->jsonRequest('POST', '/api/v1/admin/promotions/promo-codes', [
            'code' => $code,
            'discountBasisPoints' => 1_500,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        if (false === $active) {
            $this->client->jsonRequest('PUT', '/api/v1/admin/promotions/promo-codes/'.$code, ['active' => false]);
            self::assertResponseIsSuccessful();
        }
    }

    private function seedProduct(string $slug, string $brand): void
    {
        $this->productSlugs[] = $slug;

        self::connection()->insert('storefront_product_view', [
            'id' => Uuid::v4()->toRfc4122(),
            'slug' => $slug,
            'name' => 'Produit '.$slug,
            'brand' => $brand,
            'ref' => 'TST-'.$this->token,
            'category_slug' => 'sanitaires',
            'subcategory' => 'WC',
            'price_minor' => 10_000,
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
            'description' => 'Seed for the admin brand list.',
            'variants' => '[]',
        ], [
            'price_minor' => ParameterType::INTEGER,
            'reviews' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
        ]);
    }

    private function createSuperAdmin(): CustomerUser
    {
        $id = Uuid::v7()->toRfc4122();
        $email = sprintf('catalog.pagination.%s@example.com', substr($id, 0, 8));

        self::connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Catalog Pagination Admin',
            'email' => $email,
            'password_hash' => null,
            'role' => 'ROLE_SUPER_ADMIN',
            'status' => 'active',
        ]);

        return new CustomerUser($id, $email, null, 'ROLE_SUPER_ADMIN', 'active');
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }
}

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
 * R4 — the admin brand list carries each brand's image: the stored `/uploads/brands/…`
 * reference when one is attached, `null` otherwise, alongside the unchanged
 * `name` / `productCount` pair and without disturbing the list's filtering, sorting
 * or pagination.
 *
 * The image *write* endpoints belong to another work package, so the fixture sets
 * `image_path` directly through SQL.
 */
final class AdminBrandImageListTest extends WebTestCase
{
    private KernelBrowser $client;

    private CustomerUser $admin;

    /** Random token so every assertion only sees the rows this test seeded. */
    private string $token;

    /** @var list<string> */
    private array $brandNames = [];

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
        $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => $this->admin->getId()]);

        parent::tearDown();
    }

    /**
     * The read side of the brand image: the reference stored on the row is returned as
     * `image`, an imageless brand reads back as `image: null`, and the envelope, the
     * free-text filter, the sorts and the size clamping behave exactly as before the
     * field existed.
     */
    public function testTheBrandListReturnsTheStoredImageAndNullForImagelessBrands(): void
    {
        $imaged = 'Imaged'.$this->token;
        $bare = 'Bare'.$this->token;
        $image = '/uploads/brands/'.Uuid::v4()->toRfc4122().'.jpg';
        $this->insertBrand($imaged, $image);
        $this->insertBrand($bare, null);
        $this->seedProduct('brand-image-'.$this->token, $imaged);

        $page = $this->get('/api/v1/admin/catalog/brands?q='.$this->token);
        self::assertSame(
            ['page' => 1, 'size' => 50, 'total' => 2, 'totalPages' => 1, 'hasMore' => false],
            $page['meta'],
            'the pagination envelope is unchanged',
        );
        $rows = array_column($page['items'], null, 'name');
        self::assertSame(
            ['name' => $imaged, 'productCount' => 1, 'image' => $image],
            $rows[$imaged],
            'the stored path is returned as the brand image',
        );
        self::assertSame(
            ['name' => $bare, 'productCount' => 0, 'image' => null],
            $rows[$bare],
            'a brand without an image reads back as image: null',
        );

        $byCount = $this->get('/api/v1/admin/catalog/brands?q='.$this->token.'&sort=productCount');
        self::assertSame([$imaged, $bare], array_column($byCount['items'], 'name'), 'the product-count sort is unchanged by the new field');
        self::assertSame($image, array_column($byCount['items'], 'image', 'name')[$imaged], 'the image follows the row through every sort');

        $secondPage = $this->get('/api/v1/admin/catalog/brands?q='.$this->token.'&size=1&page=2');
        self::assertSame(1, $secondPage['meta']['size'], 'the size is clamped the same way');
        self::assertSame([$imaged], array_column($secondPage['items'], 'name'), 'name remains the default sort across pages');
        self::assertSame($image, array_column($secondPage['items'], 'image', 'name')[$imaged], 'the image survives pagination');
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

    private function insertBrand(string $name, ?string $imagePath): void
    {
        $this->brandNames[] = $name;

        self::connection()->insert('storefront_brand', [
            'name' => $name,
            'image_path' => $imagePath,
        ]);
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
            'description' => 'Seed for the admin brand image list.',
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
        $email = sprintf('brand.image.%s@example.com', $id);

        self::connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Brand Image Admin',
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

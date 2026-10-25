<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Super-admin catalog management: products, categories, brands, promo codes, image upload.
 */
final class AdminCatalogTest extends WebTestCase
{
    private const string ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $userIds = [];
    /** @var list<string> */
    private array $productSlugs = [];
    /** @var list<string> */
    private array $brandNames = [];
    /** @var list<string> */
    private array $promoCodes = [];
    /** @var list<string> */
    private array $categorySlugs = [];
    /** @var list<string> */
    private array $uploadedFiles = [];

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

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
        if ([] !== $this->userIds) {
            $connection->executeStatement('DELETE FROM customer_profile WHERE id IN (:v)', ['v' => $this->userIds], ['v' => ArrayParameterType::STRING]);
        }
        foreach ($this->uploadedFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function createUser(string $role): CustomerUser
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('admin.catalog.%s@example.com', substr($id, 0, 8));
        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Catalog Test '.$role,
            'email' => $email,
            'role' => $role,
            'status' => 'active',
        ]);
        $this->userIds[] = $id;

        return new CustomerUser($id, $email, null, $role, 'active');
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /** spec-run-8 R22 — approving is a Super Admin decision on the submission route. */
    private function approve(KernelBrowser $client, string $kind, string $slug): void
    {
        $collection = 'product' === $kind ? 'products' : 'categories';
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/'.$collection.'/'.$slug.'/approve');
        self::assertResponseIsSuccessful('approving the '.$kind.' '.$slug.' must succeed');
    }

    /** spec-run-8 R28/R29 — publication is a second, separate decision. */
    private function publish(KernelBrowser $client, string $slug): void
    {
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/products/'.$slug.'/publication', ['published' => true]);
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function productPayload(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => 'Produit de test',
            'brand' => 'Sopal',
            'ref' => 'TST-001',
            'category' => 'sanitaires',
            'sub' => 'WC',
            'price' => 200,
            'oldPrice' => null,
            'stock' => 'in',
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image' => '/p-wc.jpg',
            'isNew' => true,
            'best' => false,
            'headrest' => false,
            'description' => 'Produit créé par un test.',
            'variants' => [],
        ];
    }

    /**
     * spec-run-8 R1/R10/R34 — a standard admin may create, but only as a pending submission it
     * cannot approve or publish; a guest may not create at all.
     */
    public function testGuestIsRefusedAndAStandardAdminOnlySubmitsForReview(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->productPayload('guest-denied'));
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->loginUser($this->createUser('ROLE_ADMIN'));
        $slug = 'test-admin-submission-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->productSlugs[] = $slug;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->productPayload($slug));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug);
        self::assertSame('PENDING_APPROVAL', $this->json($client)['approval']['approvalStatus'], 'a creation is always a pending submission');

        // Neither the approval decision nor its metadata can be reached by a regular Admin.
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/approve');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/reject', ['reason' => 'mine']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/products/'.$slug.'/publication', ['published' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // A server-controlled status in the payload is ignored, not trusted (R10).
        $payload = $this->productPayload($slug.'-smuggled');
        $payload['approvalStatus'] = 'APPROVED';
        $payload['reviewedBy'] = 'somebody';
        $this->productSlugs[] = $slug.'-smuggled';
        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug.'-smuggled');
        self::assertSame('PENDING_APPROVAL', $this->json($client)['approval']['approvalStatus']);
        self::assertNull($this->json($client)['approval']['reviewedBy']);

        $client->jsonRequest('POST', '/api/v1/admin/promotions/promo-codes', ['code' => 'NOPE10', 'discountBasisPoints' => 1000]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testSuperAdminCanCreateReadUpdateAndDeleteAProduct(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $slug = 'test-product-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->productSlugs[] = $slug;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->productPayload($slug));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($slug, $this->json($client)['slug']);

        // spec-run-8 R10/R32 — a creation is a pending submission, so it is not public yet.
        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // spec-run-8 R22/R28 — approval and publication are two explicit decisions.
        $this->approve($client, 'product', $slug);
        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'an approved product stays a draft until published');

        $this->publish($client, $slug);
        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertSame(200, $this->json($client)['price']);

        // Same slug again is refused.
        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->productPayload($slug));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        // Update: new price, promotion price and out of stock.
        $update = $this->productPayload($slug);
        $update['price'] = 150;
        $update['oldPrice'] = 220;
        $update['stock'] = 'out';
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $update);
        self::assertResponseIsSuccessful();
        $updated = $this->json($client);
        self::assertSame(150, $updated['price']);
        self::assertSame(220, $updated['oldPrice']);
        self::assertSame('out', $updated['stock']);

        // spec-run-8 R25/R32 — editing an approved record reopens it, so it leaves the storefront.
        self::assertSame('PENDING_APPROVAL', $updated['approval']['approvalStatus']);
        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('DELETE', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAdminCannotSetNegativeStockQuantity(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $slug = 'test-stock-negative-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->productSlugs[] = $slug;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->productPayload($slug));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $update = $this->productPayload($slug);
        $update['stockQuantity'] = -1;
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $update);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAdminCanSetStockQuantity(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $slug = 'test-stock-quantity-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->productSlugs[] = $slug;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->productPayload($slug));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $update = $this->productPayload($slug);
        $update['stockQuantity'] = 7;
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $update);
        self::assertResponseIsSuccessful();

        $payload = $this->json($client);
        self::assertArrayHasKey('stockQuantity', $payload);
        self::assertSame(7, $payload['stockQuantity']);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(7, (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        ));
    }

    public function testProductValidationRejectsBadInput(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $cases = [
            'stock' => ['stock' => 'maybe'],
            'category' => ['category' => 'does-not-exist'],
            'subcategory' => ['sub' => 'Not a subcategory'],
            'price' => ['price' => -5],
            'old price' => ['price' => 100, 'oldPrice' => 90],
            'image' => ['image' => 'javascript:alert(1)'],
            'name' => ['name' => ''],
        ];

        foreach ($cases as $label => $override) {
            $slug = 'invalid-product-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
            $this->productSlugs[] = $slug;

            $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $override + $this->productPayload($slug));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'Expected a 422 for invalid '.$label);
        }
    }

    public function testVariantsDriveTheDisplayedPrice(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $slug = 'test-variants-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->productSlugs[] = $slug;

        $payload = $this->productPayload($slug);
        $payload['price'] = 9999;
        $payload['variants'] = [['label' => '50 L', 'price' => 385], ['label' => '80 L', 'price' => 470]];

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json($client);
        self::assertSame(385, $created['price']);
        self::assertCount(2, $created['variants'] ?? []);

        $payload['variants'] = [['label' => '50 L', 'price' => 385], ['label' => '50 L', 'price' => 400]];
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testBrandLifecycleAndInUseProtection(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $brand = 'TestBrand'.substr(Uuid::v4()->toRfc4122(), 0, 6);
        $renamed = $brand.'X';
        $this->brandNames[] = $brand;
        $this->brandNames[] = $renamed;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/brands', ['name' => $brand]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/brands', ['name' => strtolower($brand)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        // A product using the brand follows a rename and blocks deletion.
        $slug = 'test-brand-product-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->productSlugs[] = $slug;
        $payload = $this->productPayload($slug);
        $payload['brand'] = $brand;
        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('DELETE', '/api/v1/admin/catalog/brands/'.rawurlencode($brand));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/brands/'.rawurlencode($brand), ['name' => $renamed]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug);
        self::assertSame($renamed, $this->json($client)['brand']);

        $client->request('DELETE', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseIsSuccessful();

        $client->request('DELETE', '/api/v1/admin/catalog/brands/'.rawurlencode($renamed));
        self::assertResponseIsSuccessful();
    }

    public function testPromoCodeLifecycle(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $code = 'TST'.strtoupper(substr(str_replace('-', '', Uuid::v4()->toRfc4122()), 0, 6));
        $this->promoCodes[] = $code;

        $client->jsonRequest('POST', '/api/v1/admin/promotions/promo-codes', ['code' => strtolower($code), 'discountBasisPoints' => 1500]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($code, $this->json($client)['code']);

        $client->jsonRequest('POST', '/api/v1/admin/promotions/promo-codes', ['code' => $code, 'discountBasisPoints' => 1500]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $client->jsonRequest('POST', '/api/v1/admin/promotions/promo-codes', ['code' => $code.'B', 'discountBasisPoints' => 12.5]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // The storefront validates it while active...
        $client->request('GET', '/api/v1/storefront/promotions/validate?code='.$code);
        self::assertTrue($this->json($client)['valid']);

        // ...and rejects it once deactivated.
        $client->jsonRequest('PUT', '/api/v1/admin/promotions/promo-codes/'.$code, ['active' => false, 'discountBasisPoints' => 2000]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/storefront/promotions/validate?code='.$code);
        self::assertFalse($this->json($client)['valid']);

        $client->request('DELETE', '/api/v1/admin/promotions/promo-codes/'.$code);
        self::assertResponseIsSuccessful();
        $client->request('DELETE', '/api/v1/admin/promotions/promo-codes/'.$code);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCategoryLifecycleAndSubcategoryProtection(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $slug = 'test-cat-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->categorySlugs[] = $slug;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', [
            'slug' => $slug,
            'label' => 'Catégorie de test',
            'quoteEligible' => true,
            'subcategories' => [['label' => 'Alpha', 'children' => [['label' => 'Alpha un']]], ['label' => 'Beta']],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Duplicate labels in the tree are refused.
        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', [
            'slug' => $slug.'-dup',
            'label' => 'Doublons',
            'subcategories' => [['label' => 'Same'], ['label' => 'Same']],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // It is still a submission: the admin listing carries it with its state, the public
        // reference data does not (spec-run-8 R16/R31/R33).
        $client->request('GET', '/api/v1/admin/catalog/categories?q='.$slug);
        $listed = $this->json($client)['items'][0] ?? [];
        self::assertSame($slug, $listed['slug'] ?? null);
        self::assertSame('PENDING_APPROVAL', $listed['approvalStatus'] ?? null);

        $client->request('GET', '/api/v1/storefront/reference');
        self::assertNotContains($slug, array_column($this->json($client)['categories'] ?? [], 'slug'), 'a pending category must not reach the storefront');

        // Approving the category — and only approving it — makes it public reference data.
        $this->approve($client, 'category', $slug);
        $client->request('GET', '/api/v1/storefront/reference');
        $slugs = array_column($this->json($client)['categories'] ?? [], 'slug');
        self::assertContains($slug, $slugs);

        // A product in the category pins its subcategory.
        $productSlug = 'test-cat-product-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->productSlugs[] = $productSlug;
        $payload = $this->productPayload($productSlug);
        $payload['category'] = $slug;
        $payload['sub'] = 'Alpha un';
        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/categories/'.$slug, [
            'label' => 'Catégorie de test',
            'quoteEligible' => true,
            'subcategories' => [['label' => 'Beta']],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/categories/'.$slug, [
            'label' => 'Catégorie renommée',
            'quoteEligible' => false,
            'subcategories' => [['label' => 'Alpha', 'children' => [['label' => 'Alpha un']]], ['label' => 'Beta'], ['label' => 'Gamma']],
        ]);
        self::assertResponseIsSuccessful();

        // Not empty yet: deletion is refused while it holds a product or sub-levels...
        $client->request('DELETE', '/api/v1/admin/catalog/categories/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->request('DELETE', '/api/v1/admin/catalog/products/'.$productSlug);
        self::assertResponseIsSuccessful();
        $client->request('DELETE', '/api/v1/admin/catalog/categories/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // ...and only an empty category (no product, no sub-level) can be deleted.
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/categories/'.$slug, [
            'label' => 'Catégorie renommée',
            'quoteEligible' => false,
            'subcategories' => [],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('DELETE', '/api/v1/admin/catalog/categories/'.$slug);
        self::assertResponseIsSuccessful();
    }

    public function testImageUploadAcceptsRealImagesOnly(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $png = (string) tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($png, base64_decode(self::ONE_PIXEL_PNG, true));
        $client->request('POST', '/api/v1/admin/catalog/uploads', [], [
            'file' => new UploadedFile($png, 'tiny.png', 'image/png', null, true),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $payload = $this->json($client);
        self::assertStringStartsWith('/uploads/products/', (string) $payload['path']);
        self::assertStringEndsWith('.png', (string) $payload['path']);

        /** @var string $projectDir */
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $this->uploadedFiles[] = $projectDir.'/public'.$payload['path'];
        self::assertFileExists($projectDir.'/public'.$payload['path']);

        // The banner has its own folder: BannerImage only accepts references under /uploads/.
        $bannerPng = (string) tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($bannerPng, base64_decode(self::ONE_PIXEL_PNG, true));
        $client->request('POST', '/api/v1/admin/catalog/uploads', ['folder' => 'banner'], [
            'file' => new UploadedFile($bannerPng, 'banner.png', 'image/png', null, true),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $banner = $this->json($client);
        self::assertStringStartsWith('/uploads/banner/', (string) $banner['path']);
        $this->uploadedFiles[] = $projectDir.'/public'.$banner['path'];

        $unknownFolder = (string) tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($unknownFolder, base64_decode(self::ONE_PIXEL_PNG, true));
        $client->request('POST', '/api/v1/admin/catalog/uploads', ['folder' => '../etc'], [
            'file' => new UploadedFile($unknownFolder, 'x.png', 'image/png', null, true),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // A text file renamed to .png is rejected: the content decides, not the name.
        $fake = (string) tempnam(sys_get_temp_dir(), 'fake');
        file_put_contents($fake, '<?php echo "not an image";');
        $client->request('POST', '/api/v1/admin/catalog/uploads', [], [
            'file' => new UploadedFile($fake, 'evil.png', 'image/png', null, true),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->request('POST', '/api/v1/admin/catalog/uploads');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }
}

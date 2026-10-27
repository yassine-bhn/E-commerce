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
 * GET /api/v1/admin/catalog/products — every product, any approval / publication state.
 */
final class AdminProductListTest extends WebTestCase
{
    /** @var list<string> */
    private array $userIds = [];
    /** @var list<string> */
    private array $slugs = [];

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if ([] !== $this->slugs) {
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug IN (:v)', ['v' => $this->slugs], ['v' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->userIds) {
            $connection->executeStatement('DELETE FROM customer_profile WHERE id IN (:v)', ['v' => $this->userIds], ['v' => ArrayParameterType::STRING]);
        }

        parent::tearDown();
    }

    private function createUser(string $role): CustomerUser
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('admin.products.%s@example.com', substr($id, 0, 8));
        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Product List Test',
            'email' => $email,
            'role' => $role,
            'status' => 'active',
        ]);
        $this->userIds[] = $id;

        return new CustomerUser($id, $email, null, $role, 'active');
    }

    /** @param array<string, mixed> $overrides */
    private function insertProduct(string $slug, array $overrides = []): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('storefront_product_view', array_merge([
            'id' => Uuid::v7()->toRfc4122(),
            'slug' => $slug,
            'name' => 'Produit '.$slug,
            'brand' => 'Sopal',
            'ref' => 'REF-'.$slug,
            'category_slug' => 'sanitaires',
            'subcategory' => 'WC',
            'price_minor' => 100,
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
            'description' => 'Produit de test',
            'variants' => '[]',
            'approval_status' => 'APPROVED',
            'published' => true,
        ], $overrides), [
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
            'published' => ParameterType::BOOLEAN,
        ]);
        $this->slugs[] = $slug;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /** @return list<string> */
    private function slugsOf(KernelBrowser $client): array
    {
        return array_column($this->json($client)['items'], 'slug');
    }

    public function testTheDashboardListIsClosedToAnonymousAndOpenToBothBackOfficeRoles(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/admin/catalog/products');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->loginUser($this->createUser('ROLE_ADMIN'));
        $client->request('GET', '/api/v1/admin/catalog/products');
        self::assertResponseIsSuccessful();
        self::assertIsArray($this->json($client)['items'] ?? null);
    }

    public function testListsEveryStateWithFiltersSortAndPagination(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $p = 'lt'.substr(str_replace('-', '', Uuid::v4()->toRfc4122()), 0, 8);

        // A: public. B: pending + unpublished. C: rejected. D: approved but unpublished.
        $this->insertProduct($p.'-a', ['price_minor' => 300]);
        $this->insertProduct($p.'-b', ['approval_status' => 'PENDING_APPROVAL', 'published' => false, 'brand' => 'Geberit', 'subcategory' => 'Lavabo', 'stock' => 'order', 'price_minor' => 100]);
        $this->insertProduct($p.'-c', ['approval_status' => 'REJECTED', 'published' => false, 'stock' => 'out', 'price_minor' => 200]);
        $this->insertProduct($p.'-d', ['published' => false, 'price_minor' => 400]);

        // The public list only sees A; the admin list sees all four.
        $client->request('GET', '/api/v1/storefront/products', ['q' => $p]);
        self::assertCount(1, $this->json($client)['items']);

        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p]);
        self::assertResponseIsSuccessful();
        self::assertSame(4, $this->json($client)['meta']['total']);

        // Approval and publication filters.
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'approval' => 'pending']);
        self::assertSame([$p.'-b'], $this->slugsOf($client));
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'approval' => 'rejected']);
        self::assertSame([$p.'-c'], $this->slugsOf($client));
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'approval' => 'approved', 'sort' => 'priceAsc']);
        self::assertSame([$p.'-a', $p.'-d'], $this->slugsOf($client));
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'published' => 'true']);
        self::assertSame([$p.'-a'], $this->slugsOf($client));
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'approval' => 'nope']);
        self::assertSame(0, $this->json($client)['meta']['total']);

        // Brand, stock and sub-category filters.
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'brand' => 'Geberit']);
        self::assertSame([$p.'-b'], $this->slugsOf($client));
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'stock' => 'out']);
        self::assertSame([$p.'-c'], $this->slugsOf($client));
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'sub' => 'Lavabo']);
        self::assertSame([$p.'-b'], $this->slugsOf($client));
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'category' => 'sanitaires']);
        self::assertSame(4, $this->json($client)['meta']['total']);

        // Sort and pagination.
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'sort' => 'priceDesc']);
        self::assertSame([$p.'-d', $p.'-a', $p.'-c', $p.'-b'], $this->slugsOf($client));
        $client->request('GET', '/api/v1/admin/catalog/products', ['q' => $p, 'sort' => 'approval', 'size' => 2]);
        $page = $this->json($client);
        self::assertSame(2, $page['meta']['totalPages']);
        self::assertTrue($page['meta']['hasMore']);
        self::assertSame([$p.'-b', $p.'-c'], array_column($page['items'], 'slug'));

        // Each row carries what the dashboard needs.
        $row = $page['items'][0];
        self::assertArrayHasKey('stockQuantity', $row);
        self::assertSame('PENDING_APPROVAL', $row['approval']['approvalStatus']);
        self::assertFalse($row['approval']['published']);
    }
}

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
 * R21 — the storefront carousel reads the one site banner without credentials, as a variable-length
 * ordered array of image references in exactly the order the dashboard stored them (R20).
 */
final class StorefrontBannerTest extends WebTestCase
{
    /** @var list<string> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        $this->db()->executeStatement('DELETE FROM storefront_banner_image');

        foreach ($this->userIds as $id) {
            $this->db()->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => $id]);
        }
        $this->userIds = [];

        parent::tearDown();
    }

    public function testAGuestSeesEveryBannerImageInStoredOrderWithoutCredentials(): void
    {
        $client = static::createClient();
        $this->store(['/uploads/products/first.png', '/uploads/products/second.png', '/uploads/products/third.webp']);

        $client->request('GET', '/api/v1/storefront/banner');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(
            ['images' => ['/uploads/products/first.png', '/uploads/products/second.png', '/uploads/products/third.webp']],
            $this->json($client),
        );
    }

    public function testThePublicReadFollowsTheAdminAsTheSetGrowsAndShrinks(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('ROLE_SUPER_ADMIN'));

        $sent = [];
        foreach ([1, 3, 7] as $selected) {
            $paths = [];
            for ($index = 1; $index <= $selected; ++$index) {
                $paths[] = sprintf('/uploads/products/hero-%d.png', $index);
            }
            $sent = $paths;

            $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => $paths]);
            self::assertResponseIsSuccessful();
            self::assertSame($selected, $this->json($client)['count']);

            $client->request('GET', '/api/v1/storefront/banner');
            self::assertResponseIsSuccessful();
            self::assertSame($paths, $this->json($client)['images'], sprintf('%d images published', $selected));
        }

        $reversed = array_reverse($sent);
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => $reversed]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/storefront/banner');
        self::assertSame($reversed, $this->json($client)['images'], 'a replace-all replaces the set, never appends');

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => [array_shift($reversed)]]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/storefront/banner');
        self::assertSame(
            ['/uploads/products/hero-7.png'],
            $this->json($client)['images'],
            'shrinking from seven to one leaves exactly the one image the admin kept',
        );
    }

    public function testAPublicRequesterSeesReferencesOnlyAndNeverSlideIdentities(): void
    {
        $client = static::createClient();
        $this->store(['/uploads/products/private-a.png', '/uploads/products/private-b.png']);

        $client->request('GET', '/api/v1/storefront/banner');
        $guest = $this->json($client);
        self::assertSame(['images'], array_keys($guest), 'the public shape carries no admin fields');
        self::assertSame(['/uploads/products/private-a.png', '/uploads/products/private-b.png'], $guest['images']);

        $client->loginUser($this->user('ROLE_USER'));
        $client->request('GET', '/api/v1/storefront/banner');
        self::assertResponseIsSuccessful();
        self::assertSame($guest, $this->json($client), 'a signed-in customer sees the same public banner');
    }

    public function testABannerWithNothingLeftIsAnEmptyArrayAndStillNotAnError(): void
    {
        $client = static::createClient();
        $this->store([]);

        $client->request('GET', '/api/v1/storefront/banner');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['images' => []], $this->json($client));
    }

    public function testRowsThatDisagreeWithTheirInsertionOrderStillComeBackInSortOrderOrder(): void
    {
        $client = static::createClient();
        $this->store(['/uploads/products/lowest-order.png', '/uploads/products/highest-order.png']);

        // Rows a hand-fix could leave behind: the newest insert carries the lowest sort order, so an
        // unqualified SELECT would publish it first (R20).
        $id = (string) $this->db()->fetchOne(
            'SELECT id FROM storefront_banner_image WHERE image_path = :path',
            ['path' => '/uploads/products/highest-order.png'],
        );
        $this->db()->executeStatement(
            'UPDATE storefront_banner_image SET sort_order = -1, created_at = :now WHERE id = :id',
            ['id' => $id, 'now' => '2026-10-20 10:00:00+00'],
        );

        $client->request('GET', '/api/v1/storefront/banner');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['/uploads/products/highest-order.png', '/uploads/products/lowest-order.png'],
            $this->json($client)['images'],
        );
    }

    /** @param list<string> $paths */
    private function store(array $paths): void
    {
        $this->db()->executeStatement('DELETE FROM storefront_banner_image WHERE banner_id = 1');

        foreach ($paths as $sortOrder => $path) {
            $this->db()->insert('storefront_banner_image', [
                'id' => Uuid::v7()->toRfc4122(),
                'banner_id' => 1,
                'image_path' => $path,
                'sort_order' => $sortOrder,
            ]);
        }
    }

    private function user(string $role): CustomerUser
    {
        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('storefront.banner.%s@example.com', substr($id, 0, 8));
        $this->db()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Storefront Banner Test',
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

    private function db(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }
}

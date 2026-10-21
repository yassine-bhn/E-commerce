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
 * R18/R19/R20/R22/R24 — the admin surface of the site banner: one ordered image set, grown, shrunk and
 * reordered through commands, refused when the selection breaks a rule, and never deleting a file.
 */
final class AdminBannerTest extends WebTestCase
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

    public function testEveryAdminBannerRouteAsksGuestsForCredentialsAndCustomersForNothing(): void
    {
        $client = static::createClient();

        foreach ($this->adminRoutes() as [$method, $path]) {
            $client->request($method, $path);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, sprintf('%s %s as a guest', $method, $path));
            self::assertSame('Authentication required.', $this->json($client)['error'] ?? null);
        }

        $client->loginUser($this->createUser('ROLE_USER'));
        foreach ($this->adminRoutes() as [$method, $path]) {
            $client->jsonRequest($method, $path, $this->bodyFor($method, $path));
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, sprintf('%s %s as a plain customer', $method, $path));
        }
    }

    public function testAPlainAdminManagesTheBannerAndASuperAdminDoesToo(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_ADMIN'));

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => ['/uploads/products/by-admin.png']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['/uploads/products/by-admin.png'], array_column($this->json($client)['items'] ?? [], 'path'));

        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => ['/uploads/products/by-super-admin.png']]);
        self::assertResponseIsSuccessful();
    }

    public function testTheAdminCanStoreOneThreeAndSevenImagesAndReadsBackExactlyThatOrder(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        foreach ([1, 3, 7] as $selected) {
            $paths = [];
            for ($index = 1; $index <= $selected; ++$index) {
                $paths[] = sprintf('/uploads/products/hero-%02d.png', $index);
            }

            $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => array_reverse($paths)]);
            self::assertResponseStatusCodeSame(Response::HTTP_OK);

            $stored = $this->json($client);
            self::assertSame($selected, $stored['count'], sprintf('%d images selected', $selected));
            self::assertSame(
                array_reverse($paths),
                array_column($stored['items'], 'path'),
                'the order the admin sent is the order the admin reads back',
            );
            self::assertSame($selected, $this->storedCount());

            // The same order, and only the references, on the public read.
            $client->request('GET', '/api/v1/storefront/banner');
            self::assertResponseIsSuccessful();
            self::assertSame(array_reverse($paths), $this->json($client)['images'] ?? null);
        }
    }

    public function testSelectingNoImageOrElevenImagesIsRefusedAndPersistsNothing(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', [
            'images' => ['/uploads/products/pinned-a.png', '/uploads/products/pinned-b.png'],
        ]);
        self::assertResponseIsSuccessful();

        $tooMany = [];
        for ($index = 1; $index <= 11; ++$index) {
            $tooMany[] = sprintf('/uploads/products/over-%02d.png', $index);
        }

        $refusals = [
            'an empty set' => [],
            'eleven images' => $tooMany,
        ];

        foreach ($refusals as $reason => $images) {
            $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => $images]);

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $reason);
            self::assertSame(
                'The banner must contain between 1 and 10 images, '.count($images).' selected.',
                $this->json($client)['error'] ?? null,
                $reason,
            );
            self::assertSame(2, $this->storedCount(), $reason.' must not persist anything');
            self::assertSame(
                ['/uploads/products/pinned-a.png', '/uploads/products/pinned-b.png'],
                $this->storedPaths(),
                $reason.' must not persist anything',
            );
        }
    }

    public function testAReferenceThatIsNotADashboardUploadIsRefusedAndPersistsNothing(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => ['/uploads/products/pinned.png']]);
        self::assertResponseIsSuccessful();

        $refusals = [
            'an empty reference' => [['', '/uploads/products/other.png'], 'A banner image reference cannot be empty.'],
            'an external url' => [['https://cdn.example.com/hero.png'], 'must reference a dashboard upload, not an external URL'],
            'a protocol-relative url' => [['//cdn.example.com/hero.png'], 'not an external URL'],
            'a path outside the uploads area' => [['/etc/passwd'], 'must live under the dashboard uploads area'],
            'a path that climbs out of it' => [['/uploads/products/../../.env'], 'must live under the dashboard uploads area'],
            'something that is not an uploaded image' => [['/uploads/products/notes.txt'], 'must be one of the JPEG, PNG or WebP formats'],
            'the same image twice' => [['/uploads/products/pinned.png', '/uploads/products/pinned.png'], 'The same image cannot appear twice in one banner set'],
        ];

        foreach ($refusals as $reason => [$images, $expected]) {
            $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => $images]);

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $reason);
            self::assertStringContainsString($expected, (string) ($this->json($client)['error'] ?? ''), $reason);
            self::assertSame(['/uploads/products/pinned.png'], $this->storedPaths(), $reason.' must not persist anything');
        }
    }

    public function testTheOrderedSetCanBeReorderedAndShrunkOneSlideAtATime(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', [
            'images' => ['/uploads/products/slide-one.png', '/uploads/products/slide-two.png', '/uploads/products/slide-three.png'],
        ]);
        self::assertResponseIsSuccessful();
        $ids = array_column($this->json($client)['items'], 'id');

        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/banner/images/order', ['imageIds' => array_reverse($ids)]);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['/uploads/products/slide-three.png', '/uploads/products/slide-two.png', '/uploads/products/slide-one.png'],
            array_column($this->json($client)['items'], 'path'),
        );

        $client->request('GET', '/api/v1/storefront/banner');
        self::assertSame(
            ['/uploads/products/slide-three.png', '/uploads/products/slide-two.png', '/uploads/products/slide-one.png'],
            $this->json($client)['images'],
            'the reorder is visible on the public read too',
        );

        // A reorder that does not name every slide exactly once is refused and changes nothing.
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/banner/images/order', ['imageIds' => array_slice($ids, 0, 2)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'A banner reorder must list every image id exactly once',
            (string) ($this->json($client)['error'] ?? ''),
        );

        $client->request('DELETE', '/api/v1/admin/catalog/banner/images/'.$ids[1]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json($client)['count']);
        self::assertSame(['/uploads/products/slide-three.png', '/uploads/products/slide-one.png'], $this->storedPaths());

        $client->request('DELETE', '/api/v1/admin/catalog/banner/images/'.$ids[1]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('Banner image "'.$ids[1].'" does not exist.', $this->json($client)['error'] ?? null);

        $client->request('DELETE', '/api/v1/admin/catalog/banner/images/not-a-uuid');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testRemovingTheLastSlideKeepsTheUploadedFileAndEmptiesThePublicBanner(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $directory = $this->uploadsDirectory();
        $listing = $this->uploadedListing($directory);
        $uploaded = $listing[0] ?? null;
        $paths = ['/uploads/products/the-last-slide.png'];
        if (null !== $uploaded) {
            $paths = array_merge(['/uploads/products/'.$uploaded], $paths);
        }

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => $paths]);
        self::assertResponseIsSuccessful();

        foreach (array_column($this->json($client)['items'], 'id') as $imageId) {
            $client->request('DELETE', '/api/v1/admin/catalog/banner/images/'.$imageId);
            self::assertResponseIsSuccessful();
        }

        self::assertSame(0, $this->storedCount());
        self::assertSame(
            $listing,
            $this->uploadedListing($directory),
            'R22 — removing banner references must not delete or move a file under public/uploads/',
        );
        if (null !== $uploaded) {
            self::assertFileExists($directory.'/'.$uploaded);
        }

        // The documented answer for a banner with no images left (ADR-0008, spec Q5 default).
        $client->request('GET', '/api/v1/storefront/banner');
        self::assertResponseIsSuccessful();
        self::assertSame(['images' => []], $this->json($client));
    }

    public function testABodyThatIsNotAnArrayOfStringsIsRefusedBeforeAnyCommandRuns(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $client->request('PUT', '/api/v1/admin/catalog/banner/images', [], [], ['CONTENT_TYPE' => 'application/json'], '7');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('The request body must be a JSON object.', $this->json($client)['error'] ?? null);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => 'not-a-list']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('The "images" field must be a JSON array of dashboard upload paths.', $this->json($client)['error'] ?? null);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/banner/images', ['images' => ['/uploads/products/ok.png', 7]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('The "images" field must contain only image path strings.', $this->json($client)['error'] ?? null);

        self::assertSame(0, $this->storedCount());
    }

    /** @return list<array{0: string, 1: string}> */
    private function adminRoutes(): array
    {
        return [
            ['GET', '/api/v1/admin/catalog/banner'],
            ['PUT', '/api/v1/admin/catalog/banner/images'],
            ['PATCH', '/api/v1/admin/catalog/banner/images/order'],
            ['DELETE', '/api/v1/admin/catalog/banner/images/018eb9c0-0000-7000-8000-000000000001'],
        ];
    }

    /** @return array<string, mixed> */
    private function bodyFor(string $method, string $path): array
    {
        if ('PUT' === $method) {
            return ['images' => ['/uploads/products/denied.png']];
        }

        if ('PATCH' === $method) {
            return ['imageIds' => ['018eb9c0-0000-7000-8000-000000000001']];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function storedCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_banner_image WHERE banner_id = 1');
    }

    /** @return list<string> */
    private function storedPaths(): array
    {
        return array_column(
            $this->db()->fetchAllAssociative(
                'SELECT image_path FROM storefront_banner_image WHERE banner_id = 1 ORDER BY sort_order ASC, created_at ASC, id ASC',
            ),
            'image_path',
        );
    }

    private function uploadsDirectory(): string
    {
        return dirname(__DIR__, 2).'/public/uploads/products';
    }

    /** @return list<string> */
    private function uploadedListing(string $directory): array
    {
        $files = is_dir($directory) ? scandir($directory) : [];
        if (!is_array($files)) {
            return [];
        }

        $listing = [];
        foreach ($files as $file) {
            if ('.' !== $file && '..' !== $file) {
                $listing[] = $file;
            }
        }
        sort($listing);

        return $listing;
    }

    private function createUser(string $role): CustomerUser
    {
        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('admin.banner.%s@example.com', substr($id, 0, 8));
        $this->db()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Banner Test '.$role,
            'email' => $email,
            'role' => $role,
            'status' => 'active',
        ]);
        $this->userIds[] = $id;

        return new CustomerUser($id, $email, null, $role, 'active');
    }

    private function db(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }
}

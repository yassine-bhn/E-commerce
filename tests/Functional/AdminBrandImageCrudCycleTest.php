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
 * spec-run-19 WP5 — the end-to-end brand image CRUD cycle (R2 + R3–R6 closure).
 *
 * The whole lifecycle runs through the real endpoints only: a real PNG is uploaded with
 * `folder=brands`, the returned `/uploads/brands/…` reference is attached to the brand,
 * read back in the admin brand list, replaced by a second upload and finally removed,
 * leaving an imageless brand behind. The writes ride the same approval policy as a rename
 * (R10) and each successful one lands exactly one audit entry targeting the brand (R11).
 */
final class AdminBrandImageCrudCycleTest extends WebTestCase
{
    private const string ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $brandNames = [];
    /** @var list<string> */
    private array $userIds = [];
    /** @var list<string> */
    private array $uploadedFiles = [];
    /** @var list<string> */
    private array $tempFiles = [];

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if ([] !== $this->brandNames) {
            $this->db()->executeStatement(
                'DELETE FROM storefront_brand WHERE name IN (:v)',
                ['v' => $this->brandNames],
                ['v' => ArrayParameterType::STRING],
            );
            $this->db()->executeStatement(
                "DELETE FROM catalog_submission WHERE kind = 'brands' AND slug IN (:v)",
                ['v' => $this->brandNames],
                ['v' => ArrayParameterType::STRING],
            );
            $this->db()->executeStatement(
                "DELETE FROM catalog_audit_log WHERE target_type = 'brand' AND target_slug IN (:v)",
                ['v' => $this->brandNames],
                ['v' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->userIds) {
            $this->db()->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:v)',
                ['v' => $this->userIds],
                ['v' => ArrayParameterType::STRING],
            );
        }
        foreach ($this->uploadedFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->connection = null;
        $this->brandNames = [];
        $this->userIds = [];
        $this->uploadedFiles = [];
        $this->tempFiles = [];

        parent::tearDown();
    }

    /**
     * The full CRUD cycle closes: upload (R2) → attach (R3) → read in the list (R4) →
     * replace (R5) → remove (R6) → the list reads an imageless brand again, with the
     * approval status untouched and exactly one audit entry per successful write.
     */
    public function testTheFullImageCrudCycleRunsThroughTheRealEndpoints(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);
        $brand = $this->createBrand($client, $admin);

        // R2 — the image enters through the existing upload flow: a real PNG posted to the
        // upload endpoint with folder=brands lands under public/uploads/brands on disk.
        $first = $this->uploadBrandImage($client);

        // R3 — attach: the upload reference is persisted on the brand row and echoed back.
        $client->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $first]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['name' => $brand, 'image' => $first], $this->json($client));
        self::assertSame($first, $this->imagePath($brand), 'the reference is persisted on the brand row');

        // R4 — read: the list returns the stored reference alongside name and productCount.
        self::assertSame(
            ['name' => $brand, 'productCount' => 0, 'image' => $first],
            $this->listRow($client, $brand),
            'the attached image is read in the admin brand list',
        );

        // R5 — replace: a second upload overwrites the first, which is no longer returned.
        $second = $this->uploadBrandImage($client);
        self::assertNotSame($first, $second, 'each upload must mint a fresh path');
        $client->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $second]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['name' => $brand, 'image' => $second], $this->json($client));
        self::assertSame($second, $this->imagePath($brand), 'the stored reference equals the new one');
        self::assertNotSame($first, $this->imagePath($brand), 'the old reference is no longer returned');
        self::assertSame(
            ['name' => $brand, 'productCount' => 0, 'image' => $second],
            $this->listRow($client, $brand),
            'the list follows the replacement',
        );

        // R6 — remove: the reference is nulled and the list reads the imageless brand again.
        $client->request('DELETE', $this->imageRoute($brand));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['name' => $brand, 'image' => null], $this->json($client));
        self::assertNull($this->imagePath($brand), 'the image reads back as null');
        self::assertSame(
            ['name' => $brand, 'productCount' => 0, 'image' => null],
            $this->listRow($client, $brand),
            'the list shows image: null after the removal',
        );

        // R10/R11 — the writes rode the approval policy without moving the status, and the
        // trail holds exactly one image entry per successful write, targeting the brand.
        self::assertSame('APPROVED', $this->approvalStatus($brand), 'an image write never changes the approval status');
        self::assertSame(
            [
                ['action' => 'CATALOG_BRAND_IMAGE_SET', 'target_type' => 'brand', 'actor_id' => $admin->getId()],
                ['action' => 'CATALOG_BRAND_IMAGE_SET', 'target_type' => 'brand', 'actor_id' => $admin->getId()],
                ['action' => 'CATALOG_BRAND_IMAGE_REMOVED', 'target_type' => 'brand', 'actor_id' => $admin->getId()],
            ],
            $this->imageAudit($brand),
        );
    }

    /**
     * R10 — the policy guards the cycle exactly like a rename: an approved brand is out of
     * every regular Admin's reach (refusals persist nothing and audit nothing), while the
     * owner of a still-editable submission runs the whole attach → read → replace → remove
     * cycle through the real endpoints without ever moving PENDING_APPROVAL, every success
     * audited once under their name.
     */
    public function testTheAccessPolicyGuardsTheImageCycle(): void
    {
        $owner = $this->createUser('ROLE_ADMIN');
        $ownerClient = $this->clientFor($owner);
        $brand = $this->createBrand($ownerClient, $owner);

        // The creation was approved at once, so even its owner may not write the image.
        $ownerClient->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $this->uploadBrandImage($ownerClient)]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'an approved brand is out of its owner\'s reach');
        self::assertNull($this->imagePath($brand), 'a refused write persists nothing');

        $other = $this->clientFor($this->createUser('ROLE_ADMIN'));
        $other->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $this->uploadBrandImage($other)]);
        self::assertSame(Response::HTTP_FORBIDDEN, $other->getResponse()->getStatusCode());
        $other->request('DELETE', $this->imageRoute($brand));
        self::assertSame(Response::HTTP_FORBIDDEN, $other->getResponse()->getStatusCode(), 'removing is refused the same way');
        self::assertSame([], $this->imageAudit($brand), 'the refusals audited nothing');

        // The owner of a pending (legacy) submission may still write its image. From here on
        // the responses are read explicitly: the assertResponse* helpers always follow the
        // client created last, and $other was created since $ownerClient's requests began.
        $legacy = $this->uniqueName('CycleLegacyBrand');
        $this->brandNames[] = $legacy;
        $now = '2026-11-01 10:00:00';
        $this->db()->insert('storefront_brand', ['name' => $legacy]);
        $this->db()->insert('catalog_submission', [
            'kind' => 'brands',
            'slug' => $legacy,
            'approval_status' => 'PENDING_APPROVAL',
            'submitted_by' => $owner->getId(),
            'submitted_at' => $now,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'updated_at' => $now,
        ]);

        $attach = $this->uploadBrandImage($ownerClient);
        $ownerClient->jsonRequest('PUT', $this->imageRoute($legacy), ['image' => $attach]);
        self::assertSame(
            Response::HTTP_OK,
            $ownerClient->getResponse()->getStatusCode(),
            'the owner of a pending submission may attach an image',
        );
        self::assertSame($attach, $this->imagePath($legacy));

        self::assertSame(
            ['name' => $legacy, 'productCount' => 0, 'image' => $attach],
            $this->listRow($ownerClient, $legacy),
            'the read side shows the attached image in the admin brand list',
        );

        $replace = $this->uploadBrandImage($ownerClient);
        $ownerClient->jsonRequest('PUT', $this->imageRoute($legacy), ['image' => $replace]);
        self::assertSame(Response::HTTP_OK, $ownerClient->getResponse()->getStatusCode(), 'the owner may replace the image');
        self::assertSame($replace, $this->imagePath($legacy), 'the stored reference equals the new one');
        self::assertSame('PENDING_APPROVAL', $this->approvalStatus($legacy), 'an image write never changes the approval status');

        $ownerClient->request('DELETE', $this->imageRoute($legacy));
        self::assertSame(Response::HTTP_OK, $ownerClient->getResponse()->getStatusCode(), 'the owner may remove the image');
        self::assertNull($this->imagePath($legacy));
        self::assertSame(
            ['name' => $legacy, 'productCount' => 0, 'image' => null],
            $this->listRow($ownerClient, $legacy),
            'the list shows image: null after the removal',
        );
        self::assertSame('PENDING_APPROVAL', $this->approvalStatus($legacy), 'the cycle left the status untouched');

        self::assertSame(
            [
                ['action' => 'CATALOG_BRAND_IMAGE_SET', 'target_type' => 'brand', 'actor_id' => $owner->getId()],
                ['action' => 'CATALOG_BRAND_IMAGE_SET', 'target_type' => 'brand', 'actor_id' => $owner->getId()],
                ['action' => 'CATALOG_BRAND_IMAGE_REMOVED', 'target_type' => 'brand', 'actor_id' => $owner->getId()],
            ],
            $this->imageAudit($legacy),
            'exactly one audit entry per successful write, under the owner\'s name',
        );
    }

    private function imageRoute(string $brand): string
    {
        return '/api/v1/admin/catalog/brands/'.rawurlencode($brand).'/image';
    }

    private function createBrand(KernelBrowser $client, CustomerUser $creator): string
    {
        $brand = $this->uniqueName('CycleBrand');
        $this->brandNames[] = $brand;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/brands', ['name' => $brand]);
        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), 'the brand fixture must be created');

        return $brand;
    }

    /**
     * R2 — uploads a real PNG through the multipart upload endpoint with folder=brands,
     * checks the file really landed under public/uploads/brands and returns its public path.
     */
    private function uploadBrandImage(KernelBrowser $client): string
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($temp, base64_decode(self::ONE_PIXEL_PNG, true));
        $this->tempFiles[] = $temp;

        $client->request('POST', '/api/v1/admin/catalog/uploads', ['folder' => 'brands'], [
            'file' => new UploadedFile($temp, 'brand.png', 'image/png', null, true),
        ]);
        // Read explicitly: the assertResponse* helpers always follow the client created last,
        // which is not necessarily the one that made this request.
        self::assertSame(
            Response::HTTP_CREATED,
            $client->getResponse()->getStatusCode(),
            'the brands upload must succeed for the cycle to start',
        );

        $path = (string) $this->json($client)['path'];
        self::assertStringStartsWith('/uploads/brands/', $path);
        self::assertStringEndsWith('.png', $path);

        /** @var string $projectDir */
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $stored = $projectDir.'/public'.$path;
        $this->uploadedFiles[] = $stored;
        self::assertFileExists($stored, 'the uploaded file must exist on disk for the attach to reference it');

        return $path;
    }

    /**
     * The brand's row as the admin list serves it (the response is read off the given
     * client explicitly: the assertResponse* helpers follow the client created last).
     *
     * @return array{name: string, productCount: int, image: string|null}
     */
    private function listRow(KernelBrowser $client, string $brand): array
    {
        $client->request('GET', '/api/v1/admin/catalog/brands?q='.urlencode($brand));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), 'the brand list must answer');

        $rows = array_column($this->json($client)['items'], null, 'name');
        self::assertArrayHasKey($brand, $rows, 'the brand must appear in the admin brand list');

        /** @var array{name: string, productCount: int, image: string|null} $row */
        $row = $rows[$brand];
        self::assertSame(['name', 'productCount', 'image'], array_keys($row), 'the row shape stays name/productCount/image');

        return $row;
    }

    private function uniqueName(string $prefix): string
    {
        // UUIDv4, not v7: a UUIDv7's first hex digits are the timestamp, so two names minted in
        // the same millisecond would collide instead of differing.
        return $prefix.substr(str_replace('-', '', Uuid::v4()->toRfc4122()), 0, 10);
    }

    private function imagePath(string $brand): ?string
    {
        $path = $this->db()->fetchOne('SELECT image_path FROM storefront_brand WHERE name = :n', ['n' => $brand]);

        return is_string($path) ? $path : null;
    }

    private function approvalStatus(string $brand): string
    {
        return (string) $this->db()->fetchOne(
            'SELECT approval_status FROM storefront_brand WHERE name = :n',
            ['n' => $brand],
        );
    }

    /**
     * One browser per actor: a fresh kernel and session, so the token of the previous user can
     * never answer for the next one.
     */
    private function clientFor(CustomerUser $user): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($user);

        return $client;
    }

    private function createUser(string $role): CustomerUser
    {
        $id = Uuid::v7()->toRfc4122();
        // The whole id, not a prefix: two UUIDv7 minted in the same millisecond share their
        // first eight hex digits, and the provider resolves a session by e-mail, so a truncated
        // local part makes the second actor authenticate as the first.
        $email = sprintf('brand.cycle.%s@example.com', $id);
        $this->db()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Brand Cycle '.$role,
            'email' => $email,
            'password_hash' => null,
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

    /** Cached: the connection outlives the kernel shutdowns this test performs between actors. */
    private function db(): Connection
    {
        if (null === $this->connection) {
            /** @var Connection $connection */
            $connection = static::getContainer()->get(Connection::class);
            $this->connection = $connection;
        }

        return $this->connection;
    }

    /**
     * The image-related audit entries for a brand, oldest first.
     *
     * @return list<array{action: string, target_type: string, actor_id: string}>
     */
    private function imageAudit(string $brand): array
    {
        $rows = $this->db()->fetchAllAssociative(
            "SELECT action, target_type, actor_id FROM catalog_audit_log
             WHERE target_type = 'brand' AND target_slug = :slug AND action LIKE 'CATALOG_BRAND_IMAGE_%'
             ORDER BY created_at ASC, id ASC",
            ['slug' => $brand],
        );

        return array_map(
            static fn (array $row): array => [
                'action' => (string) $row['action'],
                'target_type' => (string) $row['target_type'],
                'actor_id' => (string) $row['actor_id'],
            ],
            $rows,
        );
    }
}

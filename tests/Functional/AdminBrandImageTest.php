<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-19 WP3 — the brand image write operations (R3, R5–R13).
 *
 * The image is one `/uploads/brands/` reference stored on the brand row: attaching, replacing
 * and removing go through the same approval policy as a rename, write nothing on refusal and
 * audit exactly one entry per success. The rename and the delete keep working with an image
 * attached (the image belongs to the brand, so it follows the rename and dies with the row).
 */
final class AdminBrandImageTest extends WebTestCase
{
    /** @var list<string> */
    private array $brandNames = [];
    /** @var list<string> */
    private array $userIds = [];

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

        $this->connection = null;
        $this->brandNames = [];
        $this->userIds = [];

        parent::tearDown();
    }

    /**
     * R3/R11 — a Super Admin attaches an upload reference to an imageless brand: the row holds
     * it, the response echoes it, the approval state is untouched and the trail records exactly
     * one image entry.
     */
    public function testAnImageAttachesToABrandWithoutOne(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);
        $brand = $this->createBrand($client, $admin);

        $image = $this->uploadReference('jpg');
        $client->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $image]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        self::assertSame(['name' => $brand, 'image' => $image], $this->json($client));
        self::assertSame($image, $this->imagePath($brand), 'the reference is persisted on the brand row');
        self::assertSame('APPROVED', $this->approvalStatus($brand), 'an image write never changes the approval status');

        self::assertSame(
            [['action' => 'CATALOG_BRAND_IMAGE_SET', 'target_type' => 'brand', 'actor_id' => $admin->getId()]],
            $this->imageAudit($brand),
            'exactly one image audit entry, targeting the brand',
        );
    }

    /**
     * R5/R11 — replacing overwrites the stored reference: the new one reads back and the trail
     * holds one entry per successful write.
     */
    public function testAReplaceOverwritesThePreviousImage(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);
        $brand = $this->createBrand($client, $admin);

        $first = $this->uploadReference('png');
        $client->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $first]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $second = $this->uploadReference('webp');
        $client->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $second]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        self::assertSame(['name' => $brand, 'image' => $second], $this->json($client));
        self::assertSame($second, $this->imagePath($brand), 'the stored reference equals the new one');
        self::assertNotSame($first, $this->imagePath($brand), 'the old reference is no longer returned');

        $sets = array_column($this->imageAudit($brand), 'action');
        self::assertSame(['CATALOG_BRAND_IMAGE_SET', 'CATALOG_BRAND_IMAGE_SET'], $sets, 'one entry per successful write');
    }

    /**
     * R6/R11 — removing nulls the reference and answers the imageless brand again; removing
     * again succeeds the same way (idempotent), each success audited once.
     */
    public function testRemoveNullsTheImageIdempotently(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);
        $brand = $this->createBrand($client, $admin);

        $client->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $this->uploadReference('jpg')]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('DELETE', $this->imageRoute($brand));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['name' => $brand, 'image' => null], $this->json($client));
        self::assertNull($this->imagePath($brand), 'the image reads back as null');
        self::assertSame('APPROVED', $this->approvalStatus($brand));

        $client->request('DELETE', $this->imageRoute($brand));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'removing when no image is set succeeds');
        self::assertSame(['name' => $brand, 'image' => null], $this->json($client));
        self::assertNull($this->imagePath($brand));

        $removals = array_column($this->imageAudit($brand), 'action');
        self::assertSame(
            ['CATALOG_BRAND_IMAGE_SET', 'CATALOG_BRAND_IMAGE_REMOVED', 'CATALOG_BRAND_IMAGE_REMOVED'],
            $removals,
            'exactly one entry per successful write and per successful removal',
        );
    }

    /**
     * R9/R11 — a missing, blank, non-string or non-brand-image payload is refused with 422 and
     * leaves the stored reference and the audit trail exactly as they were.
     */
    public function testInvalidReferencesAreRefusedWith422AndPersistNothing(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);
        $brand = $this->createBrand($client, $admin);

        $invalidPayloads = [
            'missing' => [],
            'blank' => ['image' => ''],
            'whitespace only' => ['image' => '   '],
            'not a string' => ['image' => 42],
            'wrong folder' => ['image' => '/uploads/products/'.Uuid::v4()->toRfc4122().'.jpg'],
            'missing extension' => ['image' => '/uploads/brands/'.Uuid::v4()->toRfc4122()],
            'wrong extension' => ['image' => '/uploads/brands/'.Uuid::v4()->toRfc4122().'.gif'],
            'external url' => ['image' => 'https://cdn.example.com/brands/'.Uuid::v4()->toRfc4122().'.jpg'],
            'traversal' => ['image' => '/uploads/brands/../secrets/'.Uuid::v4()->toRfc4122().'.jpg'],
        ];

        foreach ($invalidPayloads as $label => $payload) {
            $client->jsonRequest('PUT', $this->imageRoute($brand), $payload);
            self::assertResponseStatusCodeSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                sprintf('the "%s" payload must be refused', $label),
            );
        }

        self::assertNull($this->imagePath($brand), 'no refused payload persisted anything');
        self::assertSame([], $this->imageAudit($brand), 'failed attempts audit nothing');

        // The same refusals leave an existing image alone.
        $image = $this->uploadReference('jpg');
        $client->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $image]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('PUT', $this->imageRoute($brand), ['image' => '/uploads/brands/'.Uuid::v4()->toRfc4122().'.gif']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame($image, $this->imagePath($brand), 'the refused replace keeps the previous reference');
        self::assertCount(1, $this->imageAudit($brand), 'the refused replace audited nothing');
    }

    /**
     * R10 — image writes pass through the same access policy as a rename: an approved brand is
     * out of every regular Admin's reach, a refused write persists nothing, and the owner of a
     * still-editable submission may write its image without changing the approval status.
     */
    public function testTheAccessPolicyGuardsImageWrites(): void
    {
        $owner = $this->createUser('ROLE_ADMIN');
        $ownerClient = $this->clientFor($owner);

        $brand = $this->createBrand($ownerClient, $owner);

        // The creation was approved at once, so even the owner may not write its image any more.
        $ownerClient->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $this->uploadReference('jpg')]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'an approved brand is out of its owner\'s reach');
        self::assertNull($this->imagePath($brand), 'a refused write persists nothing');

        $other = $this->clientFor($this->createUser('ROLE_ADMIN'));
        $other->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $this->uploadReference('jpg')]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $other->request('DELETE', $this->imageRoute($brand));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame([], $this->imageAudit($brand), 'the refusals audited nothing');

        // The owner of a pending (legacy) submission may still attach: the policy reads the
        // approval write model, and the write never moves the status.
        $legacy = $this->uniqueName('LegacyImageBrand');
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

        $image = $this->uploadReference('jpg');
        // The response is read explicitly: the assertResponse* helpers always follow the client
        // created last, and $otherClient was created since this request's client was.
        $ownerClient->jsonRequest('PUT', $this->imageRoute($legacy), ['image' => $image]);
        self::assertSame(
            Response::HTTP_OK,
            $ownerClient->getResponse()->getStatusCode(),
            'the owner of a pending submission may attach an image',
        );
        self::assertSame($image, $this->imagePath($legacy));
        self::assertSame('PENDING_APPROVAL', $this->approvalStatus($legacy), 'the approval status is untouched');

        // A Super Admin may write any brand's image, whatever its state.
        $super = $this->clientFor($this->createUser('ROLE_SUPER_ADMIN'));
        $superImage = $this->uploadReference('png');
        $super->jsonRequest('PUT', $this->imageRoute($legacy), ['image' => $superImage]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($superImage, $this->imagePath($legacy));
        self::assertSame('PENDING_APPROVAL', $this->approvalStatus($legacy));
    }

    /**
     * R8 — an image write on an unknown brand answers the controller's existing 404 shape and
     * never creates the brand.
     */
    public function testAnUnknownBrandAnswersTheExisting404Shape(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);
        $missing = $this->uniqueName('GhostBrand');

        $client->jsonRequest('PUT', $this->imageRoute($missing), ['image' => $this->uploadReference('jpg')]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('Brand not found.', $this->json($client)['error']);

        $client->request('DELETE', $this->imageRoute($missing));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('Brand not found.', $this->json($client)['error']);

        self::assertSame(
            0,
            (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_brand WHERE lower(name) = lower(:n)', ['n' => $missing]),
            'no brand was created',
        );
        self::assertSame([], $this->imageAudit($missing), 'the failures audited nothing');
    }

    /**
     * R12 — the image belongs to the brand: a rename keeps the same reference under the new name.
     */
    public function testARenamePreservesTheImage(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);
        $brand = $this->createBrand($client, $admin);

        $image = $this->uploadReference('jpg');
        $client->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $image]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $renamed = $brand.'X';
        $this->brandNames[] = $renamed;
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/brands/'.rawurlencode($brand), ['name' => $renamed]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            $image,
            $this->db()->fetchOne('SELECT image_path FROM storefront_brand WHERE name = :n', ['n' => $renamed]),
            'the brand under the new name keeps the image it had before the rename',
        );
        self::assertSame(
            0,
            (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_brand WHERE name = :n', ['n' => $brand]),
        );
    }

    /**
     * R13 — deleting a brand that carries an image still succeeds, the reference disappears with
     * the row and the name can be used again.
     */
    public function testDeletingABrandWithAnImageSucceeds(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);
        $brand = $this->createBrand($client, $admin);

        $client->jsonRequest('PUT', $this->imageRoute($brand), ['image' => $this->uploadReference('jpg')]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('DELETE', '/api/v1/admin/catalog/brands/'.rawurlencode($brand));
        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_brand WHERE name = :n', ['n' => $brand]),
            'the image reference disappears with the brand row',
        );

        $client->jsonRequest('POST', '/api/v1/admin/catalog/brands', ['name' => $brand]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'the freed name does not block a re-creation');
        self::assertNull($this->imagePath($brand), 'the re-created brand starts imageless');
    }

    private function imageRoute(string $brand): string
    {
        return '/api/v1/admin/catalog/brands/'.rawurlencode($brand).'/image';
    }

    private function createBrand(KernelBrowser $client, CustomerUser $creator): string
    {
        $brand = $this->uniqueName('ImageBrand');
        $this->brandNames[] = $brand;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/brands', ['name' => $brand]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'the brand fixture must be created');

        return $brand;
    }

    /** A path shaped exactly like the `folder=brands` upload endpoint's answer. */
    private function uploadReference(string $extension): string
    {
        return '/uploads/brands/'.Uuid::v4()->toRfc4122().'.'.$extension;
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
        $email = sprintf('brand.image.%s@example.com', $id);
        $this->db()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Brand Image '.$role,
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

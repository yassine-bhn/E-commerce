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
 * Brands under the catalog approval workflow — the same lifecycle as a category.
 *
 * A creation is approved immediately (like a category creation) and audited; a rename is the
 * one write that changes the record's identity, so the products and the submission row move
 * with it; a delete is a Super Admin decision that removes the submission too. The generic
 * submission routes (`/submissions/brands/...`) decide a pending brand, which only a legacy
 * row pre-backfill can be — simulated here by inserting one directly.
 */
final class AdminBrandApprovalWorkflowTest extends WebTestCase
{
    /** @var list<string> */
    private array $brandNames = [];
    /** @var list<string> */
    private array $productSlugs = [];
    /** @var list<string> */
    private array $userIds = [];

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if ([] !== $this->productSlugs) {
            $this->db()->executeStatement(
                'DELETE FROM inventory_stock WHERE product_slug IN (:v)',
                ['v' => $this->productSlugs],
                ['v' => ArrayParameterType::STRING],
            );
            $this->db()->executeStatement(
                'DELETE FROM storefront_product_view WHERE slug IN (:v)',
                ['v' => $this->productSlugs],
                ['v' => ArrayParameterType::STRING],
            );
            $this->db()->executeStatement(
                'DELETE FROM catalog_submission WHERE slug IN (:v)',
                ['v' => $this->productSlugs],
                ['v' => ArrayParameterType::STRING],
            );
            $this->db()->executeStatement(
                'DELETE FROM catalog_audit_log WHERE target_slug IN (:v)',
                ['v' => $this->productSlugs],
                ['v' => ArrayParameterType::STRING],
            );
        }
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
        $this->productSlugs = [];
        $this->userIds = [];

        parent::tearDown();
    }

    /**
     * A creation is approved at once (the category rule), stored in the approval write model,
     * audited, visible to its author in the queue and offered by the storefront filters.
     */
    public function testACreationIsApprovedImmediatelyAndAudited(): void
    {
        $owner = $this->createUser('ROLE_ADMIN');
        $client = $this->clientFor($owner);

        $brand = $this->uniqueName('WfBrand');
        $this->brandNames[] = $brand;
        $client->jsonRequest('POST', '/api/v1/admin/catalog/brands', ['name' => $brand]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        self::assertSame(
            ['kind' => 'brands', 'approval_status' => 'APPROVED', 'submitted_by' => $owner->getId()],
            $this->submissionRow($brand),
            'the creation is a row of the approval write model, approved like a category creation',
        );

        self::assertSame(
            [
                ['action' => 'CATALOG_SUBMISSION_CREATED', 'target_type' => 'brand', 'actor_id' => $owner->getId(), 'reason' => null],
                ['action' => 'CATALOG_SUBMISSION_APPROVED', 'target_type' => 'brand', 'actor_id' => $owner->getId(), 'reason' => null],
            ],
            $this->audit($brand),
        );

        $client->request('GET', '/api/v1/admin/catalog/submissions/brands?status=approved&q='.urlencode($brand));
        self::assertResponseIsSuccessful();
        $items = $this->json($client)['items'];
        self::assertCount(1, $items, 'the author reads the brand in the queue');
        self::assertSame($brand, $items[0]['slug']);
        self::assertSame($brand, $items[0]['label']);
        self::assertNull($items[0]['published']);

        // R5 — a foreign submission is a missing one, on the detail route and in the queue.
        $other = $this->clientFor($this->createUser('ROLE_ADMIN'));
        $other->request('GET', '/api/v1/admin/catalog/submissions/brands/'.urlencode($brand));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $other->request('GET', '/api/v1/admin/catalog/submissions/brands?q='.urlencode($brand));
        self::assertSame([], $this->json($other)['items']);

        $client->request('GET', '/api/v1/storefront/reference');
        self::assertContains($brand, $this->json($client)['brands'], 'an approved brand is offered by the storefront filters');
    }

    /**
     * The rename is the identity change: the brand row, every product carrying the name and
     * the submission row move together, and the trail records the move under the new name.
     */
    public function testARenameFollowsTheProductsAndMovesTheSubmission(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);

        $brand = $this->uniqueName('WfBrand');
        $renamed = $brand.'X';
        $this->brandNames[] = $brand;
        $this->brandNames[] = $renamed;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/brands', ['name' => $brand]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $slug = $this->createProductWithBrand($client, $brand);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/brands/'.rawurlencode($brand), ['name' => $renamed]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug);
        self::assertSame($renamed, $this->json($client)['brand'], 'every product follows the rename');

        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_brand WHERE name = :n', ['n' => $renamed]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_brand WHERE name = :n', ['n' => $brand]));

        self::assertSame(
            ['kind' => 'brands', 'approval_status' => 'APPROVED', 'submitted_by' => $admin->getId()],
            $this->submissionRow($renamed),
            'the submission row moved with its brand',
        );
        self::assertFalse($this->db()->fetchOne(
            "SELECT 1 FROM catalog_submission WHERE kind = 'brands' AND slug = :slug",
            ['slug' => $brand],
        ));

        $actions = array_column($this->audit($renamed), 'action');
        self::assertContains('CATALOG_BRAND_RENAMED', $actions, 'the trail records the move under the new name');
    }

    /**
     * A product carries a brand string; when that brand was never registered, the product write
     * registers it as an approved brand so the admin Marques screen lists it, the storefront
     * filters offer it and a rename can follow the product (the CBM regression).
     */
    public function testAProductRegistersItsUnknownBrandAsApproved(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);

        $brand = $this->uniqueName('WfBrand');
        $this->brandNames[] = $brand;

        $slug = $this->createProductWithBrand($client, $brand);

        $client->request('GET', '/api/v1/admin/catalog/brands?q='.urlencode($brand));
        self::assertResponseIsSuccessful();
        self::assertSame(
            [['name' => $brand, 'productCount' => 1]],
            $this->json($client)['items'],
            'the brand carried by the product is registered and counted',
        );

        self::assertSame('APPROVED', $this->submissionRow($brand)['approval_status']);

        $client->request('GET', '/api/v1/storefront/reference');
        self::assertContains($brand, $this->json($client)['brands'], 'an approved brand is offered by the storefront filters');

        // The rename can now follow the product, exactly like a manually created brand.
        $renamed = $brand.'X';
        $this->brandNames[] = $renamed;
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/brands/'.rawurlencode($brand), ['name' => $renamed]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug);
        self::assertSame($renamed, $this->json($client)['brand'], 'every product follows the rename');
    }

    /**
     * Editing a product to a brand that was never registered registers that brand too, so a typo
     * or a newly typed brand cannot leave the product invisible to the Marques screen.
     */
    public function testUpdatingAProductRegistersItsUnknownBrand(): void
    {
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($admin);

        $firstBrand = $this->uniqueName('WfBrand');
        $secondBrand = $this->uniqueName('WfBrand');
        $this->brandNames[] = $firstBrand;
        $this->brandNames[] = $secondBrand;

        $slug = $this->createProductWithBrand($client, $firstBrand);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, [
            'slug' => $slug,
            'name' => 'Produit marque',
            'brand' => $secondBrand,
            'ref' => 'BRD-001',
            'category' => 'sanitaires',
            'sub' => '',
            'price' => 200,
            'oldPrice' => null,
            'stock' => 'in',
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image' => '/p-brand-wf.jpg',
            'isNew' => false,
            'best' => false,
            'headrest' => false,
            'description' => 'Produit créé par le test des marques.',
            'variants' => [],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame($secondBrand, $this->json($client)['brand']);

        $client->request('GET', '/api/v1/admin/catalog/brands?q='.urlencode($secondBrand));
        self::assertResponseIsSuccessful();
        self::assertSame([['name' => $secondBrand, 'productCount' => 1]], $this->json($client)['items']);
        self::assertSame('APPROVED', $this->submissionRow($secondBrand)['approval_status']);
    }

    /**
     * spec-run-8 R7/R8/R20/R34 — an approved brand is out of a regular Admin's reach (even its
     * owner's, until it is reopened), and deletion is the Super Admin's call alone.
     */
    public function testTheAccessPolicyGuardsWritesAndDeletionIsSuperAdminOnly(): void
    {
        $owner = $this->createUser('ROLE_ADMIN');
        $ownerClient = $this->clientFor($owner);

        $brand = $this->uniqueName('WfBrand');
        $this->brandNames[] = $brand;
        $ownerClient->jsonRequest('POST', '/api/v1/admin/catalog/brands', ['name' => $brand]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // The creation was approved at once, so even the owner may not rename it any more.
        $ownerClient->jsonRequest('PUT', '/api/v1/admin/catalog/brands/'.rawurlencode($brand), ['name' => $brand.'Y']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $other = $this->clientFor($this->createUser('ROLE_ADMIN'));
        $other->jsonRequest('PUT', '/api/v1/admin/catalog/brands/'.rawurlencode($brand), ['name' => $brand.'Z']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $other->request('DELETE', '/api/v1/admin/catalog/brands/'.rawurlencode($brand));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'deleting a brand is a Super Admin decision');

        $super = $this->clientFor($this->createUser('ROLE_SUPER_ADMIN'));

        // In-use protection: the refusal writes nothing and keeps the submission.
        $slug = $this->createProductWithBrand($super, $brand);
        $super->request('DELETE', '/api/v1/admin/catalog/brands/'.rawurlencode($brand));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_brand WHERE name = :n', ['n' => $brand]));

        $super->request('DELETE', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseIsSuccessful();

        $super->request('DELETE', '/api/v1/admin/catalog/brands/'.rawurlencode($brand));
        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_brand WHERE name = :n', ['n' => $brand]));
        self::assertFalse($this->db()->fetchOne(
            "SELECT 1 FROM catalog_submission WHERE kind = 'brands' AND slug = :slug",
            ['slug' => $brand],
        ), 'slug (name) reuse must not inherit a previous approval');

        self::assertContains('CATALOG_BRAND_DELETED', array_column($this->audit($brand), 'action'));
    }

    /**
     * The generic workflow routes decide any kind: a pending brand (a legacy row pre-backfill)
     * is rejected with its reason projected onto the row and removed from the storefront
     * filters, then resubmitted and approved by its author's reviewer.
     */
    public function testTheSubmissionRoutesDecideAPendingBrand(): void
    {
        $owner = $this->createUser('ROLE_ADMIN');
        $super = $this->createUser('ROLE_SUPER_ADMIN');

        $brand = $this->uniqueName('LegacyBrand');
        $this->brandNames[] = $brand;
        $now = '2026-10-01 10:00:00';
        $this->db()->insert('storefront_brand', ['name' => $brand]);
        $this->db()->insert('catalog_submission', [
            'kind' => 'brands',
            'slug' => $brand,
            'approval_status' => 'PENDING_APPROVAL',
            'submitted_by' => $owner->getId(),
            'submitted_at' => $now,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'updated_at' => $now,
        ]);

        $ownerClient = $this->clientFor($owner);
        $ownerClient->request('GET', '/api/v1/admin/catalog/submissions/brands?status=pending_approval&q='.urlencode($brand));
        self::assertSame([$brand], array_column($this->json($ownerClient)['items'], 'slug'), 'the author sees the pending brand in the queue');

        $ownerClient->request('GET', '/api/v1/storefront/reference');
        self::assertNotContains($brand, $this->json($ownerClient)['brands'], 'a pending brand is not offered by the storefront filters');

        $superClient = $this->clientFor($super);
        $superClient->jsonRequest('POST', '/api/v1/admin/catalog/submissions/brands/'.urlencode($brand).'/reject', ['reason' => 'Nom non conforme.']);
        self::assertResponseIsSuccessful();

        $row = $this->db()->fetchAssociative(
            'SELECT approval_status, rejection_reason, reviewed_by FROM storefront_brand WHERE name = :name',
            ['name' => $brand],
        );
        self::assertIsArray($row, 'the decision is projected onto the brand row');
        self::assertSame('REJECTED', (string) $row['approval_status'], 'the decision is projected onto the brand row');
        self::assertSame('Nom non conforme.', (string) $row['rejection_reason']);
        self::assertSame($super->getId(), (string) $row['reviewed_by']);

        $ownerClient->request('GET', '/api/v1/storefront/reference');
        self::assertNotContains($brand, $this->json($ownerClient)['brands'], 'a rejected brand is not offered by the storefront filters');

        $ownerClient->jsonRequest('POST', '/api/v1/admin/catalog/submissions/brands/'.urlencode($brand).'/resubmit');
        self::assertResponseIsSuccessful();
        self::assertSame('PENDING_APPROVAL', (string) $this->db()->fetchOne(
            'SELECT approval_status FROM catalog_submission WHERE kind = :kind AND slug = :slug',
            ['kind' => 'brands', 'slug' => $brand],
        ));

        $superClient->jsonRequest('POST', '/api/v1/admin/catalog/submissions/brands/'.urlencode($brand).'/approve');
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', (string) $this->db()->fetchOne(
            'SELECT approval_status FROM storefront_brand WHERE name = :name',
            ['name' => $brand],
        ));

        $ownerClient->request('GET', '/api/v1/storefront/reference');
        self::assertContains($brand, $this->json($ownerClient)['brands'], 'the approved brand is offered again');
    }

    private function uniqueName(string $prefix): string
    {
        // UUIDv4, not v7: a UUIDv7's first hex digits are the timestamp, so two names minted in
        // the same millisecond would collide instead of differing.
        return $prefix.substr(str_replace('-', '', Uuid::v4()->toRfc4122()), 0, 10);
    }

    /** A product carrying the brand, so the rename and in-use paths have something to guard. */
    private function createProductWithBrand(KernelBrowser $client, string $brand): string
    {
        $slug = 'brand-wf-product-'.substr(Uuid::v7()->toRfc4122(), 0, 8);
        $this->productSlugs[] = $slug;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', [
            'slug' => $slug,
            'name' => 'Produit marque',
            'brand' => $brand,
            'ref' => 'BRD-001',
            'category' => 'sanitaires',
            'sub' => '',
            'price' => 200,
            'oldPrice' => null,
            'stock' => 'in',
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image' => '/p-brand-wf.jpg',
            'isNew' => false,
            'best' => false,
            'headrest' => false,
            'description' => 'Produit créé par le test des marques.',
            'variants' => [],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'the product carrying the brand must be created');

        return $slug;
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
        $email = sprintf('brand.workflow.%s@example.com', $id);
        $this->db()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Brand Workflow '.$role,
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

    /** @return list<array{action: string, target_type: string, actor_id: string, reason: string|null}> */
    private function audit(string $slug): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT action, target_type, actor_id, reason FROM catalog_audit_log WHERE target_slug = :slug ORDER BY created_at ASC, id ASC',
            ['slug' => $slug],
        );

        return array_map(
            static fn (array $row): array => [
                'action' => (string) $row['action'],
                'target_type' => (string) $row['target_type'],
                'actor_id' => (string) $row['actor_id'],
                'reason' => is_string($row['reason']) ? $row['reason'] : null,
            ],
            $rows,
        );
    }

    /** @return array{kind: string, approval_status: string, submitted_by: string} */
    private function submissionRow(string $slug): array
    {
        $row = $this->db()->fetchAssociative(
            "SELECT kind, approval_status, submitted_by FROM catalog_submission WHERE kind = 'brands' AND slug = :slug",
            ['slug' => $slug],
        );
        self::assertIsArray($row, 'the approval write model must hold a row for '.$slug);

        return [
            'kind' => (string) $row['kind'],
            'approval_status' => (string) $row['approval_status'],
            'submitted_by' => (string) $row['submitted_by'],
        ];
    }
}

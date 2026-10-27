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
 * spec-run-8 WP8 (R50–R59) — the approval workflow end to end.
 *
 * Who may submit, who may read what, who decides, what a decision stores, what a resubmission
 * clears, what a refused action leaves behind, and how little of it the storefront can see. Each
 * actor gets its own client so the session of one Admin never answers for another, and the audit
 * table is read directly because the trail is the requirement, not a side effect.
 *
 * The last six tests close the evidence gaps named in `spec-run-10/coverage.md`: the author's own
 * pending edit, the queue read across the three states at once, a rejected record edited by the
 * reviewer, a category approval that publishes nothing, an unpublish, and the audit timestamp.
 */
final class AdminCatalogApprovalWorkflowTest extends WebTestCase
{
    /** A seeded, approved category: a submission needs an existing parent to point at. */
    private const string CATEGORY = 'sanitaires';

    /** @var list<string> */
    private array $categorySlugs = [];
    /** @var list<string> */
    private array $productSlugs = [];
    /** @var list<string> */
    private array $userIds = [];

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if ([] !== $this->productSlugs) {
            $this->db()->executeStatement(
                'DELETE FROM storefront_product_view WHERE slug IN (:v)',
                ['v' => $this->productSlugs],
                ['v' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->categorySlugs) {
            $this->db()->executeStatement(
                'DELETE FROM storefront_category WHERE slug IN (:v)',
                ['v' => $this->categorySlugs],
                ['v' => ArrayParameterType::STRING],
            );
        }
        $targets = array_merge($this->productSlugs, $this->categorySlugs);
        if ([] !== $targets) {
            $this->db()->executeStatement(
                'DELETE FROM catalog_audit_log WHERE target_slug IN (:v)',
                ['v' => $targets],
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
        $this->productSlugs = [];
        $this->categorySlugs = [];
        $this->userIds = [];

        parent::tearDown();
    }

    /**
     * R1/R3/R5 — a creation is a pending submission owned by its author: the author reads it,
     * another Admin does not, and submitting it for review is the author's move.
     */
    public function testASubmissionStartsPendingAndOnlyItsAuthorReadsIt(): void
    {
        $owner = $this->createUser('ROLE_ADMIN');
        $client = $this->clientFor($owner);

        $slug = $this->uniqueSlug('wf-owned');
        $this->createProduct($client, $slug);

        $client->request('GET', '/api/v1/admin/catalog/submissions/products/'.$slug);
        self::assertResponseIsSuccessful();
        $submission = $this->json($client);
        self::assertSame('PENDING_APPROVAL', $submission['approvalStatus']);
        self::assertSame($owner->getId(), $submission['submittedBy']);
        self::assertNotNull($submission['submittedAt']);
        self::assertNull($submission['reviewedBy']);
        self::assertNull($submission['rejectionReason']);

        $client->request('GET', '/api/v1/admin/catalog/submissions/products?status=pending_approval&q='.$slug);
        self::assertSame([$slug], array_column($this->json($client)['items'], 'slug'), 'the queue is the author\'s own work');

        // R5 — missing and foreign are the same answer, so nothing leaks through the detail route.
        $otherClient = $this->clientFor($this->createUser('ROLE_ADMIN'));
        $otherClient->request('GET', '/api/v1/admin/catalog/submissions/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $otherClient->request('GET', '/api/v1/admin/catalog/submissions/products?q='.$slug);
        self::assertSame([], $this->json($otherClient)['items']);

        $client = $this->clientFor($owner);
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/submit');
        self::assertResponseIsSuccessful();
        self::assertSame('PENDING_APPROVAL', $this->json($client)['approvalStatus']);

        self::assertSame(
            [
                ['CATALOG_SUBMISSION_CREATED', 'product', $owner->getId(), null],
                ['CATALOG_SUBMISSION_SUBMITTED', 'product', $owner->getId(), null],
            ],
            $this->audit($slug),
        );
    }

    /**
     * R16/R22/R23/R43 — the reviewer sees every submission, and a decision stores the reviewer,
     * the moment and, for a rejection, the reason the author is entitled to read.
     */
    public function testTheSuperAdminDecidesAnotherAdminsSubmissionsAndTheDecisionIsStoredAndAudited(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $adminClient = $this->clientFor($admin);

        $category = $this->uniqueSlug('wf-cat');
        $product = $this->uniqueSlug('wf-prod');
        $this->createCategory($adminClient, $category);
        $this->createProduct($adminClient, $product);

        $super = $this->createUser('ROLE_SUPER_ADMIN');
        $superClient = $this->clientFor($super);

        $superClient->request('GET', '/api/v1/admin/catalog/submissions/products?status=PENDING_APPROVAL&q='.$product);
        self::assertSame([$product], array_column($this->json($superClient)['items'], 'slug'), 'the reviewer reads the whole queue');
        $superClient->request('GET', '/api/v1/admin/catalog/submissions/categories?q='.$category);
        self::assertSame([$category], array_column($this->json($superClient)['items'], 'slug'));

        $superClient->jsonRequest('POST', '/api/v1/admin/catalog/submissions/categories/'.$category.'/approve');
        self::assertResponseIsSuccessful();
        $approved = $this->json($superClient);
        self::assertSame('APPROVED', $approved['approvalStatus']);
        self::assertSame($super->getId(), $approved['reviewedBy']);
        self::assertNotNull($approved['reviewedAt']);
        self::assertNull($approved['rejectionReason']);

        $superClient->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$product.'/reject', ['reason' => 'Photo manquante']);
        self::assertResponseIsSuccessful();
        $rejected = $this->json($superClient);
        self::assertSame('REJECTED', $rejected['approvalStatus']);
        self::assertSame('Photo manquante', $rejected['rejectionReason']);
        self::assertSame($super->getId(), $rejected['reviewedBy']);

        $adminClient = $this->clientFor($admin);
        $adminClient->request('GET', '/api/v1/admin/catalog/submissions/products/'.$product);
        self::assertResponseIsSuccessful();
        self::assertSame('Photo manquante', $this->json($adminClient)['rejectionReason'], 'the author reads the reason');

        self::assertSame(
            [
                ['CATALOG_SUBMISSION_CREATED', 'category', $admin->getId(), null],
                ['CATALOG_SUBMISSION_APPROVED', 'category', $super->getId(), null],
            ],
            $this->audit($category),
        );
        self::assertSame(
            [
                ['CATALOG_SUBMISSION_CREATED', 'product', $admin->getId(), null],
                ['CATALOG_SUBMISSION_REJECTED', 'product', $super->getId(), 'Photo manquante'],
            ],
            $this->audit($product),
        );
    }

    /**
     * R8/R9/R24/R26 — a rejected submission stays rejected through an edit, returns to review
     * only on resubmission, and an approved record cannot be pushed back through the same door.
     */
    public function testARejectedSubmissionStaysRejectedUntilItsAuthorResubmitsIt(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $adminClient = $this->clientFor($admin);

        $slug = $this->uniqueSlug('wf-reject');
        $this->createProduct($adminClient, $slug);

        $super = $this->createUser('ROLE_SUPER_ADMIN');
        $superClient = $this->clientFor($super);
        $superClient->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/reject', ['reason' => 'Prix incohérent']);
        self::assertResponseIsSuccessful();

        $adminClient = $this->clientFor($admin);
        $update = $this->productPayload($slug);
        $update['price'] = 175;
        $adminClient->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $update);
        self::assertResponseIsSuccessful();
        $edited = $this->json($adminClient)['approval'];
        self::assertSame('REJECTED', $edited['approvalStatus'], 'an edit alone never re-enters the queue');
        self::assertSame('Prix incohérent', $edited['rejectionReason']);

        $adminClient->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/resubmit');
        self::assertResponseIsSuccessful();
        $resubmitted = $this->json($adminClient);
        self::assertSame('PENDING_APPROVAL', $resubmitted['approvalStatus']);
        self::assertNull($resubmitted['reviewedBy'], 'the next reviewer must not see the previous outcome');
        self::assertNull($resubmitted['reviewedAt']);
        self::assertNull($resubmitted['rejectionReason']);

        self::assertSame(
            [
                ['CATALOG_SUBMISSION_CREATED', 'product', $admin->getId(), null],
                ['CATALOG_SUBMISSION_REJECTED', 'product', $super->getId(), 'Prix incohérent'],
                ['CATALOG_PRODUCT_UPDATED', 'product', $admin->getId(), null],
                ['CATALOG_SUBMISSION_RESUBMITTED', 'product', $admin->getId(), null],
            ],
            $this->audit($slug),
        );

        $superClient = $this->clientFor($super);
        $superClient->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/approve');
        self::assertResponseIsSuccessful();

        $adminClient = $this->clientFor($admin);
        $adminClient->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/submit');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an approved record cannot be resubmitted');
        self::assertSame('APPROVED', $this->storedStatus($slug));
    }

    /**
     * R15/R17/R19/R25/R40/R42 — the reviewer corrects, reads and deletes somebody else's
     * submission, and his own save is the validation: the correction approves the record instead
     * of queueing it for the reviewer himself.
     */
    public function testTheSuperAdminCorrectsReadsAndDeletesAnotherAdminsSubmissionAndTheCorrectionApprovesIt(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $adminClient = $this->clientFor($admin);

        $slug = $this->uniqueSlug('wf-crud');
        $this->createProduct($adminClient, $slug);

        $super = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($super);

        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseIsSuccessful();
        $read = $this->json($client);
        self::assertSame('PENDING_APPROVAL', $read['approval']['approvalStatus']);
        self::assertSame($admin->getId(), $read['approval']['submittedBy']);

        $update = $this->productPayload($slug);
        $update['price'] = 140;
        $update['name'] = 'Produit corrigé';
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $update);
        self::assertResponseIsSuccessful();
        $corrected = $this->json($client);
        self::assertSame(140, $corrected['price']);
        self::assertSame('Produit corrigé', $corrected['name']);
        self::assertSame('APPROVED', $corrected['approval']['approvalStatus'], 'the reviewer\'s own save is the validation');
        self::assertSame($super->getId(), $corrected['approval']['reviewedBy']);
        self::assertNotNull($corrected['approval']['reviewedAt']);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $update);
        self::assertResponseIsSuccessful();
        $again = $this->json($client)['approval'];
        self::assertSame('APPROVED', $again['approvalStatus'], 'an approved record the reviewer rewrites stays approved');

        $client->request('DELETE', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->db()->fetchOne('SELECT 1 FROM storefront_product_view WHERE slug = :s', ['s' => $slug]));

        self::assertSame(
            [
                ['CATALOG_SUBMISSION_CREATED', 'product', $admin->getId(), null],
                ['CATALOG_PRODUCT_UPDATED', 'product', $super->getId(), null],
                ['CATALOG_PRODUCT_UPDATED', 'product', $super->getId(), null],
                ['CATALOG_PRODUCT_DELETED', 'product', $super->getId(), null],
            ],
            $this->audit($slug),
        );
    }

    /**
     * R18/R20/R57 — the reviewer manages a submitted category, and deletion still honours the
     * product dependency: refused with a 422 and nothing removed until the product is gone.
     */
    public function testTheSuperAdminManagesASubmittedCategoryAndDeletionHonoursItsProducts(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $adminClient = $this->clientFor($admin);

        $category = $this->uniqueSlug('wf-managed-cat');
        $product = $this->uniqueSlug('wf-managed-prod');
        $this->createCategory($adminClient, $category);
        $this->createProductIn($adminClient, $product, $category);

        $super = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($super);

        $client->request('GET', '/api/v1/admin/catalog/submissions/categories/'.$category);
        self::assertResponseIsSuccessful();
        self::assertSame($admin->getId(), $this->json($client)['submittedBy']);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/categories/'.$category, [
            'label' => 'Catégorie corrigée',
            'quoteEligible' => false,
            'subcategories' => [],
        ]);
        self::assertResponseIsSuccessful();
        $updated = $this->json($client);
        self::assertSame('Catégorie corrigée', $updated['label']);
        self::assertSame('APPROVED', $updated['approvalStatus'], 'the reviewer\'s own save is the validation');

        $client->request('DELETE', '/api/v1/admin/catalog/categories/'.$category);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a category holding a product cannot go');
        self::assertNotFalse($this->db()->fetchOne('SELECT 1 FROM storefront_category WHERE slug = :s', ['s' => $category]));

        $client->request('DELETE', '/api/v1/admin/catalog/products/'.$product);
        self::assertResponseIsSuccessful();
        $client->request('DELETE', '/api/v1/admin/catalog/categories/'.$category);
        self::assertResponseIsSuccessful();

        self::assertSame(
            [
                ['CATALOG_SUBMISSION_CREATED', 'category', $admin->getId(), null],
                ['CATALOG_CATEGORY_UPDATED', 'category', $super->getId(), null],
                ['CATALOG_CATEGORY_DELETED', 'category', $super->getId(), null],
            ],
            $this->audit($category),
        );
    }

    /**
     * R34/R35/R36/R52 — a regular Admin cannot decide, publish, resubmit or rewrite a submission
     * that belongs to somebody else, and nothing it attempted is persisted.
     */
    public function testARegularAdminCannotDecidePublishOrTouchAForeignSubmission(): void
    {
        $owner = $this->createUser('ROLE_ADMIN');
        $ownerClient = $this->clientFor($owner);

        $slug = $this->uniqueSlug('wf-guarded');
        $this->createProduct($ownerClient, $slug);

        $client = $this->clientFor($this->createUser('ROLE_ADMIN'));

        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/approve');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/reject', ['reason' => 'mine']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/submit');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'a foreign submission is not the intruder\'s to submit');
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/products/'.$slug.'/publication', ['published' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->productPayload($slug));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $client->request('DELETE', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertSame('PENDING_APPROVAL', $this->storedStatus($slug));
        self::assertFalse((bool) $this->db()->fetchOne('SELECT published FROM storefront_product_view WHERE slug = :s', ['s' => $slug]));
        self::assertSame(
            [['CATALOG_SUBMISSION_CREATED', 'product', $owner->getId(), null]],
            $this->audit($slug),
            'a refused action leaves no audit trace',
        );
    }

    /**
     * R29/R32/R33/R59 — no write path publishes an unapproved product, and no public payload
     * carries the approval columns.
     */
    public function testAPendingProductCannotBePublishedAndNoPublicPayloadCarriesApprovalData(): void
    {
        $adminClient = $this->clientFor($this->createUser('ROLE_ADMIN'));

        $slug = $this->uniqueSlug('wf-public');
        $this->createProduct($adminClient, $slug);

        $client = $this->clientFor($this->createUser('ROLE_SUPER_ADMIN'));

        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/products/'.$slug.'/publication', ['published' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an unapproved product cannot be published');
        self::assertFalse((bool) $this->db()->fetchOne('SELECT published FROM storefront_product_view WHERE slug = :s', ['s' => $slug]));

        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/approve');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'approved is not published');

        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/products/'.$slug.'/publication', ['published' => true]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseIsSuccessful();
        $public = $this->json($client);
        self::assertArrayNotHasKey('approval', $public);
        self::assertArrayNotHasKey('approvalStatus', $public);

        $client->request('GET', '/api/v1/storefront/reference');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $category */
        $category = $this->json($client)['categories'][0];
        self::assertSame(['slug', 'label', 'subs'], array_keys($category), 'public taxonomy carries no approval data');
    }

    /**
     * R6/R10 — the author edits their own pending submission over HTTP: the content changes, the
     * submission stays in review, the author stays the author, and deciding is still not theirs.
     */
    public function testAnAuthorEditsTheirOwnPendingSubmissionWithoutLeavingReview(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $client = $this->clientFor($admin);

        $slug = $this->uniqueSlug('wf-author-edit');
        $this->createProduct($client, $slug);

        $update = $this->productPayload($slug);
        $update['name'] = 'Produit corrigé par son auteur';
        $update['price'] = 180;
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $update);
        self::assertResponseIsSuccessful();

        $edited = $this->json($client);
        self::assertSame('Produit corrigé par son auteur', $edited['name']);
        self::assertSame(180, $edited['price']);
        self::assertSame('PENDING_APPROVAL', $edited['approval']['approvalStatus'], 'an edit is not a decision');
        self::assertSame($admin->getId(), $edited['approval']['submittedBy'], 'the edit does not transfer authorship');
        self::assertNull($edited['approval']['reviewedBy']);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/approve');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'editing your own submission does not make you its reviewer');

        self::assertSame(
            [
                ['CATALOG_SUBMISSION_CREATED', 'product', $admin->getId(), null],
                ['CATALOG_PRODUCT_UPDATED', 'product', $admin->getId(), null],
            ],
            $this->audit($slug),
        );
    }

    /**
     * R13 — one queue read answers for all three states: the reviewer never has to ask three times
     * to know where a submission stands.
     */
    public function testTheSuperAdminQueueListsEveryApprovalStateAtOnce(): void
    {
        $adminClient = $this->clientFor($this->createUser('ROLE_ADMIN'));

        $prefix = $this->uniqueSlug('wf-states');
        $pending = $prefix.'-pending';
        $approved = $prefix.'-approved';
        $rejected = $prefix.'-rejected';
        foreach ([$pending, $approved, $rejected] as $slug) {
            $this->createProduct($adminClient, $slug);
        }

        $client = $this->clientFor($this->createUser('ROLE_SUPER_ADMIN'));
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$approved.'/approve');
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$rejected.'/reject', ['reason' => 'État de test']);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/admin/catalog/submissions/products?q='.$prefix.'&size=100');
        self::assertResponseIsSuccessful();
        $page = $this->json($client);

        self::assertSame(3, $page['meta']['total'], 'the unfiltered queue returns the three states');
        $states = [];
        foreach ($page['items'] as $item) {
            $states[$item['slug']] = $item['approvalStatus'];
        }
        self::assertEquals(
            [$pending => 'PENDING_APPROVAL', $approved => 'APPROVED', $rejected => 'REJECTED'],
            $states,
        );
    }

    /**
     * R14/R25 — the reviewer rewrites a rejected submission: the correction is stored and, because
     * his own save is the validation, the record comes out approved with the rejection cleared.
     */
    public function testTheSuperAdminEditsARejectedSubmissionAndTheEditApprovesIt(): void
    {
        $adminClient = $this->clientFor($this->createUser('ROLE_ADMIN'));

        $slug = $this->uniqueSlug('wf-rejected-edit');
        $this->createProduct($adminClient, $slug);

        $super = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($super);
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/reject', ['reason' => 'Photos floues']);
        self::assertResponseIsSuccessful();

        $update = $this->productPayload($slug);
        $update['name'] = 'Produit repris par le réviseur';
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $update);
        self::assertResponseIsSuccessful();

        $edited = $this->json($client);
        self::assertSame('Produit repris par le réviseur', $edited['name']);
        self::assertSame('APPROVED', $edited['approval']['approvalStatus'], 'the reviewer\'s own save is the validation');
        self::assertNull($edited['approval']['rejectionReason'], 'an approved record carries no rejection reason');
        self::assertSame($super->getId(), $edited['approval']['reviewedBy']);

        self::assertSame(
            [
                ['CATALOG_SUBMISSION_CREATED', 'product', $edited['approval']['submittedBy'], null],
                ['CATALOG_SUBMISSION_REJECTED', 'product', $super->getId(), 'Photos floues'],
                ['CATALOG_PRODUCT_UPDATED', 'product', $super->getId(), null],
            ],
            $this->audit($slug),
        );
    }

    /**
     * R28 — approving a category publishes none of its products: the category decision writes one
     * table, and each product still needs its own approval and its own publication.
     */
    public function testApprovingACategoryPublishesNoneOfItsProducts(): void
    {
        $adminClient = $this->clientFor($this->createUser('ROLE_ADMIN'));

        $category = $this->uniqueSlug('wf-cat-approval');
        $product = $this->uniqueSlug('wf-cat-product');
        $this->createCategory($adminClient, $category);
        $this->createProductIn($adminClient, $product, $category);

        $client = $this->clientFor($this->createUser('ROLE_SUPER_ADMIN'));
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/categories/'.$category.'/approve');
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', $this->json($client)['approvalStatus']);

        self::assertFalse(
            (bool) $this->db()->fetchOne('SELECT published FROM storefront_product_view WHERE slug = :s', ['s' => $product]),
            'the category decision never touches the publication flag',
        );
        $client->request('GET', '/api/v1/storefront/products/'.$product);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'its own approval is still pending');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$product.'/approve');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/storefront/products/'.$product);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'approved is still not published');

        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/products/'.$product.'/publication', ['published' => true]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/storefront/products/'.$product);
        self::assertResponseIsSuccessful();
    }

    /**
     * R26 — a product that was public leaves the storefront when it is unpublished, and the
     * unpublish is recorded as its own action without moving the approval state.
     */
    public function testAPublishedProductLeavesTheStorefrontWhenItIsUnpublished(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $adminClient = $this->clientFor($admin);

        $slug = $this->uniqueSlug('wf-unpublish');
        $this->createProduct($adminClient, $slug);

        $super = $this->createUser('ROLE_SUPER_ADMIN');
        $client = $this->clientFor($super);
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/approve');
        self::assertResponseIsSuccessful();
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/products/'.$slug.'/publication', ['published' => true]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseIsSuccessful();

        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/products/'.$slug.'/publication', ['published' => false]);
        self::assertResponseIsSuccessful();

        self::assertFalse(
            (bool) $this->db()->fetchOne('SELECT published FROM storefront_product_view WHERE slug = :s', ['s' => $slug]),
        );
        $client->request('GET', '/api/v1/storefront/products/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'an unpublished product is private again');
        self::assertSame('APPROVED', $this->storedStatus($slug), 'unpublishing is not an approval change');

        self::assertSame(
            [
                ['CATALOG_SUBMISSION_CREATED', 'product', $admin->getId(), null],
                ['CATALOG_SUBMISSION_APPROVED', 'product', $super->getId(), null],
                ['CATALOG_PRODUCT_PUBLISHED', 'product', $super->getId(), null],
                ['CATALOG_PRODUCT_UNPUBLISHED', 'product', $super->getId(), null],
            ],
            $this->audit($slug),
        );
    }

    /**
     * R36 — every audit entry carries the moment it describes, not a placeholder: each stamp falls
     * between the request that wrote it and its own return, and no two actions share a moment.
     */
    public function testEveryAuditEntryCarriesTheMomentOfTheActionItDescribes(): void
    {
        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $adminClient = $this->clientFor($this->createUser('ROLE_ADMIN'));
        $slug = $this->uniqueSlug('wf-audit-time');
        $this->createProduct($adminClient, $slug);
        $adminClient->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/submit');
        self::assertResponseIsSuccessful();
        $submittedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $client = $this->clientFor($this->createUser('ROLE_SUPER_ADMIN'));
        $beforeDecision = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/approve');
        self::assertResponseIsSuccessful();
        $decidedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $trail = $this->auditTrail($slug);
        self::assertSame(
            ['CATALOG_SUBMISSION_CREATED', 'CATALOG_SUBMISSION_SUBMITTED', 'CATALOG_SUBMISSION_APPROVED'],
            array_column($trail, 'action'),
        );

        $created = $trail[0]['created_at'];
        $submitted = $trail[1]['created_at'];
        $approved = $trail[2]['created_at'];

        self::assertGreaterThanOrEqual($startedAt, $created, 'the creation stamp is not older than this test');
        self::assertGreaterThanOrEqual($created, $submitted, 'submitting is stamped after creating');
        self::assertGreaterThanOrEqual($submitted, $approved, 'approving is stamped after submitting');
        self::assertLessThanOrEqual($submittedAt, $submitted, 'the submission is stamped before that request returned');
        self::assertGreaterThanOrEqual($beforeDecision, $approved, 'the decision is stamped inside its own request');
        self::assertLessThanOrEqual($decidedAt, $approved, 'an audit entry is never stamped in the future');
        self::assertNotSame($created, $approved, 'each action carries its own moment, not one frozen clock read');
    }

    /**
     * R26/R45 — a decision outside the matrix is refused with a conflict, changes nothing, and
     * leaves the trail exactly as it was.
     */
    public function testARefusedDecisionWritesNeitherTheRowNorAnAuditEntry(): void
    {
        $adminClient = $this->clientFor($this->createUser('ROLE_ADMIN'));

        $slug = $this->uniqueSlug('wf-refused');
        $this->createProduct($adminClient, $slug);

        $client = $this->clientFor($this->createUser('ROLE_SUPER_ADMIN'));
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/reject', ['reason' => 'Incomplet']);
        self::assertResponseIsSuccessful();
        $before = $this->audit($slug);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/approve');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a rejected record is not pending');
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/reject', ['reason' => 'Encore']);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        self::assertSame('REJECTED', $this->storedStatus($slug));
        self::assertSame('Incomplet', $this->db()->fetchOne('SELECT rejection_reason FROM storefront_product_view WHERE slug = :s', ['s' => $slug]));
        self::assertSame($before, $this->audit($slug), 'the refused decisions write nothing');
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string|null}>
     */
    private function audit(string $slug): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT action, target_type, actor_id, reason FROM catalog_audit_log WHERE target_slug = :slug ORDER BY created_at ASC, id ASC',
            ['slug' => $slug],
        );

        return array_map(
            static fn (array $row): array => [
                (string) $row['action'],
                (string) $row['target_type'],
                (string) $row['actor_id'],
                is_string($row['reason']) ? $row['reason'] : null,
            ],
            $rows,
        );
    }

    /**
     * The same rows as {@see self::audit()}, with the moment each action was recorded, so a test can
     * assert the timestamp without widening the shape every existing expectation compares against.
     *
     * Ordered by `id` (a UUIDv7, i.e. insertion order) on purpose: ordering by `created_at` would make
     * an "the stamps grow with the actions" assertion true by construction.
     *
     * @return list<array{action: string, created_at: \DateTimeImmutable}>
     */
    private function auditTrail(string $slug): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT action, created_at FROM catalog_audit_log WHERE target_slug = :slug ORDER BY id ASC',
            ['slug' => $slug],
        );

        return array_map(
            static function (array $row): array {
                $createdAt = $row['created_at'] ?? null;
                self::assertIsString($createdAt, 'every audit entry stores the moment of its action');

                return [
                    'action' => (string) $row['action'],
                    'created_at' => new \DateTimeImmutable($createdAt),
                ];
            },
            $rows,
        );
    }

    private function storedStatus(string $slug): string
    {
        return (string) $this->db()->fetchOne(
            'SELECT approval_status FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    /** @param list<string> $subcategories */
    private function createCategory(KernelBrowser $client, string $slug, array $subcategories = []): void
    {
        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', [
            'slug' => $slug,
            'label' => 'Catégorie '.$slug,
            'quoteEligible' => false,
            'subcategories' => $subcategories,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->categorySlugs[] = $slug;
    }

    private function createProduct(KernelBrowser $client, string $slug): void
    {
        $this->createProductIn($client, $slug, self::CATEGORY);
    }

    private function createProductIn(KernelBrowser $client, string $slug, string $category): void
    {
        $payload = $this->productPayload($slug);
        $payload['category'] = $category;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->productSlugs[] = $slug;
    }

    /** @return array<string, mixed> */
    private function productPayload(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => 'Produit du workflow',
            'brand' => 'Sopal',
            'ref' => 'WF-001',
            'category' => self::CATEGORY,
            'sub' => '',
            'price' => 200,
            'oldPrice' => null,
            'stock' => 'in',
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image' => '/p-wf.jpg',
            'isNew' => false,
            'best' => false,
            'headrest' => false,
            'description' => 'Produit créé par le test du workflow.',
            'variants' => [],
        ];
    }

    private function uniqueSlug(string $prefix): string
    {
        return $prefix.'-'.substr(Uuid::v7()->toRfc4122(), 0, 8);
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
        $email = sprintf('catalog.workflow.%s@example.com', $id);
        $this->db()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Workflow Test '.$role,
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
}

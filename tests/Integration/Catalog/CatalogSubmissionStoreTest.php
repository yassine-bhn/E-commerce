<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Port\CatalogSubmissionCreator;
use App\Catalog\Application\Port\CatalogSubmissionDecider;
use App\Catalog\Application\Port\CatalogSubmissionDeleter;
use App\Catalog\Application\Port\CatalogSubmissionOwnerEditor;
use App\Catalog\Application\Port\CatalogSubmissionReviewer;
use App\Catalog\Application\Port\CatalogSubmissionSubmitter;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use App\Catalog\Domain\Repository\CatalogSubmissionRepository;
use App\Catalog\Infrastructure\Audit\DbalCatalogAuditWriter;
use App\Catalog\Infrastructure\Persistence\DbalCatalogSubmissionStore;
use App\Catalog\Infrastructure\Projection\ProjectStorefrontApproval;
use App\Shared\Infrastructure\Outbox\DoctrineOutbox;
use App\Tests\Integration\Catalog\Support\ApprovalAttempt;
use App\Tests\Integration\Catalog\Support\CatalogRow;
use App\Tests\Integration\Catalog\Support\FailingCatalogAuditPort;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * spec-run-15 WP2 / R2/R3 — one store owns `catalog_submission`, and one call is one transaction.
 *
 * A transition must persist the submission, its outbox event, its audit entry and the six view
 * columns together: a failure anywhere leaves none of them. The tests run against PostgreSQL and
 * the real adapters, because the properties under test are transaction boundaries and SQL, not
 * object collaboration.
 */
final class CatalogSubmissionStoreTest extends KernelTestCase
{
    private const string CREATED_PRODUCT = 'it-store-created-product';

    private ?Connection $connection = null;

    private ?DbalCatalogSubmissionStore $store = null;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $this->connection = $connection;

        /** @var DbalCatalogSubmissionStore $store */
        $store = static::getContainer()->get(DbalCatalogSubmissionStore::class);
        $this->store = $store;

        $this->removeRows();
        CatalogRow::product($connection, self::CREATED_PRODUCT, 'PENDING_APPROVAL');
    }

    protected function tearDown(): void
    {
        $this->removeRows();

        parent::tearDown();
    }

    public function testCreateWritesTheSubmissionOutboxAuditAndProjectedViewTogether(): void
    {
        $submission = $this->store()->create(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            'admin-creator',
        );

        self::assertSame('PENDING_APPROVAL', $submission->status()->value);
        self::assertSame('admin-creator', $submission->submittedBy());
        self::assertNotNull($submission->submittedAt());
        self::assertNull($submission->reviewedBy());
        self::assertNull($submission->rejectionReason());

        $row = $this->submissionRow();
        self::assertSame('products', $row['kind']);
        self::assertSame('PENDING_APPROVAL', $row['approval_status']);
        self::assertSame('admin-creator', $row['submitted_by']);
        self::assertEquals($submission->submittedAt(), new \DateTimeImmutable((string) $row['submitted_at']));

        $outbox = $this->outboxRows();
        self::assertCount(1, $outbox, 'one transition is exactly one outbox event');
        self::assertSame('catalog_submission', $outbox[0]['aggregate_type']);
        self::assertSame('products:'.self::CREATED_PRODUCT, $outbox[0]['aggregate_id']);
        self::assertSame('App\Catalog\Domain\Event\CatalogSubmissionCreated', $outbox[0]['event_type']);
        $payload = json_decode((string) $outbox[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('products', $payload['kind']);
        self::assertSame(self::CREATED_PRODUCT, $payload['slug']);
        self::assertSame('admin-creator', $payload['actorId']);
        self::assertSame('PENDING_APPROVAL', $payload['status']);

        $audit = $this->auditRows();
        self::assertCount(1, $audit);
        self::assertSame('CATALOG_SUBMISSION_CREATED', $audit[0]['action']);
        self::assertSame('product', $audit[0]['target_type']);
        self::assertSame(self::CREATED_PRODUCT, $audit[0]['target_slug']);
        self::assertSame('admin-creator', $audit[0]['actor_id']);

        $view = $this->viewApproval();
        self::assertSame('PENDING_APPROVAL', $view['approval_status']);
        self::assertSame('admin-creator', $view['submitted_by']);
        self::assertSame($row['submitted_at'], $view['submitted_at'], 'R3: the view shows the stored state in the same transaction');
        self::assertNull($view['reviewed_by']);
        self::assertNull($view['reviewed_at']);
        self::assertNull($view['rejection_reason']);
        self::assertFalse((bool) $view['published'], 'approval never touches publication');
    }

    public function testSubmitFromPendingWritesOneSubmittedEventAndItsAuditEntry(): void
    {
        $this->store()->create(CatalogSubmissionKind::Product, self::CREATED_PRODUCT, 'admin-owner');

        $submission = $this->store()->submit(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            'admin-owner',
            false,
        );

        self::assertSame('PENDING_APPROVAL', $submission->status()->value);

        $outbox = $this->outboxRows();
        self::assertCount(2, $outbox, 'create and submit are two transitions, so two events');
        self::assertSame('App\Catalog\Domain\Event\CatalogSubmissionSubmitted', $outbox[1]['event_type']);
        $payload = json_decode((string) $outbox[1]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('PENDING_APPROVAL', $payload['status']);
        self::assertSame('admin-owner', $payload['actorId']);

        $audit = $this->auditRows();
        self::assertCount(2, $audit);
        self::assertSame('CATALOG_SUBMISSION_SUBMITTED', $audit[1]['action']);

        self::assertSame('PENDING_APPROVAL', $this->viewApproval()['approval_status']);
    }

    public function testSubmitFromRejectedResubmitsAndClearsThePreviousReview(): void
    {
        $this->seedSubmission('REJECTED', [
            'submitted_by' => 'admin-owner',
            'reviewed_by' => 'reviewer-1',
            'reviewed_at' => '2026-10-29 09:00:00.000000+00',
            'rejection_reason' => 'Fix the description.',
        ]);

        $submission = $this->store()->submit(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            'admin-owner',
            false,
        );

        self::assertSame('PENDING_APPROVAL', $submission->status()->value);
        self::assertNull($submission->reviewedBy());
        self::assertNull($submission->reviewedAt());
        self::assertNull($submission->rejectionReason());

        $outbox = $this->outboxRows();
        self::assertCount(1, $outbox);
        self::assertSame('App\Catalog\Domain\Event\CatalogSubmissionResubmitted', $outbox[0]['event_type']);

        $audit = $this->auditRows();
        self::assertCount(1, $audit);
        self::assertSame('CATALOG_SUBMISSION_RESUBMITTED', $audit[0]['action']);

        $view = $this->viewApproval();
        self::assertSame('PENDING_APPROVAL', $view['approval_status']);
        self::assertNull($view['reviewed_by']);
        self::assertNull($view['reviewed_at']);
        self::assertNull($view['rejection_reason']);
    }

    public function testDecideApprovesAPendingSubmissionAndRecordsTheReviewer(): void
    {
        $this->store()->create(CatalogSubmissionKind::Product, self::CREATED_PRODUCT, 'admin-owner');

        $submission = $this->store()->decide(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            \App\Catalog\Domain\Model\CatalogDecision::Approve,
            'reviewer-1',
        );

        self::assertSame('APPROVED', $submission->status()->value);
        self::assertSame('reviewer-1', $submission->reviewedBy());

        $outbox = $this->outboxRows();
        self::assertCount(2, $outbox);
        self::assertSame('App\Catalog\Domain\Event\CatalogSubmissionApproved', $outbox[1]['event_type']);

        $audit = $this->auditRows();
        self::assertCount(2, $audit);
        self::assertSame('CATALOG_SUBMISSION_APPROVED', $audit[1]['action']);
        self::assertSame('reviewer-1', $audit[1]['actor_id']);

        $view = $this->viewApproval();
        self::assertSame('APPROVED', $view['approval_status']);
        self::assertSame('reviewer-1', $view['reviewed_by']);
        self::assertFalse((bool) $view['published'], 'R6: a decision never publishes');
    }

    public function testDecideRejectsWithTheReasonAndItsOwnEventAndAuditEntry(): void
    {
        $this->store()->create(CatalogSubmissionKind::Product, self::CREATED_PRODUCT, 'admin-owner');

        $submission = $this->store()->decide(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            \App\Catalog\Domain\Model\CatalogDecision::Reject,
            'reviewer-1',
            'The photos are blurry.',
        );

        self::assertSame('REJECTED', $submission->status()->value);
        self::assertSame('The photos are blurry.', $submission->rejectionReason());

        $outbox = $this->outboxRows();
        self::assertCount(2, $outbox);
        self::assertSame('App\Catalog\Domain\Event\CatalogSubmissionRejected', $outbox[1]['event_type']);
        $payload = json_decode((string) $outbox[1]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('The photos are blurry.', $payload['reason']);

        $audit = $this->auditRows();
        self::assertCount(2, $audit);
        self::assertSame('CATALOG_SUBMISSION_REJECTED', $audit[1]['action']);
        self::assertSame('The photos are blurry.', $audit[1]['reason']);

        $view = $this->viewApproval();
        self::assertSame('REJECTED', $view['approval_status']);
        self::assertSame('The photos are blurry.', $view['rejection_reason']);
    }

    public function testAReviewerSaveFromPendingApprovesAndWritesOneAuditEntry(): void
    {
        $this->store()->create(CatalogSubmissionKind::Product, self::CREATED_PRODUCT, 'admin-owner');

        $submission = $this->store()->reviewerSave(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            'super-admin',
        );

        self::assertSame('APPROVED', $submission->status()->value);
        self::assertSame('super-admin', $submission->reviewedBy());

        $outbox = $this->outboxRows();
        self::assertCount(2, $outbox);
        self::assertSame('App\Catalog\Domain\Event\CatalogSubmissionApproved', $outbox[1]['event_type']);

        $audit = $this->auditRows();
        self::assertCount(2, $audit, 'the reviewer save is an approval, so it gets its own audit entry');
        self::assertSame('CATALOG_SUBMISSION_APPROVED', $audit[1]['action']);
        self::assertSame('super-admin', $audit[1]['actor_id']);

        self::assertSame('APPROVED', $this->viewApproval()['approval_status']);
    }

    public function testAReviewerSaveFromRejectedApprovesAndWritesOneApprovalEventAndAuditEntry(): void
    {
        $this->seedSubmission('REJECTED', [
            'submitted_by' => 'admin-owner',
            'reviewed_by' => 'reviewer-1',
            'reviewed_at' => '2026-10-29 09:00:00.000000+00',
            'rejection_reason' => 'Fix the description.',
        ]);

        $submission = $this->store()->reviewerSave(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            'super-admin',
        );

        self::assertSame('APPROVED', $submission->status()->value);
        self::assertSame('super-admin', $submission->reviewedBy());
        self::assertNull($submission->rejectionReason());

        $outbox = $this->outboxRows();
        self::assertCount(1, $outbox, 'a rejected row moved to approved is exactly one transition');
        self::assertSame('App\Catalog\Domain\Event\CatalogSubmissionApproved', $outbox[0]['event_type']);

        $audit = $this->auditRows();
        self::assertCount(1, $audit, 'the approval writes its own audit entry');
        self::assertSame('CATALOG_SUBMISSION_APPROVED', $audit[0]['action']);
        self::assertSame('super-admin', $audit[0]['actor_id']);

        $view = $this->viewApproval();
        self::assertSame('APPROVED', $view['approval_status']);
        self::assertSame('super-admin', $view['reviewed_by']);
        self::assertNull($view['rejection_reason']);
    }

    public function testASecondReviewerSaveOnApprovedWritesNothingAndKeepsTheOriginalReviewer(): void
    {
        $this->store()->create(CatalogSubmissionKind::Product, self::CREATED_PRODUCT, 'admin-owner');
        $this->store()->decide(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            \App\Catalog\Domain\Model\CatalogDecision::Approve,
            'original-reviewer',
        );
        $before = $this->submissionRow();

        $submission = $this->store()->reviewerSave(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            'second-super-admin',
        );

        self::assertSame('APPROVED', $submission->status()->value);
        self::assertSame('original-reviewer', $submission->reviewedBy(), 'the original reviewer keeps the credit');

        self::assertCount(2, $this->outboxRows(), 'an already-approved row emits no second approval event');
        self::assertCount(2, $this->auditRows(), 'an already-approved row emits no second approval audit entry');
        self::assertSame($before['reviewed_by'], $this->submissionRow()['reviewed_by']);
        self::assertSame($before['reviewed_at'], $this->submissionRow()['reviewed_at']);
        self::assertSame($before['updated_at'], $this->submissionRow()['updated_at'], 'the row is not rewritten');
    }

    public function testOwnerModifiedReopensApprovedWithAReopenedEventAndNoAuditEntry(): void
    {
        $this->store()->create(CatalogSubmissionKind::Product, self::CREATED_PRODUCT, 'admin-owner');
        $this->store()->decide(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            \App\Catalog\Domain\Model\CatalogDecision::Approve,
            'reviewer-1',
        );

        $submission = $this->store()->ownerModified(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
        );

        self::assertSame('PENDING_APPROVAL', $submission->status()->value);
        self::assertNull($submission->reviewedBy());
        self::assertNull($submission->reviewedAt());

        $outbox = $this->outboxRows();
        self::assertCount(3, $outbox);
        self::assertSame('App\Catalog\Domain\Event\CatalogSubmissionReopened', $outbox[2]['event_type']);
        self::assertCount(2, $this->auditRows(), 'the spec names no reopen audit action');

        $view = $this->viewApproval();
        self::assertSame('PENDING_APPROVAL', $view['approval_status']);
        self::assertNull($view['reviewed_by']);
        self::assertSame('admin-owner', $view['submitted_by'], 'reopening keeps the original submitter');
    }

    public function testOwnerModifiedOnPendingWritesNothing(): void
    {
        $this->store()->create(CatalogSubmissionKind::Product, self::CREATED_PRODUCT, 'admin-owner');
        $before = $this->submissionRow();

        $submission = $this->store()->ownerModified(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
        );

        self::assertSame('PENDING_APPROVAL', $submission->status()->value);
        self::assertCount(1, $this->outboxRows(), 'an edit of a pending record is not a transition');
        self::assertCount(1, $this->auditRows());
        self::assertSame($before['updated_at'], $this->submissionRow()['updated_at']);
    }

    public function testSubmitRefusesAnActorWhoDoesNotOwnTheSubmissionAndWritesNothing(): void
    {
        $this->seedSubmission('REJECTED', [
            'submitted_by' => 'admin-owner',
            'reviewed_by' => 'reviewer-1',
            'reviewed_at' => '2026-10-29 09:00:00.000000+00',
            'rejection_reason' => 'Fix the description.',
        ]);

        $failure = ApprovalAttempt::failureOf(fn () => $this->store()->submit(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            'other-admin',
            false,
        ));

        self::assertInstanceOf(CatalogSubmissionNotOwnedException::class, $failure);
        self::assertCount(0, $this->outboxRows(), 'a refused submission leaves no event');
        self::assertCount(0, $this->auditRows(), 'a refused submission leaves no audit entry');
        self::assertSame('REJECTED', $this->submissionRow()['approval_status']);

        $submission = $this->store()->submit(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            'super-admin',
            true,
        );

        self::assertSame('PENDING_APPROVAL', $submission->status()->value, 'a Super Admin may submit on the owner\'s behalf');
    }

    public function testDeleteRemovesOnlyTheSubmissionRow(): void
    {
        $this->store()->create(CatalogSubmissionKind::Product, self::CREATED_PRODUCT, 'admin-owner');

        $this->store()->delete(CatalogSubmissionKind::Product, self::CREATED_PRODUCT);

        self::assertSame([], $this->connection()->fetchAllAssociative(
            'SELECT kind FROM catalog_submission WHERE kind = :kind AND slug = :slug',
            ['kind' => 'products', 'slug' => self::CREATED_PRODUCT],
        ));
        self::assertSame('PENDING_APPROVAL', $this->viewApproval()['approval_status'], 'the content row is WP3\'s to delete, not the store\'s');
        self::assertCount(1, $this->outboxRows(), 'delete is cleanup, not a transition');
        self::assertCount(1, $this->auditRows(), 'delete does not rewrite the trail');
    }

    public function testAnAuditFailureRollsBackTheDecisionOutboxAndProjection(): void
    {
        $this->store()->create(CatalogSubmissionKind::Product, self::CREATED_PRODUCT, 'admin-owner');
        $connection = $this->connection();

        $store = new DbalCatalogSubmissionStore(
            $connection,
            new DoctrineOutbox($connection, $this->serializer()),
            new FailingCatalogAuditPort(),
            new ProjectStorefrontApproval($connection),
        );

        $failure = ApprovalAttempt::failureOf(fn () => $store->decide(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            \App\Catalog\Domain\Model\CatalogDecision::Approve,
            'reviewer-1',
        ));

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame(FailingCatalogAuditPort::MESSAGE, $failure->getMessage());
        self::assertSame('PENDING_APPROVAL', $this->submissionRow()['approval_status'], 'the decision is not committed without its audit entry');
        self::assertCount(1, $this->outboxRows(), 'the approval event is rolled back with the audit failure');
        self::assertCount(1, $this->auditRows());
        self::assertSame('PENDING_APPROVAL', $this->viewApproval()['approval_status'], 'the view did not move either');
    }

    public function testAProjectionFailureLeavesNoSubmissionOutboxOrAuditRow(): void
    {
        $connection = $this->connection();
        $failingConnection = new class([], new Driver()) extends Connection {
            public function executeStatement(string $sql, array $params = [], array $types = []): int|string
            {
                throw new \RuntimeException('projection unavailable');
            }
        };

        $store = new DbalCatalogSubmissionStore(
            $connection,
            new DoctrineOutbox($connection, $this->serializer()),
            new DbalCatalogAuditWriter($connection),
            new ProjectStorefrontApproval($failingConnection),
        );

        $failure = ApprovalAttempt::failureOf(fn () => $store->create(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            'admin-owner',
        ));

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('projection unavailable', $failure->getMessage());
        self::assertSame([], $connection->fetchAllAssociative(
            'SELECT kind FROM catalog_submission WHERE slug = :slug',
            ['slug' => self::CREATED_PRODUCT],
        ), 'a failed projection leaves no submission');
        self::assertCount(0, $this->outboxRows(), 'a failed projection leaves no outbox event');
        self::assertCount(0, $this->auditRows(), 'a failed projection leaves no audit entry');
        self::assertNull($this->viewApproval()['submitted_by'], 'the content row keeps its previous approval metadata');
    }

    private function serializer(): SerializerInterface
    {
        return new Serializer(
            [new DateTimeNormalizer(), new ObjectNormalizer()],
            [new JsonEncoder()],
        );
    }

    public function testImportSeedsAnExistingStateWithoutAnEventOrAuditAndTheSecondImportChangesNothing(): void
    {
        $submittedAt = new \DateTimeImmutable('2026-10-20 10:00:00+00');
        $reviewedAt = new \DateTimeImmutable('2026-10-21 11:00:00+00');
        $updatedAt = new \DateTimeImmutable('2026-10-21 11:00:00+00');

        $submission = CatalogSubmission::reconstitute(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            ApprovalStatus::Approved,
            'legacy-owner',
            $submittedAt,
            'legacy-reviewer',
            $reviewedAt,
            null,
            $updatedAt,
        );

        self::assertTrue($this->store()->import($submission));

        $row = $this->submissionRow();
        self::assertSame('APPROVED', $row['approval_status']);
        self::assertSame('legacy-owner', $row['submitted_by']);
        self::assertSame('legacy-reviewer', $row['reviewed_by']);
        self::assertEquals($submittedAt, new \DateTimeImmutable((string) $row['submitted_at']));
        self::assertEquals($reviewedAt, new \DateTimeImmutable((string) $row['reviewed_at']));
        self::assertCount(0, $this->outboxRows(), 'seeding an existing state is not a transition');
        self::assertCount(0, $this->auditRows(), 'seeding an existing state writes no audit entry');

        $view = $this->viewApproval();
        self::assertSame('APPROVED', $view['approval_status']);
        self::assertSame('legacy-owner', $view['submitted_by']);
        self::assertSame('legacy-reviewer', $view['reviewed_by']);
        self::assertEquals($reviewedAt, new \DateTimeImmutable((string) $view['reviewed_at']));

        $replacement = CatalogSubmission::reconstitute(
            CatalogSubmissionKind::Product,
            self::CREATED_PRODUCT,
            ApprovalStatus::PendingApproval,
            'other-owner',
            new \DateTimeImmutable('2026-10-25 08:00:00+00'),
            null,
            null,
            null,
            new \DateTimeImmutable('2026-10-25 08:00:00+00'),
        );

        self::assertFalse($this->store()->import($replacement), 'a second import must not overwrite the stored state');
        self::assertSame('APPROVED', $this->submissionRow()['approval_status']);
        self::assertSame('legacy-owner', $this->submissionRow()['submitted_by']);
        self::assertCount(0, $this->outboxRows());
        self::assertCount(0, $this->auditRows());
    }

    public function testFindReturnsTheStoredSubmissionOrNull(): void
    {
        self::assertNull($this->store()->find(CatalogSubmissionKind::Product, self::CREATED_PRODUCT));

        $this->store()->create(CatalogSubmissionKind::Product, self::CREATED_PRODUCT, 'admin-owner');

        $found = $this->store()->find(CatalogSubmissionKind::Product, self::CREATED_PRODUCT);

        self::assertInstanceOf(CatalogSubmission::class, $found);
        self::assertSame('PENDING_APPROVAL', $found->status()->value);
        self::assertSame('admin-owner', $found->submittedBy());
        self::assertNotNull($found->submittedAt());
    }

    /** @param array<string, mixed> $overrides */
    private function seedSubmission(string $status, array $overrides = []): void
    {
        $this->connection()->insert(
            'catalog_submission',
            array_replace([
                'kind' => 'products',
                'slug' => self::CREATED_PRODUCT,
                'approval_status' => $status,
                'submitted_by' => 'admin-owner',
                'submitted_at' => '2026-10-29 08:00:00.000000+00',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
                'updated_at' => '2026-10-29 09:00:00.000000+00',
            ], $overrides),
        );
    }

    /** @return array<string, mixed> */
    private function submissionRow(): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM catalog_submission WHERE kind = :kind AND slug = :slug',
            ['kind' => 'products', 'slug' => self::CREATED_PRODUCT],
        );
        self::assertIsArray($row, 'the store must have persisted the submission');

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function outboxRows(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT aggregate_type, aggregate_id, event_type, payload FROM outbox WHERE aggregate_id = :id ORDER BY occurred_on',
            ['id' => 'products:'.self::CREATED_PRODUCT],
        );
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT action, target_type, target_slug, actor_id, reason FROM catalog_audit_log WHERE target_slug = :slug ORDER BY created_at',
            ['slug' => self::CREATED_PRODUCT],
        );
    }

    /** @return array<string, mixed> */
    private function viewApproval(): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT approval_status, submitted_by, submitted_at, reviewed_by, reviewed_at, rejection_reason, published
               FROM storefront_product_view WHERE slug = :slug',
            ['slug' => self::CREATED_PRODUCT],
        );
        self::assertIsArray($row, 'the content row is the projection target, not something the store inserts');

        return $row;
    }

    private function removeRows(): void
    {
        $connection = $this->connection();
        $connection->executeStatement(
            'DELETE FROM catalog_submission WHERE slug = :slug',
            ['slug' => self::CREATED_PRODUCT],
        );
        $connection->executeStatement(
            'DELETE FROM outbox WHERE aggregate_id = :id',
            ['id' => 'products:'.self::CREATED_PRODUCT],
        );
        CatalogRow::deleteAuditEntries($connection, self::CREATED_PRODUCT);
        CatalogRow::deleteProducts($connection, self::CREATED_PRODUCT);
    }

    /**
     * solid Task 2.5 (ISP-001) — the six narrow ports and the read repository are aliases over
     * the one adapter, so every transition still shares the single catalog_submission writer.
     */
    public function testEverySubmissionPortResolvesToTheSameAdapterInstance(): void
    {
        $adapter = static::getContainer()->get(DbalCatalogSubmissionStore::class);

        foreach ([
            CatalogSubmissionCreator::class,
            CatalogSubmissionSubmitter::class,
            CatalogSubmissionDecider::class,
            CatalogSubmissionReviewer::class,
            CatalogSubmissionOwnerEditor::class,
            CatalogSubmissionDeleter::class,
            CatalogSubmissionRepository::class,
        ] as $port) {
            $resolved = static::getContainer()->get($port);
            self::assertInstanceOf($port, $resolved, sprintf('%s resolves', $port));
            self::assertSame($adapter, $resolved, sprintf('%s is the one adapter', $port));
        }
    }

    private function connection(): Connection
    {
        $connection = $this->connection;
        if (!$connection instanceof Connection) {
            throw new \LogicException('The DBAL connection was not bootstrapped by setUp().');
        }

        return $connection;
    }

    private function store(): DbalCatalogSubmissionStore
    {
        $store = $this->store;
        if (!$store instanceof DbalCatalogSubmissionStore) {
            throw new \LogicException('The store was not resolved from the container by setUp().');
        }

        return $store;
    }
}

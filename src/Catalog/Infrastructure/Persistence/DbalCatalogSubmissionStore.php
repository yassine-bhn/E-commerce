<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Port\CatalogSubmissionStore;
use App\Catalog\Domain\Event\CatalogSubmissionApproved;
use App\Catalog\Domain\Event\CatalogSubmissionCreated;
use App\Catalog\Domain\Event\CatalogSubmissionRejected;
use App\Catalog\Domain\Event\CatalogSubmissionReopened;
use App\Catalog\Domain\Event\CatalogSubmissionResubmitted;
use App\Catalog\Domain\Event\CatalogSubmissionSubmitted;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use App\Catalog\Domain\Repository\CatalogSubmissionRepository;
use App\Catalog\Infrastructure\Projection\ProjectStorefrontApproval;
use App\Shared\Domain\Outbox\OutboxPort;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-15 WP2 (R2/R3) — the only adapter that writes `catalog_submission`.
 *
 * One mutating call is one transaction: lock the row (except on create), apply the domain
 * transition, persist it, append exactly one transition event, record the audit entry the spec
 * names, then project the six columns. Any failure rolls the whole call back, so the write model
 * and the read model cannot disagree.
 */
final class DbalCatalogSubmissionStore implements CatalogSubmissionStore, CatalogSubmissionRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OutboxPort $outbox,
        private readonly CatalogAuditPort $audit,
        private readonly ProjectStorefrontApproval $projector,
    ) {
    }

    public function create(CatalogSubmissionKind $kind, string $slug, string $actorId): CatalogSubmission
    {
        $now = new \DateTimeImmutable();
        $submission = CatalogSubmission::create($kind, $slug, $actorId, $now);

        $this->connection->transactional(function () use ($submission, $kind, $slug, $actorId, $now): void {
            $this->connection->insert('catalog_submission', [
                'kind' => $kind->value,
                'slug' => $slug,
                'approval_status' => $submission->status()->value,
                'submitted_by' => $submission->submittedBy(),
                'submitted_at' => $submission->submittedAt()?->format('Y-m-d H:i:s.uP'),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
                'updated_at' => $submission->updatedAt()->format('Y-m-d H:i:s.uP'),
            ]);

            $this->outbox->append(
                'catalog_submission',
                $this->aggregateId($kind, $slug),
                [new CatalogSubmissionCreated(
                    Uuid::v7()->toRfc4122(),
                    $kind->value,
                    $slug,
                    $actorId,
                    $submission->status()->value,
                    null,
                    $now,
                )],
            );

            $this->audit->record(
                'CATALOG_SUBMISSION_CREATED',
                $kind->auditTarget(),
                $slug,
                $actorId,
            );

            $this->projector->project($submission);
        });

        return $submission;
    }

    public function import(CatalogSubmission $submission): bool
    {
        return $this->connection->transactional(function () use ($submission): bool {
            $inserted = (int) $this->connection->executeStatement(
                'INSERT INTO catalog_submission
                        (kind, slug, approval_status, submitted_by, submitted_at, reviewed_by, reviewed_at,
                         rejection_reason, updated_at)
                 VALUES (:kind, :slug, :status, :submitted_by, :submitted_at, :reviewed_by, :reviewed_at,
                         :rejection_reason, :updated_at)
                 ON CONFLICT (kind, slug) DO NOTHING',
                [
                    'kind' => $submission->kind()->value,
                    'slug' => $submission->slug(),
                    'status' => $submission->status()->value,
                    'submitted_by' => $submission->submittedBy(),
                    'submitted_at' => $submission->submittedAt()?->format('Y-m-d H:i:s.uP'),
                    'reviewed_by' => $submission->reviewedBy(),
                    'reviewed_at' => $submission->reviewedAt()?->format('Y-m-d H:i:s.uP'),
                    'rejection_reason' => $submission->rejectionReason(),
                    'updated_at' => $submission->updatedAt()->format('Y-m-d H:i:s.uP'),
                ],
            );

            if (0 === $inserted) {
                return false;
            }

            $this->projector->project($submission);

            return true;
        });
    }

    public function submit(
        CatalogSubmissionKind $kind,
        string $slug,
        string $actorId,
        bool $actorIsSuperAdmin,
    ): CatalogSubmission {
        $now = new \DateTimeImmutable();

        return $this->connection->transactional(function () use ($kind, $slug, $actorId, $actorIsSuperAdmin, $now): CatalogSubmission {
            $submission = $this->lock($kind, $slug);

            if (!$actorIsSuperAdmin && $submission->submittedBy() !== $actorId) {
                throw CatalogSubmissionNotOwnedException::for($kind, $slug);
            }

            $wasRejected = ApprovalStatus::Rejected === $submission->status();

            $submission->submit($actorId, $now);
            $this->persist($submission);

            $this->outbox->append(
                'catalog_submission',
                $this->aggregateId($kind, $slug),
                [$wasRejected
                    ? new CatalogSubmissionResubmitted(
                        Uuid::v7()->toRfc4122(),
                        $kind->value,
                        $slug,
                        $actorId,
                        $submission->status()->value,
                        null,
                        $now,
                    )
                    : new CatalogSubmissionSubmitted(
                        Uuid::v7()->toRfc4122(),
                        $kind->value,
                        $slug,
                        $actorId,
                        $submission->status()->value,
                        null,
                        $now,
                    )],
            );

            $this->audit->record(
                $wasRejected ? 'CATALOG_SUBMISSION_RESUBMITTED' : 'CATALOG_SUBMISSION_SUBMITTED',
                $kind->auditTarget(),
                $slug,
                $actorId,
            );

            $this->projector->project($submission);

            return $submission;
        });
    }

    public function decide(
        CatalogSubmissionKind $kind,
        string $slug,
        CatalogDecision $decision,
        string $reviewerId,
        ?string $reason = null,
    ): CatalogSubmission {
        $now = new \DateTimeImmutable();

        return $this->connection->transactional(function () use ($kind, $slug, $decision, $reviewerId, $reason, $now): CatalogSubmission {
            $submission = $this->lock($kind, $slug);

            $submission->decide($decision, $reviewerId, $reason, $now);
            $this->persist($submission);

            $this->outbox->append(
                'catalog_submission',
                $this->aggregateId($kind, $slug),
                [CatalogDecision::Approve === $decision
                    ? new CatalogSubmissionApproved(
                        Uuid::v7()->toRfc4122(),
                        $kind->value,
                        $slug,
                        $reviewerId,
                        $submission->status()->value,
                        null,
                        $now,
                    )
                    : new CatalogSubmissionRejected(
                        Uuid::v7()->toRfc4122(),
                        $kind->value,
                        $slug,
                        $reviewerId,
                        $submission->status()->value,
                        $submission->rejectionReason(),
                        $now,
                    )],
            );

            $this->audit->record(
                $decision->auditAction(),
                $kind->auditTarget(),
                $slug,
                $reviewerId,
                $submission->rejectionReason(),
            );

            $this->projector->project($submission);

            return $submission;
        });
    }

    public function reviewerSave(
        CatalogSubmissionKind $kind,
        string $slug,
        string $actorId,
    ): CatalogSubmission {
        $now = new \DateTimeImmutable();

        return $this->connection->transactional(function () use ($kind, $slug, $actorId, $now): CatalogSubmission {
            $submission = $this->lock($kind, $slug);

            if (!$submission->reviewerSave($actorId, $now)) {
                return $submission;
            }

            $this->persist($submission);

            $this->outbox->append(
                'catalog_submission',
                $this->aggregateId($kind, $slug),
                [new CatalogSubmissionApproved(
                    Uuid::v7()->toRfc4122(),
                    $kind->value,
                    $slug,
                    $actorId,
                    $submission->status()->value,
                    null,
                    $now,
                )],
            );

            $this->audit->record(
                'CATALOG_SUBMISSION_APPROVED',
                $kind->auditTarget(),
                $slug,
                $actorId,
            );

            $this->projector->project($submission);

            return $submission;
        });
    }

    public function ownerModified(CatalogSubmissionKind $kind, string $slug): CatalogSubmission
    {
        $now = new \DateTimeImmutable();

        return $this->connection->transactional(function () use ($kind, $slug, $now): CatalogSubmission {
            $submission = $this->lock($kind, $slug);
            $actorId = $submission->submittedBy() ?? 'system:catalog-submission';

            if (!$submission->ownerModified($now)) {
                return $submission;
            }

            $this->persist($submission);

            $this->outbox->append(
                'catalog_submission',
                $this->aggregateId($kind, $slug),
                [new CatalogSubmissionReopened(
                    Uuid::v7()->toRfc4122(),
                    $kind->value,
                    $slug,
                    $actorId,
                    $submission->status()->value,
                    null,
                    $now,
                )],
            );

            $this->projector->project($submission);

            return $submission;
        });
    }

    public function delete(CatalogSubmissionKind $kind, string $slug): void
    {
        $this->connection->transactional(function () use ($kind, $slug): void {
            $this->connection->executeStatement(
                'DELETE FROM catalog_submission WHERE kind = :kind AND slug = :slug',
                ['kind' => $kind->value, 'slug' => $slug],
            );
        });
    }

    public function find(CatalogSubmissionKind $kind, string $slug): ?CatalogSubmission
    {
        $row = $this->connection->fetchAssociative(
            'SELECT slug, approval_status, submitted_by, submitted_at, reviewed_by, reviewed_at,
                    rejection_reason, updated_at
               FROM catalog_submission
              WHERE kind = :kind AND slug = :slug',
            ['kind' => $kind->value, 'slug' => $slug],
        );

        return false === $row ? null : $this->hydrate($kind, $row);
    }

    private function lock(CatalogSubmissionKind $kind, string $slug): CatalogSubmission
    {
        $row = $this->connection->fetchAssociative(
            'SELECT slug, approval_status, submitted_by, submitted_at, reviewed_by, reviewed_at,
                    rejection_reason, updated_at
               FROM catalog_submission
              WHERE kind = :kind AND slug = :slug
              FOR UPDATE',
            ['kind' => $kind->value, 'slug' => $slug],
        );

        if (false === $row) {
            throw CatalogSubmissionNotFoundException::for($kind, $slug);
        }

        return $this->hydrate($kind, $row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(CatalogSubmissionKind $kind, array $row): CatalogSubmission
    {
        return CatalogSubmission::reconstitute(
            $kind,
            (string) $row['slug'],
            ApprovalStatus::fromStorage((string) $row['approval_status']),
            $this->nullableString($row['submitted_by'] ?? null),
            $this->nullableDate($row['submitted_at'] ?? null),
            $this->nullableString($row['reviewed_by'] ?? null),
            $this->nullableDate($row['reviewed_at'] ?? null),
            $this->nullableString($row['rejection_reason'] ?? null),
            new \DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function persist(CatalogSubmission $submission): void
    {
        $this->connection->executeStatement(
            'UPDATE catalog_submission
                SET approval_status = :status,
                    submitted_by = :submitted_by,
                    submitted_at = :submitted_at,
                    reviewed_by = :reviewed_by,
                    reviewed_at = :reviewed_at,
                    rejection_reason = :rejection_reason,
                    updated_at = :updated_at
              WHERE kind = :kind AND slug = :slug',
            [
                'status' => $submission->status()->value,
                'submitted_by' => $submission->submittedBy(),
                'submitted_at' => $submission->submittedAt()?->format('Y-m-d H:i:s.uP'),
                'reviewed_by' => $submission->reviewedBy(),
                'reviewed_at' => $submission->reviewedAt()?->format('Y-m-d H:i:s.uP'),
                'rejection_reason' => $submission->rejectionReason(),
                'updated_at' => $submission->updatedAt()->format('Y-m-d H:i:s.uP'),
                'kind' => $submission->kind()->value,
                'slug' => $submission->slug(),
            ],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function nullableDate(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    private function aggregateId(CatalogSubmissionKind $kind, string $slug): string
    {
        return $kind->value.':'.$slug;
    }
}

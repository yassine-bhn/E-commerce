<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\SubmitCatalogSubmission;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R3/R4/R9/R24/R39 — puts a submission in front of a reviewer, and only the owner or the back
 * office acting for them may do it.
 *
 * The state change and its audit row commit together, so a refused submission leaves the record
 * exactly as it was and writes no history. Resubmission also clears the previous reviewer,
 * decision moment and rejection reason: the next reviewer must see a fresh submission, not a
 * stale REJECTED outcome.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class SubmitCatalogSubmissionHandler
{
    private const string LOAD = 'SELECT approval_status, submitted_by FROM %s WHERE slug = :slug';

    private const string STORE = 'UPDATE %s SET
            approval_status = :status,
            reviewed_by = NULL,
            reviewed_at = NULL,
            rejection_reason = NULL,
            submitted_by = :submitted_by,
            submitted_at = :submitted_at
        WHERE slug = :slug';

    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogAuditPort $audit,
    ) {
    }

    public function __invoke(SubmitCatalogSubmission $command): void
    {
        $this->connection->transactional(function () use ($command): void {
            $row = $this->connection->fetchAssociative(
                sprintf(self::LOAD, $command->kind->table()),
                ['slug' => $command->slug],
            );
            if (false === $row) {
                throw CatalogSubmissionNotFoundException::for($command->kind, $command->slug);
            }

            $current = ApprovalStatus::fromStorage((string) $row['approval_status']);
            $owner = is_string($row['submitted_by'] ?? null) ? $row['submitted_by'] : null;
            if (!$command->actorIsAdmin && $owner !== $command->actorId) {
                throw CatalogSubmissionNotOwnedException::for($command->kind, $command->slug);
            }

            if (ApprovalStatus::Approved === $current) {
                throw ApprovalTransitionRefusedException::approvedCannotBeSubmitted($command->slug);
            }

            $this->connection->executeStatement(
                sprintf(self::STORE, $command->kind->table()),
                [
                    'status' => ApprovalStatus::PendingApproval->value,
                    'submitted_by' => $command->actorId,
                    'submitted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
                    'slug' => $command->slug,
                ],
            );

            $this->audit->record(
                $current->canBeResubmitted() ? 'CATALOG_SUBMISSION_RESUBMITTED' : 'CATALOG_SUBMISSION_SUBMITTED',
                $command->kind->auditTarget(),
                $command->slug,
                $command->actorId,
            );
        });
    }
}

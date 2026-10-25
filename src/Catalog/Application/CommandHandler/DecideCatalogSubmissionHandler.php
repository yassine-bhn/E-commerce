<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\DecideCatalogSubmission;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R22/R23/R26 — one review outcome, written in one transaction.
 *
 * Only a `PENDING_APPROVAL` submission can be decided, and the decision is the only place an
 * approval status becomes `APPROVED` or `REJECTED`: nothing else in the workflow writes those two
 * values, so no CRUD path can approve a record by accident.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DecideCatalogSubmissionHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogAuditPort $audit,
    ) {
    }

    public function __invoke(DecideCatalogSubmission $command): void
    {
        $this->connection->transactional(function () use ($command): void {
            $current = $this->currentStatus($command->kind, $command->slug);

            $target = $command->decision->targetStatus();
            if (!$current->canBeApproved() || !$current->canMoveTo($target)) {
                throw ApprovalTransitionRefusedException::notPending($command->slug, $current);
            }

            $this->connection->executeStatement(
                sprintf(
                    'UPDATE %s SET approval_status = :status, reviewed_by = :reviewer, reviewed_at = :reviewed_at,
                        rejection_reason = :reason
                     WHERE slug = :slug',
                    $command->kind->table(),
                ),
                [
                    'status' => $target->value,
                    'reviewer' => $command->reviewerId,
                    'reviewed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
                    'reason' => $command->reason,
                    'slug' => $command->slug,
                ],
            );

            $this->audit->record(
                $command->decision->auditAction(),
                $command->kind->auditTarget(),
                $command->slug,
                $command->reviewerId,
                $command->reason,
            );
        });
    }

    private function currentStatus(CatalogSubmissionKind $kind, string $slug): ApprovalStatus
    {
        $stored = $this->connection->fetchOne(
            sprintf('SELECT approval_status FROM %s WHERE slug = :slug', $kind->table()),
            ['slug' => $slug],
        );
        if (!is_string($stored)) {
            throw CatalogSubmissionNotFoundException::for($kind, $slug);
        }

        return ApprovalStatus::fromStorage($stored);
    }
}

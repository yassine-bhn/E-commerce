<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * R22/R23 — the two decisions a review can end on. Anything else is not a review outcome.
 */
enum CatalogDecision: string
{
    case Approve = 'APPROVED';
    case Reject = 'REJECTED';

    public function targetStatus(): ApprovalStatus
    {
        return match ($this) {
            self::Approve => ApprovalStatus::Approved,
            self::Reject => ApprovalStatus::Rejected,
        };
    }

    public function auditAction(): string
    {
        return match ($this) {
            self::Approve => 'CATALOG_SUBMISSION_APPROVED',
            self::Reject => 'CATALOG_SUBMISSION_REJECTED',
        };
    }

    public function requiresReason(): bool
    {
        return self::Reject === $this;
    }
}

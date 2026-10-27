<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * spec-run-8 R21–R27 — the approval lifecycle of a catalog submission.
 *
 * Approval is decided by the Super Admin only and is never the same act as publishing: this
 * enum says what a review may do next, the publication columns say what the storefront shows.
 * Every transition the prompt does not list is refused, so no caller has to remember the matrix.
 */
enum ApprovalStatus: string
{
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';

    public static function fromStorage(string $value): self
    {
        $status = self::tryFrom($value);
        if (null === $status) {
            throw new \InvalidArgumentException(sprintf('Unknown approval status "%s".', $value));
        }

        return $status;
    }

    /** The status a freshly created submission always starts in (R10). */
    public static function initial(): self
    {
        return self::PendingApproval;
    }

    public function canBeApproved(): bool
    {
        return self::PendingApproval === $this;
    }

    public function canBeRejected(): bool
    {
        return self::PendingApproval === $this;
    }

    /** `REJECTED` → `PENDING_APPROVAL`, after the owner corrected the submission (R24). */
    public function canBeResubmitted(): bool
    {
        return self::Rejected === $this;
    }

    /** An owner may only edit what a reviewer has not approved yet. */
    public function canBeEditedByOwner(): bool
    {
        return self::PendingApproval === $this || self::Rejected === $this;
    }

    /**
     * R25 — a modification of an approved record sends it back for reapproval. A rejected record
     * stays rejected until the owner explicitly resubmits it, so an edit never re-enters the queue
     * behind the reviewer's back.
     */
    public function afterModification(): self
    {
        return self::Approved === $this ? self::PendingApproval : $this;
    }

    /**
     * The reviewer validates by writing: a Super Admin's own save is never queued for himself, so
     * the record comes out approved whatever state the edit found it in.
     */
    public function afterReviewerModification(): self
    {
        return self::Approved;
    }

    public function isPubliclyVisible(): bool
    {
        return self::Approved === $this;
    }

    public function canMoveTo(self $target): bool
    {
        return match (true) {
            self::PendingApproval === $this => self::Approved === $target || self::Rejected === $target,
            self::Rejected === $this => self::PendingApproval === $target,
            self::Approved === $this => self::PendingApproval === $target,
        };
    }

    public function assertCanMoveTo(self $target): void
    {
        if (!$this->canMoveTo($target)) {
            throw ApprovalTransitionRefusedException::create($this, $target);
        }
    }
}

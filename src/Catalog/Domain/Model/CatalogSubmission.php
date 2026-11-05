<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * spec-run-15 WP2 / R1–R3 — the state-based approval write model.
 *
 * Approval used to live only in the two storefront view tables, so no single owner could refuse an
 * illegal transition and a replay had nothing authoritative to rebuild from. This aggregate is that
 * owner: it holds the six approval columns plus the row's own update moment, and every transition
 * either reaches the state the workflow allows or throws before the store writes anything.
 *
 * It is deliberately free of DBAL, Doctrine and the view tables: `DbalCatalogSubmissionStore` is the
 * only adapter that persists it.
 */
final class CatalogSubmission
{
    private function __construct(
        private readonly CatalogSubmissionKind $kind,
        private readonly string $slug,
        private ApprovalStatus $status,
        private ?string $submittedBy,
        private ?\DateTimeImmutable $submittedAt,
        private ?string $reviewedBy,
        private ?\DateTimeImmutable $reviewedAt,
        private ?string $rejectionReason,
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        CatalogSubmissionKind $kind,
        string $slug,
        string $actorId,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $kind,
            $slug,
            ApprovalStatus::initial(),
            $actorId,
            $now,
            null,
            null,
            null,
            $now,
        );
    }

    /**
     * Rebuilds the aggregate the store locked, without replaying any transition. Persistence is the
     * only caller: the state it reads is already the result of decisions this model made.
     */
    public static function reconstitute(
        CatalogSubmissionKind $kind,
        string $slug,
        ApprovalStatus $status,
        ?string $submittedBy,
        ?\DateTimeImmutable $submittedAt,
        ?string $reviewedBy,
        ?\DateTimeImmutable $reviewedAt,
        ?string $rejectionReason,
        \DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $kind,
            $slug,
            $status,
            $submittedBy,
            $submittedAt,
            $reviewedBy,
            $reviewedAt,
            $rejectionReason,
            $updatedAt,
        );
    }

    public function kind(): CatalogSubmissionKind
    {
        return $this->kind;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function status(): ApprovalStatus
    {
        return $this->status;
    }

    public function decide(
        CatalogDecision $decision,
        string $reviewerId,
        ?string $reason,
        \DateTimeImmutable $now,
    ): void {
        if (ApprovalStatus::PendingApproval !== $this->status) {
            throw ApprovalTransitionRefusedException::notPending($this->slug, $this->status);
        }

        if ($decision->requiresReason() && '' === trim($reason ?? '')) {
            throw new ApprovalTransitionRefusedException(sprintf('The submission "%s" cannot be rejected without a reason.', $this->slug));
        }

        $this->status = $decision->targetStatus();
        $this->reviewedBy = $reviewerId;
        $this->reviewedAt = $now;
        $this->rejectionReason = CatalogDecision::Approve === $decision ? null : trim((string) $reason);
        $this->updatedAt = $now;
    }

    public function submit(string $actorId, \DateTimeImmutable $now): void
    {
        if (ApprovalStatus::Approved === $this->status) {
            throw ApprovalTransitionRefusedException::approvedCannotBeSubmitted($this->slug);
        }

        $this->status = ApprovalStatus::PendingApproval;
        $this->submittedBy = $actorId;
        $this->submittedAt = $now;
        $this->reviewedBy = null;
        $this->reviewedAt = null;
        $this->rejectionReason = null;
        $this->updatedAt = $now;
    }

    public function reviewerSave(string $actorId, \DateTimeImmutable $now): bool
    {
        if (ApprovalStatus::Approved === $this->status) {
            return false;
        }

        $this->status = ApprovalStatus::Approved;
        $this->reviewedBy = $actorId;
        $this->reviewedAt = $now;
        $this->rejectionReason = null;
        $this->updatedAt = $now;

        return true;
    }

    public function ownerModified(\DateTimeImmutable $now): bool
    {
        if (ApprovalStatus::Approved !== $this->status) {
            return false;
        }

        $this->status = ApprovalStatus::PendingApproval;
        $this->reviewedBy = null;
        $this->reviewedAt = null;
        $this->rejectionReason = null;
        $this->updatedAt = $now;

        return true;
    }

    public function submittedBy(): ?string
    {
        return $this->submittedBy;
    }

    public function submittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function reviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function reviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function rejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

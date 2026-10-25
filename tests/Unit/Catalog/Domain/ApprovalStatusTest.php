<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * spec-run-8 R21–R26 — the approval lifecycle is closed: three states, four transitions and a
 * typed refusal for everything else.
 */
final class ApprovalStatusTest extends TestCase
{
    public function testTheThreeStatesAreTheOnlyOnesTheStorageCanCarry(): void
    {
        self::assertSame('PENDING_APPROVAL', ApprovalStatus::PendingApproval->value);
        self::assertSame('APPROVED', ApprovalStatus::Approved->value);
        self::assertSame('REJECTED', ApprovalStatus::Rejected->value);
        self::assertSame(3, count(ApprovalStatus::cases()));

        self::assertSame(ApprovalStatus::Approved, ApprovalStatus::fromStorage('APPROVED'));
    }

    public function testAnUnknownStoredStatusIsRefusedInsteadOfGuessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ApprovalStatus::fromStorage('PUBLISHED');
    }

    public function testOnlyAPendingSubmissionCanBeApprovedOrRejected(): void
    {
        self::assertTrue(ApprovalStatus::PendingApproval->canBeApproved());
        self::assertTrue(ApprovalStatus::PendingApproval->canBeRejected());
        self::assertFalse(ApprovalStatus::Approved->canBeApproved());
        self::assertFalse(ApprovalStatus::Approved->canBeRejected());
        self::assertFalse(ApprovalStatus::Rejected->canBeApproved());
        self::assertFalse(ApprovalStatus::Rejected->canBeRejected());
    }

    public function testOnlyARejectedSubmissionCanBeResubmitted(): void
    {
        self::assertTrue(ApprovalStatus::Rejected->canBeResubmitted());
        self::assertFalse(ApprovalStatus::PendingApproval->canBeResubmitted());
        self::assertFalse(ApprovalStatus::Approved->canBeResubmitted());
    }

    public function testOnlyTheOwnerOfAPendingOrRejectedSubmissionMayEditIt(): void
    {
        self::assertTrue(ApprovalStatus::PendingApproval->canBeEditedByOwner());
        self::assertTrue(ApprovalStatus::Rejected->canBeEditedByOwner());
        self::assertFalse(ApprovalStatus::Approved->canBeEditedByOwner());
    }

    public function testEditingAnApprovedSubmissionSendsItBackForReapproval(): void
    {
        self::assertSame(ApprovalStatus::PendingApproval, ApprovalStatus::Approved->afterModification());
        self::assertSame(ApprovalStatus::PendingApproval, ApprovalStatus::PendingApproval->afterModification());
        self::assertSame(ApprovalStatus::Rejected, ApprovalStatus::Rejected->afterModification());
    }

    public function testOnlyAnApprovedSubmissionIsPubliclyVisible(): void
    {
        self::assertTrue(ApprovalStatus::Approved->isPubliclyVisible());
        self::assertFalse(ApprovalStatus::PendingApproval->isPubliclyVisible());
        self::assertFalse(ApprovalStatus::Rejected->isPubliclyVisible());
    }

    /** @return iterable<string, array{ApprovalStatus, ApprovalStatus, bool}> */
    public static function transitionMatrix(): iterable
    {
        yield 'pending to approved' => [ApprovalStatus::PendingApproval, ApprovalStatus::Approved, true];
        yield 'pending to rejected' => [ApprovalStatus::PendingApproval, ApprovalStatus::Rejected, true];
        yield 'rejected to pending' => [ApprovalStatus::Rejected, ApprovalStatus::PendingApproval, true];
        yield 'approved to pending' => [ApprovalStatus::Approved, ApprovalStatus::PendingApproval, true];
        yield 'approved to rejected' => [ApprovalStatus::Approved, ApprovalStatus::Rejected, false];
        yield 'rejected to approved' => [ApprovalStatus::Rejected, ApprovalStatus::Approved, false];
        yield 'pending to pending' => [ApprovalStatus::PendingApproval, ApprovalStatus::PendingApproval, false];
        yield 'approved to approved' => [ApprovalStatus::Approved, ApprovalStatus::Approved, false];
        yield 'rejected to rejected' => [ApprovalStatus::Rejected, ApprovalStatus::Rejected, false];
    }

    #[DataProvider('transitionMatrix')]
    public function testTheTransitionMatrix(ApprovalStatus $from, ApprovalStatus $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canMoveTo($to));

        if (!$allowed) {
            $this->expectException(ApprovalTransitionRefusedException::class);
        }

        $from->assertCanMoveTo($to);
    }
}

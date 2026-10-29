<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use PHPUnit\Framework\TestCase;

/**
 * spec-run-15 WP2 / R1–R3 — the approval write model is the only owner of an approval transition.
 *
 * The model is state based: every behaviour returns the state a reviewer or the owner is allowed
 * to reach, and the store persists exactly that. These tests pin the transition matrix the store
 * and WP3 route through, without a database or a framework.
 */
final class CatalogSubmissionTest extends TestCase
{
    private const string NOW = '2026-10-29T10:00:00.000000+00:00';

    public function testItCreatesAPendingSubmissionOwnedByItsCreator(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-creator',
            $now,
        );

        self::assertSame(CatalogSubmissionKind::Product, $submission->kind());
        self::assertSame('it-submission-product', $submission->slug());
        self::assertSame('PENDING_APPROVAL', $submission->status()->value);
        self::assertSame('admin-creator', $submission->submittedBy());
        self::assertEquals($now, $submission->submittedAt());
        self::assertNull($submission->reviewedBy());
        self::assertNull($submission->reviewedAt());
        self::assertNull($submission->rejectionReason());
        self::assertEquals($now, $submission->updatedAt());
    }

    public function testItApprovesAPendingSubmissionAndRecordsTheReviewer(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );
        $decidedAt = new \DateTimeImmutable('2026-10-29T11:00:00.000000+00:00');

        $submission->decide(CatalogDecision::Approve, 'reviewer-1', 'ignored on approval', $decidedAt);

        self::assertSame('APPROVED', $submission->status()->value);
        self::assertSame('reviewer-1', $submission->reviewedBy());
        self::assertEquals($decidedAt, $submission->reviewedAt());
        self::assertNull($submission->rejectionReason(), 'approval clears any reason a previous review left');
        self::assertEquals($decidedAt, $submission->updatedAt());
    }

    public function testItRefusesARejectionWithoutAReason(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );

        $this->expectException(ApprovalTransitionRefusedException::class);
        $this->expectExceptionMessage('The submission "it-submission-product" cannot be rejected without a reason.');

        $submission->decide(CatalogDecision::Reject, 'reviewer-1', '   ', new \DateTimeImmutable(self::NOW));
    }

    public function testItClearsTheReviewWhenARejectedSubmissionIsResubmitted(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );
        $submission->decide(
            CatalogDecision::Reject,
            'reviewer-1',
            'The description is wrong.',
            new \DateTimeImmutable('2026-10-29T11:00:00.000000+00:00'),
        );
        $resubmittedAt = new \DateTimeImmutable('2026-10-29T12:00:00.000000+00:00');

        $submission->submit('admin-owner', $resubmittedAt);

        self::assertSame('PENDING_APPROVAL', $submission->status()->value);
        self::assertSame('admin-owner', $submission->submittedBy());
        self::assertEquals($resubmittedAt, $submission->submittedAt());
        self::assertNull($submission->reviewedBy(), 'the next reviewer must see a fresh submission');
        self::assertNull($submission->reviewedAt());
        self::assertNull($submission->rejectionReason());
        self::assertEquals($resubmittedAt, $submission->updatedAt());
    }

    public function testItRefusesToSubmitAnApprovedSubmission(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );
        $submission->decide(CatalogDecision::Approve, 'reviewer-1', null, new \DateTimeImmutable(self::NOW));

        $this->expectException(ApprovalTransitionRefusedException::class);
        $this->expectExceptionMessage('The submission "it-submission-product" is already APPROVED: only a modification returns it to review.');

        $submission->submit('admin-owner', new \DateTimeImmutable(self::NOW));
    }

    public function testItRefusesToDecideAnApprovedSubmission(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );
        $submission->decide(CatalogDecision::Approve, 'reviewer-1', null, new \DateTimeImmutable(self::NOW));

        $this->expectException(ApprovalTransitionRefusedException::class);
        $this->expectExceptionMessage('The submission "it-submission-product" is APPROVED: only a PENDING_APPROVAL submission can be reviewed.');

        $submission->decide(CatalogDecision::Approve, 'reviewer-2', null, new \DateTimeImmutable(self::NOW));
    }

    public function testItRefusesToDecideARejectedSubmission(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );
        $submission->decide(CatalogDecision::Reject, 'reviewer-1', 'Fix the photos.', new \DateTimeImmutable(self::NOW));

        $this->expectException(ApprovalTransitionRefusedException::class);
        $this->expectExceptionMessage('The submission "it-submission-product" is REJECTED: only a PENDING_APPROVAL submission can be reviewed.');

        $submission->decide(CatalogDecision::Reject, 'reviewer-2', 'Still wrong.', new \DateTimeImmutable(self::NOW));
    }

    public function testAReviewerSaveApprovesARejectedSubmissionAndClearsTheReason(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );
        $submission->decide(
            CatalogDecision::Reject,
            'reviewer-1',
            'Fix the photos.',
            new \DateTimeImmutable('2026-10-29T11:00:00.000000+00:00'),
        );
        $savedAt = new \DateTimeImmutable('2026-10-29T12:00:00.000000+00:00');

        $changed = $submission->reviewerSave('super-admin', $savedAt);

        self::assertTrue($changed, 'the reviewer save really moved the rejected submission to approved');
        self::assertSame('APPROVED', $submission->status()->value);
        self::assertSame('super-admin', $submission->reviewedBy());
        self::assertEquals($savedAt, $submission->reviewedAt());
        self::assertNull($submission->rejectionReason(), 'approval clears the rejection reason');
        self::assertEquals($savedAt, $submission->updatedAt());
    }

    public function testAReviewerSaveApprovesAPendingSubmission(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );
        $savedAt = new \DateTimeImmutable('2026-10-29T11:00:00.000000+00:00');

        $changed = $submission->reviewerSave('reviewer-1', $savedAt);

        self::assertTrue($changed, 'the save really approved the submission');
        self::assertSame('APPROVED', $submission->status()->value);
        self::assertSame('reviewer-1', $submission->reviewedBy());
        self::assertEquals($savedAt, $submission->reviewedAt());
        self::assertNull($submission->rejectionReason());
        self::assertEquals($savedAt, $submission->updatedAt());
    }

    public function testAReviewerSaveKeepsTheOriginalReviewerOfAnApprovedSubmission(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );
        $decidedAt = new \DateTimeImmutable('2026-10-29T11:00:00.000000+00:00');
        $submission->decide(CatalogDecision::Approve, 'original-reviewer', null, $decidedAt);
        $laterSave = new \DateTimeImmutable('2026-10-29T12:00:00.000000+00:00');

        $changed = $submission->reviewerSave('second-super-admin', $laterSave);

        self::assertFalse($changed, 'an approval that already happened is not a transition');
        self::assertSame('APPROVED', $submission->status()->value);
        self::assertSame('original-reviewer', $submission->reviewedBy(), 'the original reviewer keeps the credit');
        self::assertEquals($decidedAt, $submission->reviewedAt());
        self::assertEquals($decidedAt, $submission->updatedAt(), 'an unchanged approved row is not rewritten');
    }

    public function testAnOwnerModificationReopensAnApprovedSubmission(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );
        $decidedAt = new \DateTimeImmutable('2026-10-29T11:00:00.000000+00:00');
        $submission->decide(CatalogDecision::Approve, 'reviewer-1', null, $decidedAt);
        $modifiedAt = new \DateTimeImmutable('2026-10-29T12:00:00.000000+00:00');

        $changed = $submission->ownerModified($modifiedAt);

        self::assertTrue($changed);
        self::assertSame('PENDING_APPROVAL', $submission->status()->value);
        self::assertSame('admin-owner', $submission->submittedBy(), 'the original owner stays recorded');
        self::assertNull($submission->reviewedBy());
        self::assertNull($submission->reviewedAt());
        self::assertNull($submission->rejectionReason());
        self::assertEquals($modifiedAt, $submission->updatedAt());
    }

    public function testARejectionStoresTheReasonWithoutSurroundingWhitespace(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );

        $submission->decide(
            CatalogDecision::Reject,
            'reviewer-1',
            '  Fix the photos.  ',
            new \DateTimeImmutable('2026-10-29T11:00:00.000000+00:00'),
        );

        self::assertSame('Fix the photos.', $submission->rejectionReason(), 'the stored reason must not keep padding');
    }

    public function testAnOwnerModificationLeavesARejectedSubmissionRejected(): void
    {
        $submission = CatalogSubmission::create(
            CatalogSubmissionKind::Product,
            'it-submission-product',
            'admin-owner',
            new \DateTimeImmutable(self::NOW),
        );
        $decidedAt = new \DateTimeImmutable('2026-10-29T11:00:00.000000+00:00');
        $submission->decide(CatalogDecision::Reject, 'reviewer-1', 'Fix the description.', $decidedAt);
        $modifiedAt = new \DateTimeImmutable('2026-10-29T12:00:00.000000+00:00');

        $changed = $submission->ownerModified($modifiedAt);

        self::assertFalse($changed, 'only an approved record is pulled back into review');
        self::assertSame('REJECTED', $submission->status()->value);
        self::assertSame('reviewer-1', $submission->reviewedBy());
        self::assertSame('Fix the description.', $submission->rejectionReason());
        self::assertEquals($decidedAt, $submission->updatedAt(), 'an unchanged rejected row is not rewritten');
    }
}

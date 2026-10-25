<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * R26 — an approval transition the prompt does not list is refused, and the refusal names both
 * ends so the edge can answer 409 without guessing what happened.
 */
final class ApprovalTransitionRefusedException extends \DomainException
{
    public static function create(ApprovalStatus $from, ApprovalStatus $to): self
    {
        return new self(sprintf(
            'The approval status cannot move from %s to %s.',
            $from->value,
            $to->value,
        ));
    }

    public static function notPending(string $slug, ApprovalStatus $current): self
    {
        return new self(sprintf(
            'The submission "%s" is %s: only a PENDING_APPROVAL submission can be reviewed.',
            $slug,
            $current->value,
        ));
    }

    public static function notRejected(string $slug, ApprovalStatus $current): self
    {
        return new self(sprintf(
            'The submission "%s" is %s: only a REJECTED submission can be resubmitted.',
            $slug,
            $current->value,
        ));
    }

    public static function unapprovedCannotBePublished(string $slug, ApprovalStatus $current): self
    {
        return new self(sprintf(
            'The product "%s" is %s: only an APPROVED product can be published.',
            $slug,
            $current->value,
        ));
    }

    public static function approvedCannotBeSubmitted(string $slug): self
    {
        return new self(sprintf(
            'The submission "%s" is already APPROVED: only a modification returns it to review.',
            $slug,
        ));
    }
}

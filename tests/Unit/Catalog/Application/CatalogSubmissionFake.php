<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\CatalogSubmissionCreator;
use App\Catalog\Application\Port\CatalogSubmissionDecider;
use App\Catalog\Application\Port\CatalogSubmissionDeleter;
use App\Catalog\Application\Port\CatalogSubmissionOwnerEditor;
use App\Catalog\Application\Port\CatalogSubmissionReviewer;
use App\Catalog\Application\Port\CatalogSubmissionSubmitter;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Repository\CatalogSubmissionRepository;

/**
 * Inline fake (TDD-008) of the approval store and its read port.
 *
 * It records every call so a handler test can assert which transition it delegates, and it
 * answers the ownership policy from the same state the store would lock, so a unit test never
 * needs a database or a second approval mechanism.
 */
final class CatalogSubmissionFake implements CatalogSubmissionCreator, CatalogSubmissionDecider, CatalogSubmissionDeleter, CatalogSubmissionOwnerEditor, CatalogSubmissionReviewer, CatalogSubmissionSubmitter, CatalogSubmissionRepository
{
    /** @var list<array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    /** @var array<string, CatalogSubmission> */
    private array $submissions = [];

    /** The row {@see SubmissionAccessPolicy} reads when the test does not pin a slug. */
    private ?CatalogSubmission $any = null;

    public function seed(CatalogSubmissionKind $kind, string $slug, ApprovalStatus $status, ?string $owner): void
    {
        $now = new \DateTimeImmutable('2026-10-29 12:00:00');
        $approved = ApprovalStatus::Approved === $status;

        $this->submissions[$this->key($kind, $slug)] = CatalogSubmission::reconstitute(
            $kind,
            $slug,
            $status,
            $owner,
            null === $owner ? null : $now,
            $approved ? 'reviewer' : null,
            $approved ? $now : null,
            null,
            $now,
        );
    }

    /** Answers the policy for any slug, for tests that exercise the content write only. */
    public function any(ApprovalStatus $status = ApprovalStatus::PendingApproval, ?string $owner = null): void
    {
        $this->seed(CatalogSubmissionKind::Category, 'any', $status, $owner);
        $this->any = $this->submissions[$this->key(CatalogSubmissionKind::Category, 'any')];
    }

    public function create(CatalogSubmissionKind $kind, string $slug, string $actorId): CatalogSubmission
    {
        $this->record(__FUNCTION__, [$kind, $slug, $actorId]);
        $submission = CatalogSubmission::create($kind, $slug, $actorId, new \DateTimeImmutable());
        $this->submissions[$this->key($kind, $slug)] = $submission;

        return $submission;
    }

    public function createApproved(CatalogSubmissionKind $kind, string $slug, string $actorId): void
    {
        $this->create($kind, $slug, $actorId);
        $this->reviewerSave($kind, $slug, $actorId);
    }

    public function import(CatalogSubmission $submission): bool
    {
        $this->record(__FUNCTION__, [$submission]);
        $key = $this->key($submission->kind(), $submission->slug());
        if (isset($this->submissions[$key])) {
            return false;
        }

        $this->submissions[$key] = $submission;

        return true;
    }

    public function find(CatalogSubmissionKind $kind, string $slug): ?CatalogSubmission
    {
        return $this->submissions[$this->key($kind, $slug)] ?? $this->any;
    }

    public function submit(
        CatalogSubmissionKind $kind,
        string $slug,
        string $actorId,
        bool $actorIsSuperAdmin,
    ): CatalogSubmission {
        $this->record(__FUNCTION__, [$kind, $slug, $actorId, $actorIsSuperAdmin]);

        return $this->submissions[$this->key($kind, $slug)]
            ?? CatalogSubmission::create($kind, $slug, $actorId, new \DateTimeImmutable());
    }

    public function decide(
        CatalogSubmissionKind $kind,
        string $slug,
        CatalogDecision $decision,
        string $reviewerId,
        ?string $reason = null,
    ): CatalogSubmission {
        $this->record(__FUNCTION__, [$kind, $slug, $decision, $reviewerId, $reason]);

        return $this->submissions[$this->key($kind, $slug)] ?? CatalogSubmission::create($kind, $slug, $reviewerId, new \DateTimeImmutable());
    }

    public function reviewerSave(
        CatalogSubmissionKind $kind,
        string $slug,
        string $actorId,
    ): CatalogSubmission {
        $this->record(__FUNCTION__, [$kind, $slug, $actorId]);

        return $this->submissions[$this->key($kind, $slug)] ?? CatalogSubmission::create($kind, $slug, $actorId, new \DateTimeImmutable());
    }

    public function ownerModified(CatalogSubmissionKind $kind, string $slug): CatalogSubmission
    {
        $this->record(__FUNCTION__, [$kind, $slug]);

        return $this->submissions[$this->key($kind, $slug)] ?? CatalogSubmission::create($kind, $slug, 'system', new \DateTimeImmutable());
    }

    public function renameSubject(CatalogSubmissionKind $kind, string $fromSlug, string $toSlug, string $actorId): void
    {
        $this->record(__FUNCTION__, [$kind, $fromSlug, $toSlug, $actorId]);

        if (null !== $this->find($kind, $fromSlug)) {
            $this->move($kind, $fromSlug, $toSlug);
        } else {
            $this->create($kind, $toSlug, $actorId);
        }

        $this->reviewerSave($kind, $toSlug, $actorId);
    }

    public function move(CatalogSubmissionKind $kind, string $fromSlug, string $toSlug): ?CatalogSubmission
    {
        $this->record(__FUNCTION__, [$kind, $fromSlug, $toSlug]);

        $source = $this->key($kind, $fromSlug);
        if (!isset($this->submissions[$source])) {
            return null;
        }

        $submission = $this->submissions[$source];
        unset($this->submissions[$source]);
        $this->submissions[$this->key($kind, $toSlug)] = CatalogSubmission::reconstitute(
            $submission->kind(),
            $toSlug,
            $submission->status(),
            $submission->submittedBy(),
            $submission->submittedAt(),
            $submission->reviewedBy(),
            $submission->reviewedAt(),
            $submission->rejectionReason(),
            new \DateTimeImmutable(),
        );

        return $this->submissions[$this->key($kind, $toSlug)];
    }

    public function delete(CatalogSubmissionKind $kind, string $slug): void
    {
        $this->record(__FUNCTION__, [$kind, $slug]);
        unset($this->submissions[$this->key($kind, $slug)]);
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_map(static fn (array $call): string => $call['method'], $this->calls);
    }

    /** @return array{method: string, args: array<int, mixed>}|null */
    public function lastCall(): ?array
    {
        return [] === $this->calls ? null : $this->calls[count($this->calls) - 1];
    }

    /** @param list<mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }

    private function key(CatalogSubmissionKind $kind, string $slug): string
    {
        return $kind->value.':'.$slug;
    }
}

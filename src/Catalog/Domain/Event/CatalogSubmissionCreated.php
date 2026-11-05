<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

/**
 * spec-run-15 WP2 (R3/R4) — a product or category got its PENDING_APPROVAL submission.
 *
 * The event mirrors `catalog_submission` so the outbox relay can publish a transition without
 * reading the store again. It carries the resulting state, not a diff, because a subscriber that
 * missed an earlier event still needs enough to project the same row.
 */
final readonly class CatalogSubmissionCreated
{
    public function __construct(
        public string $eventId,
        public string $kind,
        public string $slug,
        public string $actorId,
        public string $status,
        public ?string $reason,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}

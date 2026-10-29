<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

/**
 * spec-run-15 WP2 (R3) — an owner edit pulled an approved record back into review.
 *
 * No audit action is specified for a reopen, so this event is the transition's only trail. The
 * store has no actor parameter here; the recorded submitter is the closest truthful attribution,
 * with a system marker for a row that predates the workflow.
 */
final readonly class CatalogSubmissionReopened
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

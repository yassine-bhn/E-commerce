<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

/**
 * spec-run-15 WP2 (R3/R5) — the owner put a pending submission (back) in front of a reviewer.
 */
final readonly class CatalogSubmissionSubmitted
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

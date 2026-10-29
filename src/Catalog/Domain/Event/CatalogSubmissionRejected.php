<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

/**
 * spec-run-15 WP2 (R3/R6) — a reviewer rejected a pending submission and said why.
 */
final readonly class CatalogSubmissionRejected
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

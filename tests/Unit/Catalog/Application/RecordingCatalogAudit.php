<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\CatalogAuditPort;

/**
 * Inline fake (TDD-008) of the audit port: records what a handler appended so a unit test can
 * assert the trail without a database, and keeps the assertion about "no trace on refusal"
 * meaningful because a refused command never reaches the recorder.
 */
final class RecordingCatalogAudit implements CatalogAuditPort
{
    /** @var list<array{action: string, target: string, slug: string, actor: string, reason: ?string}> */
    public array $records = [];

    public function record(
        string $action,
        string $targetType,
        string $targetSlug,
        string $actorId,
        ?string $reason = null,
    ): void {
        $this->records[] = [
            'action' => $action,
            'target' => $targetType,
            'slug' => $targetSlug,
            'actor' => $actorId,
            'reason' => $reason,
        ];
    }

    /** @return array{action: string, target: string, slug: string, actor: string, reason: ?string}|null */
    public function last(): ?array
    {
        return [] === $this->records ? null : $this->records[count($this->records) - 1];
    }
}

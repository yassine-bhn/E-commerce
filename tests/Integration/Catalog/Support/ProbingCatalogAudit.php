<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Support;

use App\Catalog\Application\Port\CatalogAuditPort;
use Doctrine\DBAL\Connection;

/**
 * The real audit writer, with R5's question answered at the only moment it can be answered: while
 * the audit row is being written, inside the deciding transaction.
 *
 * A test that wraps the handler in a transaction of its own proves the test's transaction, not the
 * handler's — DBAL nests the second one as a savepoint, so removing `transactional()` from the
 * handler leaves every assertion green. Probing here closes that hole: with the handler's
 * transaction gone, the state UPDATE is already committed when this runs, and the reader below
 * sees `APPROVED`.
 *
 * Two views are captured because isolation is only meaningful next to the write it isolates: the
 * reader (a second connection, what the storefront and the queue see) and the writer (its own
 * uncommitted work, which proves the change has happened and only the commit is missing).
 */
final class ProbingCatalogAudit implements CatalogAuditPort
{
    /** @var list<array{reader: string, readerRows: int, writer: string, writerRows: int}> */
    public array $atWrite = [];

    public function __construct(
        private readonly CatalogAuditPort $inner,
        private readonly Connection $reader,
        private readonly Connection $writer,
        private readonly string $targetType,
        private readonly string $table,
        private readonly string $slug,
    ) {
    }

    public function record(
        string $action,
        string $targetType,
        string $targetSlug,
        string $actorId,
        ?string $reason = null,
    ): void {
        $this->inner->record($action, $targetType, $targetSlug, $actorId, $reason);

        $this->atWrite[] = [
            'reader' => $this->status($this->reader),
            'readerRows' => $this->rows($this->reader),
            'writer' => $this->status($this->writer),
            'writerRows' => $this->rows($this->writer),
        ];
    }

    private function status(Connection $connection): string
    {
        return (string) $connection->fetchOne(
            sprintf('SELECT approval_status FROM %s WHERE slug = :slug', $this->table),
            ['slug' => $this->slug],
        );
    }

    private function rows(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            'SELECT count(*) FROM catalog_audit_log WHERE target_type = :type AND target_slug = :slug',
            ['type' => $this->targetType, 'slug' => $this->slug],
        );
    }
}

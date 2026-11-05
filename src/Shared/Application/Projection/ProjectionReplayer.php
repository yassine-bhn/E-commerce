<?php

declare(strict_types=1);

namespace App\Shared\Application\Projection;

/**
 * One named, source-based projection rebuild (spec-run-15 R30/R31, H6, MIG-002).
 *
 * A replayer reads state-based write tables, never an event store, and must do its writes in one
 * transaction. It must not write `outbox` or `processed_events` rows: a replay repairs a read model,
 * it is not a command and emits no domain event. It must also leave projection rows whose source is
 * gone in place and report them, so an operator can decide what to do with the orphans.
 */
interface ProjectionReplayer
{
    /** Stable console name, for example `catalog-product-view`. */
    public function name(): string;

    /** The state-based write table the projection is rebuilt from. */
    public function source(): string;

    /**
     * @param bool $dryRun when true, report the work without writing anything
     *
     * @return array{written: int, orphans: int, missingView?: int} rows written (or that would be
     *                                                              written), view rows whose source row
     *                                                              no longer exists, and optional source
     *                                                              rows with no view row
     */
    public function replay(bool $dryRun): array;
}

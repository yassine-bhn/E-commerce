# ADR-0017 — Projection replay and its registry

Status: accepted. Relies on: AGENTS.md 6/8, MIG-002 (`.agents/rules/migrations-performance.md`), spec R30/R31/R32 (`spec-run-15/spec.md`), H6 (`spec-run-15/context.md`), ADR-0002.

## Context

`bin/console list --raw` had no projection/replay command, so MIG-002's rule — rebuild read-model data with `bin/console projection:replay <name>`, never with a data-writing migration — was unenforceable. Only the minimal `catalog_product_view` had a projector (`ProjectCatalogProductView`). `docs/system-design/DATABASE-ARCHITECTURE.md` claimed `storefront_product_view` was rebuilt by replay, but no such command existed.

The read models are state-based: `catalog_products` is the write table for `catalog_product_view`; there is no event store to replay (AGENTS.md 8). A replay therefore has to copy from state tables, not rehydrate aggregates. A data-writing migration would re-run on every `up()` and silently re-apply a decision an operator later reversed (the same reasoning as ADR-0012 and the approval backfill), so the repair belongs in an explicit operator command.

The command surface also has to grow: H1 and H3 add `catalog-submission` and `inventory-stock` replays in other work packages. A command with a `match` on the name would need editing for every new projection and would let the registered set drift from the documentation.

## Decision

**`projection:replay <name>` is a single console command backed by a tagged `ProjectionReplayer` registry.**

- **Port.** `App\Shared\Application\Projection\ProjectionReplayer` declares `name()`, `source()` and `replay(bool $dryRun): array{written: int, orphans: int}`. `Application` owns the contract; the interface is the only thing the command knows about a projection.
- **Registry.** `App\Shared\Infrastructure\Projection\ProjectionRegistry` collects `iterable<ProjectionReplayer>` from the `app.projection_replayer` tag (wired in `config/services.yaml`). Names are unique — a duplicate registration throws — and sorted, so the unknown-name error always lists the same supported set. Shared never names a context; each context tags its own replayer.
- **Command.** `App\Shared\Infrastructure\Console\ProjectionReplayCommand` requires `name`, accepts `--dry-run`, returns `Command::FAILURE` for an unknown name and prints the supported names. A missing name is a Symfony usage error and also exits non-zero. The command prints the replayer's declared source, the rows written and the orphan count.
- **Replayer.** `ReplayCatalogProductView` rebuilds `catalog_product_view` from `catalog_products` with one `INSERT … SELECT … ON CONFLICT (id) DO UPDATE` statement. The `WHERE … IS DISTINCT FROM` clause means unchanged rows are not written, so the reported count is real work and a second run reports zero. A corrupted row is overwritten and a missing row is inserted. `catalog_product_view` has no FK to `catalog_products`, so it is the source of truth for the orphan direction.
- **Transaction and side effects.** Every `replay()` runs inside `Connection::transactional(...)`. A replay writes only its projection table: it writes no `outbox` row and no `processed_events` row, because it is not a command and emits no domain event.
- **Dry run.** `--dry-run` passes `true` to the replayer. `ReplayCatalogProductView` then runs only the count queries (pending rows and orphans) inside a transaction and writes nothing.
- **Orphans.** A view row whose source row is gone is counted and reported, never deleted. Removing an orphan is a data decision with business consequences (the row may still be visible to a reader), so the replay leaves it to the operator.
- **Names.** `catalog-product-view` ships here. `catalog-submission` (WP2) and `inventory-stock` (WP4) register through the same tag and follow the same contract.

## Rejected alternatives

- **An event-sourced replay.** Rejected by AGENTS.md 8 and the spec's "No event sourcing" boundary: there is no event store, and introducing one to rebuild two tables would add a second write model for no requirement.
- **A branch per projection in the command.** Rejected because it makes `src/Shared` name every context (deptrac forbids it) and turns each new replay into an edit of a shared command. The tag keeps the dependency pointing at Shared.
- **A data-writing migration.** Rejected by MIG-002: migrations must not rewrite read-model data, because a rollback and re-run would re-apply a state an operator may have deliberately changed.
- **Deleting orphan view rows during replay.** Rejected because the projection cannot distinguish "source row deleted on purpose" from "source row missing because a migration or restore is incomplete"; reporting preserves that distinction for a human.
- **A `--apply` flag instead of `--dry-run`.** Rejected for consistency with the replay rule in MIG-002 and the spec: a replay is the explicit act, so running it is the default and `--dry-run` is the safety preview. (The operator backfills keep their own dry-run-by-default convention because they migrate legacy data.)
- **Returning a `void` from `replay()` and logging inside the replayer.** Rejected because the command must report rows written and orphans uniformly; a structured return keeps output formatting in one place.

## Accepted trade-offs (non-properties)

- **No cross-run checksum.** The replay reports work done on this run, not a durable "last replayed at" marker; a second run is the idempotency proof.
- **No orphan cleanup.** Orphans accumulate until an operator deletes them or a future cleanup command is specified.
- **One statement per projection.** A projection with multiple tables would need several statements in the same transaction; the contract allows that, the command only sees the totals.
- **No automatic search reindex.** `app:storefront:index-search` remains a separate documented step (spec open question).

## Consequences and follow-ups

- **Positive:** MIG-002 has an enforceable command; a corrupted or missing `catalog_product_view` row is repairable without SQL; the registry makes the three required names a wiring change rather than a command change; replay side effects are constrained by contract and test.
- **Negative:** each replayer must keep its own pending/orphan count queries in sync with its upsert; a drifted count would misreport (not corrupt) a run.
- **If** a projection needs to rebuild from several source tables, extend that replayer and keep the single transaction; do not add a second command.
- **If** orphan cleanup becomes required, add a separate explicit command with its own dry-run and audit trail; do not fold deletion into this replay.

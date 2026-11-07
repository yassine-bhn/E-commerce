# ADR-0014 — Catalog approval write model

Status: accepted; **amended by ADR-0018 (solid Task 2.5)** — the single store remains the only `catalog_submission` writer, exposed as six narrow per-transition ports (Creator/Submitter/Decider/Reviewer/OwnerEditor/Deleter) plus the read repository, all over the one `DbalCatalogSubmissionStore` adapter, which keeps its `Connection::transactional()` carve-out. Relies on: AGENTS.md 2/4/5/8, CMD-002, LAY-003, EVT-002, MIG-002, spec R1/R2/R3/R12/R31 (`spec-run-15/spec.md`), H1 (`spec-run-15/context.md`), ADR-0012, ADR-0017.

## Context

Approval used to live only in the two denormalized view tables: `storefront_product_view` and `storefront_category` carried `approval_status`, the submitter and the reviewer columns, and the command handlers plus `AdminProductController` updated them directly. There was no write-side owner, so:

- any code path could set any status, and nothing refused an illegal transition (approve an approved row, reject without a reason, resubmit an approved row);
- a projection rebuild had no authoritative row to restore, so a replay either had to invent state from the view or could not run at all;
- a partial failure could leave the status, the audit trail and the publication flag disagreeing, because the three writes were not owned by one adapter;
- no transition event reached the outbox, so other contexts could not react to a decision.

R1 makes `catalog_submission` the sole authority and the six view columns a projection. R2 requires exactly one store to write the table and one projector to write the views. R3 requires the submission, exactly one outbox event, the audit entry and the synchronous projection to commit together, with nothing persisted on failure. R12 adds a replay that restores both views and reports both missing directions.

## Decision

**`catalog_submission` is a state-based approval write model, owned by one application port and one DBAL adapter, projected synchronously into the two storefront views and rebuildable from its source table.**

- **Table.** `catalog_submission(kind, slug)` is the primary key; `kind` is `products` or `categories`, `approval_status` is `PENDING_APPROVAL`/`APPROVED`/`REJECTED`, the five workflow moments are nullable timestamps/ids/reason, and `updated_at` is `NOT NULL`. CHECK constraints keep `kind` and `approval_status` to the values the PHP enums actually produce. The migration is schema-only (MIG-002): legacy rows are seeded by the WP3 backfill command, not by a migration that would re-run on every `up()`.
- **Aggregate.** `CatalogSubmission` is a plain PHP state machine with `create`, `submit`, `decide`, `reviewerSave`, `ownerModified` and `reconstitute`. It uses the existing `ApprovalStatus`, `CatalogDecision` and `ApprovalTransitionRefusedException`; it has no DBAL or Doctrine. `reviewerSave` and `ownerModified` return whether a real transition happened, so the store can skip the write, the event and the audit row when the answer is no. `reviewerSave` on an approved row preserves the original reviewer; `ownerModified` only reopens an approved row and clears its review.
- **Store.** `CatalogSubmissionStore` is the port WP3 injects. `DbalCatalogSubmissionStore` is its only implementation and the only class that inserts, updates or deletes `catalog_submission`; it also implements the domain `CatalogSubmissionRepository`. Every mutating method runs one `Connection::transactional(...)` block: lock the row with `SELECT … FOR UPDATE` (except on create), apply the model transition, persist the row with `updated_at`, append exactly one transition event through `OutboxPort`, write the audit entry through `CatalogAuditPort` when the spec names one, then project the six columns. Any failure rolls all five writes back.
- **Ownership.** `submit` accepts `actorIsSuperAdmin`. A non-super-admin must equal `submitted_by`, otherwise `CatalogSubmissionNotOwnedException::for()` is thrown before any write; a Super Admin may submit on the owner's behalf.
- **Events.** One readonly class per transition (`Created`, `Submitted`, `Resubmitted`, `Approved`, `Rejected`, `Reopened`), each carrying a UUID v7 `eventId`, the kind value, the slug, the actor, the resulting status, an optional reason and `occurredOn`. They are appended with `aggregate_type = 'catalog_submission'` and `aggregate_id = '<kind>:<slug>'`; the existing outbox serializer JSON-encodes them, so they stay free of resources and closures. Events describe transitions that really happened: a no-op reviewer save or owner edit appends nothing.
- **Projector.** `ProjectStorefrontApproval::project()` updates exactly the six approval columns in the view selected by `CatalogSubmissionKind::table()`, keyed by slug. It never inserts a missing row and never touches `published` or `hidden`: approval and publication are independent decisions (ADR-0012, spec R6). It runs on the store's connection inside the store's transaction.
- **Replay.** `ReplayCatalogSubmission` implements the WP1 `ProjectionReplayer` contract as `catalog-submission` from `catalog_submission`. It copies the six columns into both views with `UPDATE … FROM … WHERE … IS DISTINCT FROM`, so a second run writes zero. It reports `orphans` (a view row with no submission) and `missingView` (a submission with no view row), never inserts or deletes a row, and leaves `published`/`hidden` alone. The whole run is one transaction with no `outbox` or `processed_events` row. `ProjectionReplayCommand` treats the extra key as optional and prints it when non-zero, so older two-key replayers keep working.

## Rejected alternatives

- **Event sourcing the submission.** Rejected by AGENTS.md 8 and the spec's "No event sourcing" boundary. The transitions are few and the current state is all a reader needs; an event store would add a second write model and a rehydration path with no requirement behind it.
- **Keep approval in the view tables and add constraints there.** Rejected because the view is a projection: a rebuild loses the authority, a missing content row has nowhere to store a decision, and the CHECK constraints would still not encode which transitions are legal. R1 explicitly makes the view a projection.
- **A Doctrine ORM entity and repository.** Rejected because the store must merge its writes with raw outbox/audit/projection SQL in one transaction and the catalog write side is DBAL. An entity would add mapping and identity-map concerns without changing behaviour.
- **A second repository adapter that writes the table.** Rejected by R2: two writers make the transition rules and the transaction boundary negotiable. The domain repository interface is implemented by the same adapter so readers can depend on the narrow contract without creating a second writer.
- **Event-driven projection instead of the synchronous call.** Rejected by R3: the requirement is that the view shows the stored state when the command returns. An async subscriber can lag, drop or replay, and a rolled-back command could still have projected.
- **Auditing the reopen.** Rejected because the spec names no reopen audit action; inventing one would add vocabulary no reader expects. The outbox event still records the transition for whoever needs it.
- **Inserting a missing view row during projection or replay.** Rejected because the projector cannot know the content columns of a product or category. Inserting would fabricate catalog content; reporting the missing view leaves the decision to the owner.
- **A data-writing migration to seed legacy rows.** Rejected by MIG-002 and ADR-0012's reasoning: a rollback and re-run would silently re-apply an operator decision. WP3's explicit backfill command owns that migration.
- **Updating `published`/`hidden` when a row is approved.** Rejected because approval and publication are separate decisions (spec R6); coupling them would publish a product the owner has not released and make a reopen hide live content.

## Accepted trade-offs (non-properties)

- **One transition per call, one event per call.** A retried command appends a second event and a second audit row; exactly-once is the caller's responsibility, and the trail is an action log, not a session ledger.
- **No event-sourced history.** Rebuilding past states is impossible; the audit trail and outbox are the only historical record, and `catalog_submission` stores only the latest state.
- **Reopen has no actor parameter.** The brief fixes `ownerModified(kind, slug)` without an actor. The store therefore attributes `CatalogSubmissionReopened` to the current `submitted_by`, falling back to `system:catalog-submission` when the row has no submitter. A true "who reopened this" audit needs an API change in a later work package.
- **No database-enforced transition matrix.** The CHECK constraints restrict values, not legal edges; the aggregate enforces the edges and the store is the only writer. A direct SQL client can still move a row to an illegal edge.
- **Slug is the join key.** Renaming a product or category would require the rename path to carry the submission with it; WP3 owns that path. Until then a rename leaves the submission under the old slug and shows up as an orphan/missing view.
- **The replay repairs the six columns only.** It cannot recreate a deleted content row, so `missingView` is a report, not a fix.

## Consequences and follow-ups

- **Positive:** R1/R2/R3 have one enforceable owner; illegal transitions fail before any write; a command either commits the submission, one event, the audit row and the view together or commits none of them; the view is now a genuine projection with an authoritative replay; the store's fixed method signatures let WP3 rewire handlers without touching persistence.
- **Negative:** two representations of approval now exist, so any future writer that bypasses the store silently desynchronizes them; the backfill and the handler rewiring are still pending in WP3; the replay's pending and orphan queries must stay in sync with the six columns it copies.
- **If** a future requirement adds an actor to `ownerModified`, extend the port signature and the `CatalogSubmissionReopened` payload rather than defaulting to the submitter; the current fallback is documented as a gap, not a property.
- **If** approval state must survive a content-row deletion, keep the submission row until WP3's delete transaction removes both in one transaction; do not cascade at the database level, because the audit trail outlives the content.

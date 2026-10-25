# ADR-0012 — Catalog audit trail

Status: accepted. Relies on: AGENTS.md 2/4/6, CMD-002, CMD-005, LAY-003/LAY-004, spec R39/R40/R41/R42/R43/R44/R45 (`spec-run-8/spec.md`), ADR-0001, ADR-0007.

## Context

The approval workflow must record every significant catalog action — submission creation, first submission and resubmission, Super Admin modification, approval, rejection and deletion — with the acting user, the affected record, the timestamp and, for a rejection, the reason (R39–R43). Product publication changes are audited as well, although the spec does not demand it. R44 adds the constraint that auditing "reuses the audit infrastructure already present in the codebase instead of a parallel mechanism", and R45 requires the audit write to commit in the same transaction as the state change it describes.

The only audit infrastructure that exists is `identity_audit_log` (`migrations/Version20261017000004.php`): `id UUID`, `action VARCHAR(64)`, `target_user_id UUID NOT NULL`, `actor_admin_id VARCHAR(64)`, `reason TEXT NOT NULL`, `created_at TIMESTAMP(6) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP`, indexed on `target_user_id`. It is written by Identity command handlers through DBAL (`src/Identity/Application/CommandHandler/ChangeCustomerRoleHandler.php`) and read back per user by `GET /api/v1/admin/customers/{id}/audit` (`src/Identity/Infrastructure/Http/AdminCustomerController.php`), which filters `WHERE target_user_id = :id`.

Catalog records are not keyed by user. There is no full product write model: `storefront_product_view` and `storefront_category` are the denormalized tables and their identity is `slug` (`spec-run-8/spec.md`, "What already exists"). A product or category target therefore has no UUID to put in `target_user_id`, and `actor_admin_id` (already `VARCHAR(64)`, not a FK) cannot stand in for it without making the trail claim that a product is a user.

Layering pushes the same way. `Catalog.Application` may depend only on `Catalog.Domain`, `Shared.Domain` and `Shared.Application` (`deptrac.yaml`), so a handler cannot name Identity's table or writer; `Shared.Infrastructure` cannot name any context, so a shared adapter cannot know catalog's table or verbs. The spec left the placement to a PATTERN-gate ADR and suggested the default `src/Catalog/Infrastructure/Audit/**` (`spec-run-8/spec.md`, WP7).

## Decision

**Catalog owns an append-only audit table, `catalog_audit_log`, written through one port in the application layer and one DBAL adapter in `Catalog.Infrastructure`.**

- **Port.** `CatalogAuditPort::record(action, targetType, targetSlug, actorId, reason = null)` (`src/Catalog/Application/Port/CatalogAuditPort.php`). It is the only way a catalog handler writes a trail entry, so no handler names the table and the storage choice stays replaceable.
- **Adapter.** `DbalCatalogAuditWriter` (`src/Catalog/Infrastructure/Audit/DbalCatalogAuditWriter.php`) inserts through the `Doctrine\DBAL\Connection`, using `Uuid::v7()` for `id` and `DateTimeImmutable` with microseconds and offset for `created_at`. It is wired as the port's only implementation in `config/services.yaml` (`spec-run-8 (ADR-0012)`).
- **Transaction.** The adapter shares the connection the command handler already opened; every write path that records an action wraps its state change and its audit call in `Connection::transactional(...)` — the seven command handlers that write the trail (`CreateCategory`, `AddCategorySubLevel`, `ReplaceCategoryTree`, `SubmitCatalogSubmission`, `DecideCatalogSubmission`, `SetProductPublication`, `DeleteCategory`) and the three controller-side writes (`AdminProductController` create and update, `AdminCategoryController::deleteProduct`). A refused action throws before the insert, so it leaves no row (R45).
- **Table.** `catalog_audit_log(id UUID PK, action VARCHAR(64), target_type VARCHAR(16), target_slug VARCHAR(128), actor_id VARCHAR(64), reason TEXT NULL, created_at TIMESTAMP(6) WITH TIME ZONE)`, indexed on `(target_type, target_slug)`, created by `migrations/Version20261023000000.php`. It mirrors `identity_audit_log`'s shape — same `id`/`action`/`actor`/`reason`/`created_at` semantics — while replacing the UUID user target with `target_type` + `target_slug`.
- **Vocabulary.** `target_type` is `product` or `category`, derived from `CatalogSubmissionKind::auditTarget()` so call sites cannot invent a third value. `action` is one of `CATALOG_SUBMISSION_CREATED`, `CATALOG_SUBMISSION_SUBMITTED`, `CATALOG_SUBMISSION_RESUBMITTED`, `CATALOG_SUBMISSION_APPROVED`, `CATALOG_SUBMISSION_REJECTED`, `CATALOG_PRODUCT_UPDATED`, `CATALOG_CATEGORY_UPDATED`, `CATALOG_PRODUCT_PUBLISHED`, `CATALOG_PRODUCT_UNPUBLISHED`, `CATALOG_PRODUCT_DELETED`, `CATALOG_CATEGORY_DELETED`; `CatalogDecision::auditAction()` maps approve/reject, so the decision and its action cannot drift apart.
- **Append-only by convention.** Nothing under `src/` issues `UPDATE` or `DELETE` against the table. The only deletes are test fixtures removing the rows they created (`tests/Functional/AdminCatalogApprovalWorkflowTest.php`).
- **No read surface.** No endpoint exposes catalog audit in this scope; the rows are written for forensics and for later review surfaces, and the tests read them directly through DBAL.

## Rejected alternatives

- **Reuse `identity_audit_log` with the actor or a synthetic UUID as target.** Rejected because `target_user_id` is `UUID NOT NULL` and its consumer filters by user id: a product slug cannot be stored there, and storing the actor instead would answer "which products did this admin touch?" while breaking "what happened to this product?", and would make the existing per-customer audit endpoint report catalog events as account events.
- **Extend `identity_audit_log` with nullable `target_type`/`target_slug`.** Rejected because it gives one table two owning contexts: Catalog would write a schema Identity owns (deptrac forbids `Catalog.Application → Identity.*`, and going through `Identity.Infrastructure` only moves the coupling), every Identity reader would need a `WHERE target_user_id IS NOT NULL` guard, and the next context would repeat the same migration. A shared table is not "the existing infrastructure" once it is polymorphic; it is a new one placed in Identity's schema.
- **A generic audit port in `Shared.Application` with the adapter in `Shared.Infrastructure`.** Rejected because `Shared.Infrastructure` may not name any context (`deptrac.yaml`), so the shared adapter could not know the catalog table or its verbs; a context-neutral port would have to be configured per context anyway, adding an abstraction with one consumer.
- **A domain event plus an asynchronous audit consumer.** Rejected by R45: the audit row must commit with the change, and an async consumer can drop, reorder or replay entries; worse, a rolled-back action could still have published an event.
- **An ORM entity and repository for the audit row.** Rejected because the catalog write paths are DBAL and the handler is the transaction boundary; a mapped entity would add mapping and identity-map concerns without changing behaviour, and could not join a caller-opened DBAL transaction as directly.
- **Writing the audit row in the controller for the two legacy write paths.** Rejected because the legacy endpoints already write inside `Connection::transactional`; the audit call sits in that same closure, so a policy placed in the controller would be skipped by the command bus paths and duplicate the rule.
- **A foreign key to `storefront_product_view.slug` / `storefront_category.slug`.** Rejected because deletions must be audited (R42): the target no longer exists when the delete row is written, and a cascade would erase the trail it was meant to preserve.

## Accepted trade-offs (non-properties)

This trail deliberately does **not** provide:

- **Referential integrity to the target.** `target_slug` is a plain string; renaming or deleting the record leaves earlier rows pointing at the old slug, which is the intent (the trail describes a moment, not a live join). Reconciling a rename requires reading the corresponding `CATALOG_*_UPDATED` row.
- **Database-enforced immutability.** There is no trigger or revoked privilege on `UPDATE`/`DELETE`; append-only is enforced by code review and by the absence of any such statement in `src/`. A direct SQL client can still rewrite history.
- **Storage-level idempotency.** A retried command (same intent, second execution) appends a second row. Exactly-once is the caller's responsibility, and the table is an action log, not a session ledger.
- **An enum or CHECK on `action`.** The column is `VARCHAR(64)` so adding a verb needs no migration; a misspelled verb is caught by the tests that assert the exact action, not by the database.
- **A foreign key on `actor_id`.** It stays `VARCHAR(64)` because some paths legitimately have no human actor and record `system` (`$command->actorId ?? 'system'` in the category handlers), which an FK to `customer_profile` would reject.
- **A read API or retention policy.** No endpoint serves the table and no job archives or prunes it, so it grows with every catalog mutation until a review surface or retention task is specified.
- **Personal data beyond the actor id.** Rows carry a slug, an admin id and an optional reason; they carry no customer data, so the trail is not part of any erasure path.

## Consequences and follow-ups

- **Positive:** R44 is satisfied without cross-context coupling — the shape and conventions of `identity_audit_log` are reused, ownership is not; the port keeps handlers free of SQL and lets the adapter be replaced; the write joins the caller's transaction for free; deletion is auditable because nothing references the deleted row.
- **Negative:** two audit tables now exist, so any future global audit feed has to read both with different keys; the trail has no database-level guard against modification; the table has no reader in this scope.
- **If** a global, filterable super-admin audit feed is built (the `I-18` gap in `spec-run-5`), then it needs an explicit union of `catalog_audit_log` and `identity_audit_log` on a normalized projection, not a query that assumes one target key.
- **If** catalog audit must be shown per product or category, then add a DBAL-only, requester-authorized query handler (`QRY-002/QRY-004`) over `(target_type, target_slug)` and a `GET /api/v1/admin/catalog/.../audit` route; that is a new requirement, not a change to this table.
- **If** retention or tamper-evidence becomes a requirement, then add a partitioned archive and/or a hash chain in a new migration under MIG-001; today neither exists.
- **When** the platform adopts a polymorphic audit schema (`target_type` + `target_id` in one table) and deptrac permits the cross-context write, migrate these rows into it and drop `catalog_audit_log` with a reversible migration; until then, two tables are the honest boundary.

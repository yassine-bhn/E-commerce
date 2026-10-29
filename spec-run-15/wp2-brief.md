# WP2 brief — approval write model, projector and replay

Authority: `spec-run-15/spec.md` v2, requirements R1–R3, R12, R31 and the WP2 row.
This brief is the implementation contract. If it conflicts with the spec, the spec wins.

## Scope

Create the Catalog approval write model and nothing else. Do **not** rewire existing command
handlers, controllers, policies, read models or the backfill command — that is WP3. WP2 may create
new files and edit only the files listed below.

Owned paths:

- `migrations/Version20261029000001.php`
- `src/Catalog/Domain/Model/CatalogSubmission.php`
- `src/Catalog/Domain/Model/CatalogSubmissionKind.php` (only if a helper is genuinely needed)
- `src/Catalog/Domain/Event/CatalogSubmission*.php`
- `src/Catalog/Domain/Repository/CatalogSubmissionRepository.php`
- `src/Catalog/Application/Port/CatalogSubmissionStore.php`
- `src/Catalog/Infrastructure/Persistence/DbalCatalogSubmissionStore.php`
- `src/Catalog/Infrastructure/Projection/ProjectStorefrontApproval.php`
- `src/Catalog/Infrastructure/Projection/ReplayCatalogSubmission.php`
- `docs/adr/ADR-0014-catalog-approval-write-model.md`
- `tests/Unit/Catalog/CatalogSubmissionTest.php`
- `tests/Integration/Catalog/CatalogSubmissionStoreTest.php`
- `tests/Projection/Catalog/ReplayCatalogSubmissionTest.php`
- `config/services.yaml` only if autowiring needs a tag; the replay must be tagged
  `app.projection_replayer`.
- `src/Shared/Application/Projection/ProjectionReplayer.php` and
  `src/Shared/Infrastructure/Console/ProjectionReplayCommand.php` only if needed to report
  `missingView` (see Replay below). Keep the WP1 tests green.

Never edit: `spec-run-15/spec.md`, `.agents/state/**`, `.agents/audit/**`, existing catalog
handlers/controllers/read models, `src/Catalog/Infrastructure/Console/BackfillCatalogApprovalCommand.php`,
the money files, cart/ordering, frontend, or any other migration.

## Migration

`Version20261029000001` must execute exactly the approved SQL from
`spec-run-15/migrations-preview.sql`:

```sql
CREATE TABLE catalog_submission (
    kind VARCHAR(16) NOT NULL,
    slug VARCHAR(128) NOT NULL,
    approval_status VARCHAR(20) NOT NULL DEFAULT 'PENDING_APPROVAL',
    submitted_by VARCHAR(64) DEFAULT NULL,
    submitted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
    reviewed_by VARCHAR(64) DEFAULT NULL,
    reviewed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
    PRIMARY KEY (kind, slug),
    CONSTRAINT chk_catalog_submission_kind CHECK (kind IN ('products', 'categories')),
    CONSTRAINT chk_catalog_submission_status
        CHECK (approval_status IN ('PENDING_APPROVAL', 'APPROVED', 'REJECTED'))
);
CREATE INDEX idx_catalog_submission_status ON catalog_submission (kind, approval_status);
```

The `down()` drops the table. Schema-only; no data movement. Apply it to dev and test with
`docker compose exec app php bin/console doctrine:migrations:migrate -n` and the same with
`--env=test`. Report the output.

## Domain model

`CatalogSubmission` is a state-based aggregate (no event sourcing). It carries:

- `CatalogSubmissionKind $kind`, `string $slug`, `ApprovalStatus $status`
- `?string $submittedBy`, `?DateTimeImmutable $submittedAt`
- `?string $reviewedBy`, `?DateTimeImmutable $reviewedAt`
- `?string $rejectionReason`, `DateTimeImmutable $updatedAt`

Required behaviour:

- `create(kind, slug, actorId, now)` → `PENDING_APPROVAL`, `submitted_by = actorId`,
  `submitted_at = now`, no reviewer.
- `submit(actorId, now)` → `PENDING_APPROVAL`; sets submitter/time; clears reviewer/time/reason.
  Allowed from `PENDING_APPROVAL` and `REJECTED`; `APPROVED` is refused with
  `ApprovalTransitionRefusedException::approvedCannotBeSubmitted()`.
- `decide(CatalogDecision, reviewerId, ?reason, now)` → only `PENDING_APPROVAL`;
  `APPROVED` or `REJECTED`; sets reviewer/time; rejection without a non-empty reason is refused;
  approval clears the reason. Never touches publication.
- `reviewerSave(actorId, now)`:
  - from `PENDING_APPROVAL` or `REJECTED` → `APPROVED`, reviewer/time set, reason cleared;
  - from `APPROVED` → unchanged, **original reviewer metadata preserved**;
  - returns a value indicating whether a real approval transition happened.
- `ownerModified(now)` → `APPROVED` becomes `PENDING_APPROVAL` and clears reviewer/time/reason;
  pending/rejected stay as they are; returns whether it changed.
- `delete` is store-level, not a model transition.

Use `ApprovalStatus` and `CatalogDecision`; do not duplicate status strings. Throw the existing
domain exceptions. Keep the model free of DBAL/Doctrine.

## Store (single writer)

`App\Catalog\Application\Port\CatalogSubmissionStore` is the application port WP3 will inject.
`App\Catalog\Infrastructure\Persistence\DbalCatalogSubmissionStore` is the only class that
inserts/updates/deletes `catalog_submission`. It must implement the port and may also implement
`CatalogSubmissionRepository`; do not create a second adapter that writes the table.

Exact public methods (names and parameters are fixed so WP3 can code against them):

```php
public function create(CatalogSubmissionKind $kind, string $slug, string $actorId): CatalogSubmission;
public function submit(CatalogSubmissionKind $kind, string $slug, string $actorId, bool $actorIsSuperAdmin): CatalogSubmission;
public function decide(CatalogSubmissionKind $kind, string $slug, CatalogDecision $decision, string $reviewerId, ?string $reason = null): CatalogSubmission;
public function reviewerSave(CatalogSubmissionKind $kind, string $slug, string $actorId): CatalogSubmission;
public function ownerModified(CatalogSubmissionKind $kind, string $slug): CatalogSubmission;
public function find(CatalogSubmissionKind $kind, string $slug): ?CatalogSubmission;
public function delete(CatalogSubmissionKind $kind, string $slug): void;
```

Semantics:

- Every mutating method runs in **one** `Connection::transactional()` block. Inside it:
  1. lock the row with `SELECT ... FOR UPDATE` (except `create`);
  2. apply the model transition;
  3. persist the submission row (insert for create, update otherwise, including `updated_at`);
  4. append exactly one outbox event describing a real transition via `OutboxPort::append()`
     with `aggregate_type = 'catalog_submission'` and `aggregate_id = '<kind>:<slug>'`;
  5. write the audit entry through `CatalogAuditPort` when the transition has one;
  6. synchronously project the six approval columns into the view row via
     `ProjectStorefrontApproval` in the same transaction.
- `create` audits `CATALOG_SUBMISSION_CREATED` and appends `CatalogSubmissionCreated`.
- `submit` audits `CATALOG_SUBMISSION_SUBMITTED` from pending and
  `CATALOG_SUBMISSION_RESUBMITTED` from rejected, appending the matching event.
- `decide` audits `CATALOG_SUBMISSION_APPROVED` / `CATALOG_SUBMISSION_REJECTED` and appends the
  matching event.
- `reviewerSave` appends/audits `CATALOG_SUBMISSION_APPROVED` **only when it really moves
  pending/rejected to approved**. Saving an already-approved row writes no submission update, no
  outbox row and no audit row; it returns the existing model.
- `ownerModified` on approved→pending appends `CatalogSubmissionReopened` and writes no audit row
  (the spec names no reopen audit action); on pending/rejected it writes nothing and returns the
  existing model.
- `submit` ownership: when `actorIsSuperAdmin` is false, the actor must equal
  `submitted_by`; otherwise throw `CatalogSubmissionNotOwnedException::for()`.
- `find` is a non-locking read and returns `null` when absent. `delete` removes the submission row
  and nothing else (the content row deletion is WP3's transaction).
- All date arithmetic uses one `DateTimeImmutable` per transition; format timestamps as
  `Y-m-d H:i:s.uP` for PostgreSQL.

Events: one small readonly class per transition, each with `eventId` (UUID v7 string), `kind`
(the `CatalogSubmissionKind` value), `slug`, `actorId`, `status` (the resulting
`ApprovalStatus` value), optional `reason`, and `occurredOn` (`DateTimeImmutable`). The outbox
serializer must be able to JSON-encode them; avoid resources and closures. Suggested classes:
`CatalogSubmissionCreated`, `CatalogSubmissionSubmitted`, `CatalogSubmissionResubmitted`,
`CatalogSubmissionApproved`, `CatalogSubmissionRejected`, `CatalogSubmissionReopened`.

## Projector

`ProjectStorefrontApproval::project(CatalogSubmission $submission): void` updates exactly:

`approval_status, submitted_by, submitted_at, reviewed_by, reviewed_at, rejection_reason`

in `storefront_product_view` (products) or `storefront_category` (categories), keyed by `slug`.
It never inserts a missing view row and never touches `published` or `hidden`. It must be usable
inside the store transaction. Format timestamps the same way as the store.

## Replay

`ReplayCatalogSubmission implements ProjectionReplayer`:

- `name(): 'catalog-submission'`
- `source(): 'catalog_submission'`
- `replay(bool $dryRun): array` in one transaction, writing no outbox/processed_events rows.
- Rebuild the same six columns in both view tables from `catalog_submission`.
- Idempotent: a second replay writes zero rows (`IS DISTINCT FROM` in the UPDATE is the simplest
  way to count real changes).
- Never inserts a missing view row; leaves `published` and `hidden` untouched.
- Reports both directions: view rows with no submission (`orphans`, keep this key because the
  WP1 command reads it) and submissions with no view row (`missingView`). If you add the second
  key, update `ProjectionReplayCommand` defensively (`$report['missingView'] ?? 0`) so the WP1
  test stub with only two keys still passes, and print the missing-view count when it is non-zero.

## TDD (mandatory)

RED before production code, one behaviour at a time. At minimum record real failing output for:

1. the unit model transitions (including reviewer-save preserving approved metadata, and
   rejection requiring a reason);
2. the integration store: create writes submission + outbox + audit + projected view row in one
   transaction; a forced failure in projection/audit leaves no submission/outbox/audit row;
3. the projection replay: restores corrupted and missing view rows, second run writes zero,
   dry-run writes nothing, reports both orphan directions.

Write the test, run it, capture the actual failure, then implement. Do not weaken existing tests.
The repo already has three known unrelated failures:
`AdminCategoryManagementTest` (409 on already-approved category), `AdminProductListTest:142`
(Meilisearch state), `CatalogVisibilitySingleSourceTest` (AdminProductFinder mentions
CatalogVisibility). Leave them alone.

## Verification

Run and report:

- `docker compose exec app php bin/console doctrine:migrations:migrate -n`
- `docker compose exec app php bin/console doctrine:migrations:migrate -n --env=test`
- `docker compose exec app vendor/bin/phpunit tests/Unit/Catalog/CatalogSubmissionTest.php`
- `docker compose exec app vendor/bin/phpunit tests/Integration/Catalog/CatalogSubmissionStoreTest.php`
- `docker compose exec app vendor/bin/phpunit tests/Projection/Catalog/ReplayCatalogSubmissionTest.php`
- `docker compose exec app php bin/console list --raw | grep projection`
- `docker compose exec app vendor/bin/phpunit --testsuite=Projection`
- `docker compose exec app vendor/bin/phpstan analyse src/Catalog src/Shared`
- `docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff` on touched files

Do not commit (the worktree already contains other uncommitted work). Report files, RED
commands + failures, migration output, test counts, remaining failures, and any spec gap.

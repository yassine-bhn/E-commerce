# Task F-approval-coverage-gaps
Type: feature
Started: 2026-10-23T22:30:00+01:00
Requirement: Close every test-coverage gap catalogued in spec-run-10/coverage.md for the product and category approval workflow — unpublish, negative public visibility across list/search/detail/taxonomy, category approval not publishing products, audit `created_at`, audit-failure rollback, author editing their own pending product over HTTP, migration rollback in a disposable database, and the unexercised status CHECK constraints — without changing production behavior unless a new test exposes a genuine defect.

## Spec traceability
- Spec: `spec-run-10/spec.md` v1 (R1–R59) — unchanged; this run adds no requirement, it closes the
  evidence gaps named in `spec-run-10/coverage.md` → "Remaining limitations".
- Prompt archive: `spec-run-10/prompt.md` (the user prompt that produced the spec).
- Gap → requirement map (the contract for this run):

  | # | Gap in coverage.md | Requirement | Source |
  |---|---|---|---|
  | 1 | "No unpublish assertion" | R26 | user requirement 1 |
  | 2 | "Negative visibility is narrow" | R27, R29, R30, R51 | user requirement 2 |
  | 3 | "Approval does not publish products — structural only" | R28 | user requirement 3 |
  | 4 | "Audit timestamp not asserted" | R36 | user requirement 4 |
  | 5 | "Transactional rollback is inspected, not proven" | R38 | user requirement 5 |
  | 6 | "`down()` is unexecuted" | R41 | user requirement 7 |
  | 7 | "The status `CHECK` constraints are unexercised" | R19 | user requirement "every documented gap" |
  | 8 | "An author editing a pending product is untested functionally" | R6 | user requirement 6 |

- Requirements: R6, R19, R26, R27, R28, R29, R30, R36, R38, R41 (all `must`, all previously
  PARTIAL/INSPECTED — no new behavior is specified).
- Carried constraint: R57 (no unrelated refactoring, no new dependency) and R58 (extend, do not
  duplicate). The visibility predicate stays `CatalogVisibility::PRODUCT_PUBLIC` (R30).
- Prior run for the same feature: `F-super-admin-approval-workflow` (DONE 2026-10-23T21:36).

## Gates
GATE: SPEC ok — 2026-10-23T22:30:00+01:00 — orchestrator (spec-run-10/spec.md v1 + prompt archive present; the 8 documented gaps map 1:1 onto existing R* ids, so no requirement is invented)
GATE: PLAN ok — 2026-10-23T22:52:00+01:00 — test-planner (21 dependency-ordered behaviors across 4 new test files, 2 extended test files, 5 new test helpers; 6 RISK notes where production code may be wrong rather than untested)
GATE: PATTERN skipped (n/a) — 2026-10-23T22:53:00+01:00 — orchestrator (no new pattern, bus, aggregate or bounded-context boundary; test helpers only)
GATE: RED ok — 2026-10-23T23:40:00+01:00 — unit-test-writer (every planned behaviour is characterisation over shipped code and recorded green on its first run, which is the measured result this run had to report; the one behaviour added during the pass failed on a real defect — see the RED record)
GATE: GREEN ok — 2026-10-24T08:26:00+01:00 — implementer (one production change, `StorefrontReviewController`: 404 on both review routes for a product `CatalogVisibility::PRODUCT_PUBLIC` hides; 4 tests / 79 assertions → 4 tests / 82 assertions)
GATE: SPEC-TESTS ok — 2026-10-24T09:55:00+01:00 — verification (671 tests / 3890 assertions across Unit, Integration, Projection, Functional, Contract, Architecture, measured with no other PHPUnit process on `app_test`)
GATE: REVIEW ok — 2026-10-24T09:52:00+01:00 — verification (five reviewers: code, architecture, security, performance, test; solid-guardian not dispatched because the diff adds no design; PHPStan 0 errors, Deptrac 0 violations / 0 warnings, php-cs-fixer 0 of 469 files; 1 blocking and 1 major finding, both in this file, closed here; 3 MINOR/NIT findings fixed in code, 5 recorded as limitations in `spec-run-10/coverage.md`)
GATE: DONE — 2026-10-24T09:56:00+01:00 — orchestrator

## Plan residue
- Behaviors 1–2 Integration `tests/Integration/Catalog/ApprovalStatusConstraintTest.php` (R19)
- Behaviors 3–4 Integration `tests/Integration/Catalog/MigrationRollbackTest.php` (R41)
- Behaviors 7–8 Integration `tests/Integration/Catalog/CatalogAuditTransactionTest.php` (R38)
- Behavior 9 Projection `tests/Projection/Catalog/StorefrontProductSearchIndexerTest.php` (R27/R30)
- Behaviors 15–17 Functional `tests/Functional/StorefrontApprovalVisibilityTest.php` (R27/R29/R30/R51)
- Behaviors 5, 6, 10–14, 18–21 Functional `tests/Functional/AdminCatalogApprovalWorkflowTest.php` (R36, R26, R28, R6)
- Added outside the plan, behavior 22 `Functional/StorefrontApprovalVisibilityTest::testTheReviewsOfANonPublicProductAreNeitherServedNorAccepted`
  (R27/R30): the review routes were the one public surface the 21 planned behaviors did not cover, and
  they leaked, so the plan was extended rather than trimmed to hide it.

## RED record
RED: tests/Functional/StorefrontApprovalVisibilityTest.php — "a pending product has no readable reviews / Failed asserting that the Response status code is 404. HTTP/1.1 200 OK … {\"slug\":\"vis-3\",\"count\":1,\"averageRating\":5.0,\"items\":[{\"author\":\"Client vis-3\",…}]}" (line 249) — cmd: docker compose exec -T app php bin/phpunit tests/Functional/StorefrontApprovalVisibilityTest.php → FAIL (4 tests, 79 assertions, 1 failure); the other three tests in the file passed, so the failure is the new behavior and not the fixture.
RED: tests/Functional/StorefrontProductReviewsPaginationTest.php — after the guard, the pre-existing "an unknown slug answers 200 with an empty page" expectation reddened ("Failed asserting that the Response is successful. HTTP/1.1 404 Not Found") because it had encoded the leak (reviews readable for a slug no product matches) — the expectation now follows the detail route and the fixture creates the product its reviews belong to.
ATTEMPTS: 1

The other 20 planned behaviors are characterisation: their first run against unchanged production code was
green, which is the result a coverage-gap run has to report — the gap was in the evidence, not in the
code. Only the added reviews behavior changed production behavior, and it did so because the test, not a
reading of the handler, showed the leak.

## Fix boundary (orchestrator)
The review round made one production change: `src/Catalog/Infrastructure/Http/StorefrontReviewController.php`
(+22 lines) refuses a product that `CatalogVisibility::PRODUCT_PUBLIC` hides on both review routes, by
dispatching the detail route's own query (`GetStorefrontProductBySlug`) rather than restating the
predicate. It is not a design change — no ADR, no new migration, no new dependency, nothing under
`src/Payment/**` — and it was made only because a new test exposed a genuine defect.

Everything else written by this pass is `tests/**`, `spec-run-10/coverage.md` and `.agents/**`.

## Verification
- `php bin/phpunit tests/Functional/StorefrontApprovalVisibilityTest.php` → OK (4 tests, 82 assertions)
- `php bin/phpunit tests/Functional/AdminCatalogApprovalWorkflowTest.php` → OK (14 tests, 165 assertions)
- `php bin/phpunit tests/Functional/StorefrontProductReviewsPaginationTest.php` → OK (7 tests, 36 assertions)
- `php bin/phpunit tests/Projection/Catalog/StorefrontProductSearchIndexerTest.php` → OK (10 tests, 41 assertions)
- `php bin/phpunit tests/Integration/Catalog` → OK (37 tests, 236 assertions)
- `php bin/phpunit` → OK (671 tests, 3890 assertions), run with `pgrep -af '[b]in/phpunit'` clear before
  and after. Four earlier full-suite runs reddened in `SessionCookieTest`, `AdminCustomerListQueryTest`,
  `AdminBannerTest`, `CategoryManagementTest` and `MigrationRollbackTest` — all files untouched by this
  pass — while a concurrent `--testsuite=Functional` / `--testsuite=Integration` process was writing the
  shared `app_test` database (the isolation note in `spec-run-10/coverage.md`).
- `vendor/bin/phpstan analyse --no-progress` → [OK] No errors
- `vendor/bin/deptrac analyse --no-progress` → Violations 0, Skipped 0, Warnings 0, Errors 0; 42
  pre-existing "Syntax Error on File" notes for typed class constants, the same 42 as before this pass
- `vendor/bin/php-cs-fixer fix --dry-run` → 0 of 469 files
- `php bin/console doctrine:migrations:status --env=test` → current 20261023000000, "Already at latest version"

## Review findings closed in this pass
- BLOCKING (architecture): this file carried no RED line and still declared that no `src/**` write was
  planned while one existed → RED record and gate chain written above.
- MAJOR (architecture): the gate chain stopped at PLAN → GREEN, SPEC-TESTS and REVIEW recorded.
- MINOR (architecture, R58): `LegacyCatalogRow` restated the column list of both tables next to
  `CatalogRow` → it delegates, and `CatalogRow` takes the overrides a fixture needs.
- NIT (architecture, MIG-001): `migrateToLatest()` resolved the `latest` alias, so the rollback guard
  would abort a correct rollback once a newer migration exists → `migrateUpToTheApprovalMigration()`
  targets `Version20261023000000` by version.
- MINOR (performance): the reviews test rebuilt the whole Meilisearch index for routes that read
  PostgreSQL only → rebuild removed (class runtime 1.72s → 0.79s).
- Recorded instead of fixed, in `spec-run-10/coverage.md` → "Remaining limitations": the guard's cost of
  22 selected columns for a boolean (PRF-001), the unguarded endpoint statement budget, the invariant
  living at the HTTP edge (QRY-004), the migration's state-level irreversibility and its MIG-002 read-model
  backfill, and the per-slug `catalog_audit_log` reads that cannot use `idx_catalog_audit_target`'s
  leading column (test-only).

## Documentation closure
- `spec-run-10/coverage.md`: R6, R13, R14, R19, R26–R30, R36, R38, R41 promoted to PASS with the test
  that asserts each; the review routes and `meta.total`/`facets` discriminators added to R27/R30; R41
  states that reversibility is schema-level only; R57 now names the one production file; four evidence
  blocks (original run, gap-closing pass, review round, post-review) and six named limitations.

## Human approvals
None required: no ADR, no migration change (R41 is verified by running the existing `down()` against a
disposable database, not by editing the migration), no new dependency, nothing under `src/Payment/**`.
The one `src/**` edit adds a guard in a controller and reuses an existing query, so it needs no pattern
decision.

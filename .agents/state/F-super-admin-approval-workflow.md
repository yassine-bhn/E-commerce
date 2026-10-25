# Task F-super-admin-approval-workflow
Type: feature
Started: 2026-10-23T20:03:00+01:00
Requirement: Backend-only approval gate for catalog products and categories: regular Admins create `PENDING_APPROVAL` submissions they own, read their own status and rejection reason, edit pending or rejected work and resubmit rejections; the Super Admin performs full CRUD over any submission (including other Admins') and is the only role that approves or rejects; approval and publication stay independent; every public surface enforces both; significant actions are audited with actor, timestamp and reason; the schema grows only where needed and the tests are executed and reported.

## Spec traceability
- Spec: spec-run-10/spec.md (v1 — synthesised from analyst-a/b/c of this run)
- Prompt archive: spec-run-10/prompt.md
- Stage 4 summary: implementation inherited from spec-run-8 is present and uncommitted; the single defect found by the gap-driven run was in the test harness (see INV132 diagnosis), fixed without touching `src/**`; full suite, PHPStan, Deptrac and php-cs-fixer are green
- Requirements: R1–R59 (spec-run-10/spec.md)

## Gates
GATE: SPEC ok — 2026-10-23T21:20:00+01:00 — orchestrator (spec-run-10/spec.md; both skeptic BLOCKING questions resolved by reproduction, not by asking)
GATE: INVESTIGATE ok — 2026-10-23T21:30:00+01:00 — investigator (WP1: the 8 red tests were a harness fault, not a product fault)
GATE: RED ok — 2026-10-23T20:33:00+01:00 — unit-test-writer (pre-existing `tests/Functional/AdminCatalogApprovalWorkflowTest.php`: 1 error + 7 failures, `spec-run-10/baseline-approval-functional.log`)
GATE: GREEN ok — 2026-10-23T21:32:00+01:00 — implementer (test-side fix only: unique e-mail per test identity; targeted file 8 tests / 101 assertions green)
GATE: SPEC-TESTS ok — 2026-10-23T22:12:00+01:00 — verification (632 tests / 3563 assertions across Unit, Integration, Projection, Functional, Contract, Architecture; the count includes the unrelated uncommitted WhatsApp suite present in the working tree)
GATE: REVIEW ok — 2026-10-23T21:35:00+01:00 — verification (PHPStan 0 errors, Deptrac 0 violations, php-cs-fixer 0 of 457 files)
GATE: DONE — 2026-10-23T21:36:00+01:00 — orchestrator

## Diagnosis (WP1)
INV132 — `AdminCatalogApprovalWorkflowTest` created its actors with
`catalog.workflow.<first 8 hex of a UUIDv7>@example.com`. Two UUIDv7 values minted inside the
same millisecond share those first eight hex digits, so every actor in a test received the same
e-mail. `CustomerProfileUserProvider` resolves a session by e-mail, so:

- the second client's token refreshed to the first user's row → the "foreign admin" read returned
  200 with the owner's `submittedBy` (looked like an ownership leak, was identity collapse), and
- when the two rows disagreed on role, the refresh produced an unusable identity → the
  firewall answered `401 {"error":"Authentication required."}` and dropped the session cookie.

Minimal reproduction: two test users with the full UUID as local part authenticate independently;
the queue returns each actor's own rows. With that one change the file is green and no
`src/**` file was modified, which falsifies the product-fault hypothesis for all eight tests.

## RED record
RED: tests/Functional/AdminCatalogApprovalWorkflowTest.php — "Failed asserting that the Response status code is 404." (line 104) plus `array_column(): Argument #1 must be of type array, null given` (line 140) — cmd: docker compose exec -T app php bin/phpunit tests/Functional/AdminCatalogApprovalWorkflowTest.php → FAIL (1 error, 7 failures)
ATTEMPTS: 1

## Fix boundary (orchestrator)
The only file written is `tests/Functional/AdminCatalogApprovalWorkflowTest.php` (the createUser
helper and its comment). No `src/**`, no migration, no configuration and no frontend path is
touched by this run; the production surface inherited from spec-run-8 is left exactly as it was
found, because the evidence did not justify changing it.

## Verification
- `php bin/phpunit tests/Functional/AdminCatalogApprovalWorkflowTest.php` → OK (8 tests, 101 assertions)
- `php bin/phpunit` → OK (632 tests, 3563 assertions) — final run over the whole working tree; an earlier run in this session reported 612/3513
- `vendor/bin/phpstan analyse --no-progress` → [OK] No errors
- `vendor/bin/deptrac analyse --no-progress` → 0 violations, 0 errors, 0 warnings
- `vendor/bin/php-cs-fixer fix --dry-run` → 0 of 457 files fixable
- `php bin/console doctrine:migrations:status --env=test` → current 20261023000000, "Already at latest version"
- `php bin/console doctrine:migrations:migrate -n --env=test` → [OK] Already at the latest version

## Documentation closure
- `docs/adr/ADR-0012-catalog-audit-trail.md` written: the code already cited ADR-0012 in three places (migration, audit writer, services.yaml) while `docs/adr/` stopped at 0011; the ADR records the catalog-owned trail, its rejected alternatives and its non-properties.
- `spec-run-10/coverage.md` written: R1–R59 mapped to executed tests or inspection, with nine named limitations (unpublish not asserted, narrow negative visibility, timestamp not asserted, rollback inspected only, `down()` unexecuted, CHECK constraints unexercised, author pending edit untested).

## Human approvals
None required: no ADR, no new migration in this run, no new dependency, nothing under `src/Payment/**`.

# Spec: Paying down the approval workflow's six outstanding limitations
Version: 1

## Goal
The approval workflow ships without the caveats its report apologises for: its design decisions are defended by executable checks, its transaction is proven against a real process boundary, its two schema weaknesses (no e-mail uniqueness, a non-state-safe destructive migration) are fixed by migrations that cannot harm a running store, and the reviews endpoint is authorized where the rule requires while staying inside its cost budget.

## Corrected prompt
The Super Admin approval workflow still carries six documented limitations. Close them: make R39/R40/R58 verifiable instead of merely inspected, assert the category move directly (R42), prove the approval transaction beyond statement level (R38), add the missing unique index on `customer_profile.email`, make the approval migration reversible in state as well as schema and free of the MIG-002 read-model backfill, and move the reviews guard off the HTTP edge while pinning the endpoint's statement cost.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The three design properties are defended by checks rather than prose: an executable assertion exists for "no second approval mechanism" (a single writer of APPROVED/REJECTED, one controller owning the decision routes), for "only genuinely missing schema was added" (the added columns/tables are exactly the documented set; categories keep `hidden` instead of gaining `published`), and for "the audit trail reuses the existing shape" (the catalog trail carries the same action/actor/reason/timestamp shape as `identity_audit_log`). | quality | "R39/R40/R58 are design properties — INSPECTED, not testable" | must |
| R2 | A test moves a product to a different category and asserts the persisted reference, the single audit entry, and the public consequence on both sides of the move — the product leaves the storefront when the destination category is not approved, and returns when it is — so a `category_slug` that is silently dropped or a visibility that keeps following the old category fails. | functional | "Moving a product to another category is still only exercised as part of the update path (R42)" | must |
| R3 | Moving a product to a category that does not exist stays refused (422) and writes neither row nor audit entry. | functional | "(R42)" | should |
| R4 | The approval transaction is proven across a boundary a single connection cannot fake: while the handler's transaction is open, another connection sees the pre-decision state and no audit entry; after commit it sees both; if the writing process dies before commit, neither is visible. | quality | "The transactional proofs are statement-level, not process-level (R38)" | must |
| R5 | `customer_profile.email` is unique in the database, case-insensitively, matching the `lower(email)` comparisons the identity handlers already use. | constraint | "customer_profile.email has no unique index" | must |
| R6 | The unique index is introduced without destroying data: the migration detects pre-existing duplicates and aborts with an actionable message instead of deleting, merging or silently picking a winner. | constraint | "customer_profile.email has no unique index" | must |
| R7 | Registration and Google linking keep their current answer for a taken e-mail (the existing conflict response) when the database, not the pre-check, is what refuses the insert — a race between two registrations answers 409 and never 500. | functional | "customer_profile.email has no unique index" | must |
| R8 | No migration writes read-model data: the approval feature's legacy backfill is executed by an explicit operator command, is idempotent, reports how many rows it touched, and is documented in the deployment runbook. | constraint | "the backfill itself violates MIG-002 (read model written from a migration). Needs a new migration" | must |
| R9 | A `down()` followed by an `up()` of the approval migration leaves approval and publication state exactly as the destructive `down()` left it — nothing is re-approved or re-published — and the rollback drill asserts that, including for a rejected and an unpublished row. | constraint | "up() re-approves and re-publishes every row after a down()" | must |
| R10 | The rollback drill still proves what it promises (columns and audit table restored, catalogue rows survive) and the runbook states the operational truth: rolling the feature back discards approval state, and restoring it is a backup operation, not a migration. | quality | "reversible in schema, not in state" | should |
| R11 | The reviews query is authorized inside the handler that answers it (QRY-004): a product the storefront hides yields no page, and the controller no longer decides visibility. The write path keeps refusing a hidden product before the insert. | constraint | "The review guard lives at the HTTP edge (QRY-004)" | must |
| R12 | The visibility predicate is still written once: the handler composes `CatalogVisibility::PRODUCT_PUBLIC` instead of restating approved/published/category rules, so the two surfaces cannot drift (R30). | constraint | "(QRY-004) … which no test pins" | must |
| R13 | Both review verbs have a pinned statement budget at the endpoint: GET answers with 2 statements, POST with 2 (visibility + insert); adding a lookup back into the path turns a test red. | quality | "raises the endpoint from 2 to 3 statements, which no test pins" | must |
| R14 | Nothing regresses: public review payloads, cursor paging, the rating filter, the 404 shape, and every approval-workflow behavior keep their current contracts. | quality | "do it" | must |
| R15 | The report is updated so each former limitation cites its test, migration or command, and any limitation that genuinely remains is stated with the reason. | quality | "Remaining limitations (documented, not fixed here)" | must |
| R16 | No new dependency, no frontend change, no unrelated refactor; static analysis, architecture checks and the full suite stay green. | constraint | "do it" | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The approval feature has not reached production (only dev and test have run the migration), so rewriting its data statements is safe; if it has, the state fix must be a superseding migration instead. | high |
| 2 | "State-reversible" means "a re-apply does not silently change approval or publication state". Restoring the pre-rollback statuses is impossible once `down()` drops the columns, so the requirement is the safe direction (nothing becomes public), not a time machine. | med |
| 3 | `storefront_product_view` and `storefront_category` have no `created_at`, so "legacy rows" cannot be identified by date; "no workflow metadata" (`submitted_by`/`reviewed_by` null) is the only available discriminator, and only until a `down()` erases it. | med |
| 4 | A unique functional index on `lower(email)` replaces, rather than duplicates, the existing plain index, and the new migration's `down()` recreates the plain index so the older migration's `down()` still works. | med |
| 5 | The reviews write route is public and has no requester identity, so QRY-004's ownership clause does not apply to it; only the visibility clause does. | low |
| 6 | A child process killed between UPDATE and COMMIT is a legitimate PHPUnit test (the suite already shells out for migrations), not an unacceptable flake source. | med |

## Open questions
- [minor] Should the operator backfill command be gated (for example refuse without `--force`) so it cannot re-approve a live catalogue by accident? Default if unanswered: yes, it prints what it would change and requires an explicit flag to write.
- [minor] Does the statement budget belong to the endpoint or the handler? Default if unanswered: assert both, because the limitation is specifically about the endpoint.
- [minor] Should the review 404 payload stay `{"error":"Product not found"}`? Default if unanswered: yes, it is the existing contract and tests assert it.

## Out of scope
- Changing the approval state machine, the statuses or the decision matrix.
- Introducing a projection replay command for the catalog (`projection:replay` does not exist in this tree); the backfill is a one-off command instead.
- Unique constraints anywhere other than `customer_profile.email`.
- Backfilling approval metadata onto public responses.
- Frontend work.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Defend the three design properties with executable checks | R1 | ADR-0012, the approval migration, `DecideCatalogSubmissionHandler`, `AdminCatalogSubmissionController` | new architecture/schema tests | `tests/Architecture/` (new file) | none | each property has a passing assertion; anything still untestable is named with its reason |
| WP2 | Assert the category move end to end | R2, R3 | admin product routes, approval fixtures | functional test for the move and its visibility consequences | `tests/Functional/` (new file) | none | the test fails when the category reference or the visibility consequence is wrong |
| WP3 | Prove the transaction across a connection and a process | R4 | audit adapter, approval handler, migration probe helpers | integration test with a second connection and an out-of-process crash | `tests/Integration/Catalog/` (new file) | none | the second connection never sees an uncommitted decision or audit row, and a killed process leaves neither |
| WP4 | Enforce e-mail uniqueness safely | R5, R6, R7 | `Version20261022000000.php`, identity handlers, `EmailAlreadyUsedException` | new migration, handler mapping, concurrency test | `migrations/` (new file), `src/Identity/`, identity tests | none | duplicates abort the migration with a message; a raced insert answers the same conflict as a sequential one; the suite is green with the index |
| WP5 | Remove the read-model backfill and fix the re-apply | R8, R9, R10 | `Version20261023000000.php`, `MigrationRollbackTest`, runbook | schema-only migration, operator command, extended drill | `migrations/Version20261023000000.php`, new console command, `MigrationRollbackTest`, `docs/runbook-deployment.md` | none | no migration writes read-model data; down+up preserves the destructive outcome instead of publishing everything |
| WP6 | Authorize reviews in the handler and pin both budgets | R11, R12, R13 | review controller/handlers, `CatalogVisibility`, budget test | handler-level visibility, removal of the controller guard, budget assertions | review path under `src/Catalog/`, review tests | none | a hidden product yields no page without the controller deciding, the predicate is composed not restated, and GET/POST budgets are asserted |
| WP7 | Re-state the limitations honestly | R15 | all of the above | updated limitations section | `spec-run-10/coverage.md` | WP1–WP6 | every former limitation cites its evidence or its reason for remaining |

## Changelog (synthesizer and orchestrator only)
- v1: created

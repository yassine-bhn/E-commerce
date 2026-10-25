# Spec: Close the six remaining limitations of the Super Admin approval workflow
Version: 1

## Goal
Each of the six limitations the approval-workflow report still carries is either closed by an executed test, a migration or a production change, or is restated with a documented reason it cannot be — so that no limitation is left as "INSPECTED" when it could be asserted.

## Corrected prompt
The implementation of the Super Admin approval workflow still has these documented, unfixed limitations:
R39/R40/R58 are design properties — INSPECTED, not testable.
Moving a product to another category is still only exercised as part of the update path (R42).
The transactional proofs are statement-level, not process-level (R38).
customer_profile.email has no unique index.
The approval migration is reversible in schema, not in state — up() re-approves and re-publishes every row after a down(), and the backfill itself violates MIG-002 (read model written from a migration); needs a new migration.
The review guard lives at the HTTP edge (QRY-004) and raises the endpoint from 2 to 3 statements, which no test pins.
Do it.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | R39, R40 and R58 stop being INSPECTED: each one is either asserted by an executed test or re-stated with an explicit reason it cannot be tested. | quality | "R39/R40/R58 are design properties — INSPECTED, not testable" | must |
| R2 | A product update that moves a product from one category to another is asserted on its own, not only as a side effect of the generic update path. | quality | "Moving a product to another category is still only exercised as part of the update path (R42)" | must |
| R3 | The transaction that writes an approval decision and its audit entry is proven at process level, not only statement level. | quality | "The transactional proofs are statement-level, not process-level (R38)" | must |
| R4 | `customer_profile.email` carries a database uniqueness constraint. | constraint | "customer_profile.email has no unique index" | must |
| R5 | A migration exists that makes the approval migration's backfill compliant with MIG-002, i.e. no read-model data is written by a migration. | constraint | "the backfill itself violates MIG-002 (read model written from a migration). Needs a new migration" | must |
| R6 | After a `down()` followed by an `up()` of the approval migration, no row is silently re-approved or re-published. | constraint | "up() re-approves and re-publishes every row after a down()" | must |
| R7 | The review visibility guard no longer lives only at the HTTP edge: the authorization happens at the handler that answers the query (QRY-004). | constraint | "The review guard lives at the HTTP edge (QRY-004)" | must |
| R8 | The reviews endpoint's SQL statement count is pinned by a test. | quality | "raises the endpoint from 2 to 3 statements, which no test pins" | must |
| R9 | The six limitations are re-reported after the change, with the evidence that closes each one and any that remains. | quality | "Remaining limitations (documented, not fixed here)" | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Needs a new migration" applies to the MIG-002 part of the sentence and the state part of the same sentence is fixed in the same change. | med |
| 2 | A test can assert R58 by reading the source tree, and R40/R39 by reading the database schema; "not testable" in the prompt means "not yet tested". | med |
| 3 | "Process-level" means a proof that crosses a process or connection boundary, not an in-process assertion. | med |
| 4 | The unique index must match the lookups the application actually performs (`lower(email)`), not the literal column. | med |
| 5 | The review guard must move into the query handler that serves the reviews page; the write route keeps its own protection. | low |

## Open questions
- [minor] Is a unique index on `lower(email)` acceptable, given the report quotes `email`? Default if unanswered: yes, because `RegisterCustomerHandler`, `LinkGoogleAccountHandler` and `RequestPasswordResetHandler` all compare `lower(email)`.
- [minor] Where does the process-level proof live — a second database connection or a child process? Default if unanswered: both, with the connection proof as the deterministic one.
- [minor] Should the approval migration keep its unconditional `UPDATE` behind a command instead? Default if unanswered: yes, a console command run by the deploy runbook.

## Out of scope
- Rebuilding the approval workflow's behavior or changing what the statuses mean.
- Adding approval metadata to public payloads.
- Changing the storefront frontend.
- Replaying projections (`projection:replay` does not exist in this tree).
- Any new dependency.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Make R39/R40/R58 assertable | R1 | `docs/adr/ADR-0012-catalog-audit-trail.md`, `Version20261023000000.php`, `DecideCatalogSubmissionHandler.php`, `AdminCatalogSubmissionController.php` | tests that assert the three properties | `tests/Architecture/**` (new file only) | none | each of R39, R40, R58 has an executed assertion or an explicit, written reason it cannot have one |
| WP2 | Assert the category move | R2 | `AdminProductController`, `AdminCatalogTest` / `AdminCatalogApprovalWorkflowTest` | one test that moves a product between categories and asserts the stored reference and the public consequence | `tests/Functional/**` (one new file or one named method in an approval test) | none | the test fails if `category_slug` is not updated, and the full category-move behavior is asserted without going through the generic update assertions |
| WP3 | Prove the transaction at process level | R3 | `CatalogAuditTransactionTest`, the approval handler, the audit adapter | a test crossing a connection/process boundary | `tests/Integration/Catalog/**` (new file) | none | killing or isolating the writing process leaves the decision uncommitted and no audit entry behind |
| WP4 | Add the e-mail uniqueness constraint | R4 | `Version20261022000000.php`, identity command handlers | a new migration plus handler mapping and tests | `migrations/` (new file), `src/Identity/**`, `tests/**` for identity | none | the database refuses a duplicate `lower(email)`; the API answers the same conflict a sequential duplicate answers |
| WP5 | Make the approval migration MIG-002 compliant and state-safe | R5, R6 | `Version20261023000000.php`, `MigrationRollbackTest`, `docs/runbook-deployment.md` | schema-only migration, a backfill command, an extended rollback test | `migrations/Version20261023000000.php`, a new console command, `tests/Integration/Catalog/MigrationRollbackTest.php`, `docs/runbook-deployment.md` | none | no migration writes read-model data; `down()` + `up()` leaves a rejected/unpublished row rejected/unpublished in the probe database |
| WP6 | Move the review authorization into the handlers and pin the budget | R7, R8 | `StorefrontReviewController`, `ListProductReviewsHandler`, `SubmitProductReviewHandler`, `CatalogVisibility`, `ProductReviewsHandlerBudgetTest` | handler-level guard plus a statement-count assertion for both verbs | `src/Catalog/**` review path, `tests/Integration/Catalog/ProductReviewsHandlerBudgetTest.php`, review functional tests | none | a non-public product is refused without the controller deciding it, and the GET/POST statement counts are asserted |
| WP7 | Re-report the limitations | R9 | every WP above | the updated limitations section | `spec-run-10/coverage.md` | WP1–WP6 | each of the six items names its test/artifact or the reason it remains |

## Changelog (synthesizer and orchestrator only)
- v1: created

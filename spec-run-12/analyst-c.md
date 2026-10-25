# Spec: Closing the six approval-workflow limitations — skeptic reading
Version: 1

## Goal
Settle, in writing and with tests, what each of the six limitations actually asks for — including the ones the prompt states as if they were actionable but which no change can deliver — so that the next reader is not told "fixed" about something that was only re-labelled.

## Corrected prompt
Six limitations remain documented and unfixed in the Super Admin approval workflow: R39/R40/R58 were verified by inspection; a product moved between categories is only covered by the generic update path (R42); the transactional proofs stop at statement level (R38); `customer_profile.email` has no unique index; the approval migration is schema-reversible but not state-reversible, its `up()` re-approves and re-publishes everything after a `down()`, and its backfill violates MIG-002 — which is said to need a new migration; and the reviews guard sits at the HTTP edge in breach of QRY-004 while pushing the endpoint from 2 to 3 statements, unpinned. Close them.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | Each of R39, R40 and R58 ends the run with either an executed assertion or a written statement of why the property is not assertable; "INSPECTED" is not left as the final status without a reason. | quality | "R39/R40/R58 are design properties — INSPECTED, not testable" | must |
| R2 | For R58, the assertion is about the source: exactly one writer of `APPROVED`/`REJECTED` exists, and the decision routes are declared in exactly one controller. | quality | "(R58)" | must |
| R3 | For R40 and R39, the assertions are about the live schema: the approval columns and the audit table are exactly the documented set, no product/category column outside that set is introduced, categories carry `hidden` and no `published`, and the catalog audit table's action/actor/reason/timestamp shape matches `identity_audit_log`'s. | quality | "(R39/R40)" | must |
| R4 | A product can be moved to another category over the admin route, and the move is asserted independently of the generic update test: persisted `category_slug`, one `UPDATED` audit entry, and the public consequence evaluated on the destination category (hidden while the destination is not approved, visible again when it is). | functional | "Moving a product to another category is still only exercised as part of the update path (R42)" | must |
| R5 | The move to a category that does not exist remains a 422 and writes nothing. | functional | "(R42)" | should |
| R6 | The audit write's atomicity is proven from outside the writing connection: a second connection observes the old status and no audit row while the transaction is open, and both after commit. | quality | "The transactional proofs are statement-level, not process-level (R38)" | must |
| R7 | The atomicity is also proven against the failure the statement-level test cannot express: a writer that dies between the state `UPDATE` and `COMMIT` leaves neither the new status nor an audit row. | quality | "(R38)" | must |
| R8 | The database refuses two `customer_profile` rows whose e-mail differs only by case, because every identity lookup compares `lower(email)`. | constraint | "customer_profile.email has no unique index" | must |
| R9 | The migration that adds the constraint fails loudly, without touching rows, when duplicates already exist; it neither deletes nor merges. | constraint | "customer_profile.email has no unique index" | must |
| R10 | The `idx_customer_profile_email` index that `Version20261022000000::down()` drops still exists after this change's `down()`, so migrating all the way back does not fail. | constraint | derived from "no unique index" plus MIG-001 | must |
| R11 | A concurrent duplicate registration answers the same conflict as a sequential one — the unique violation is mapped to the existing domain exception, not surfaced as a 500. | functional | "no unique index" | must |
| R12 | The whole existing suite still passes with the constraint in place, including fixtures that previously reused an e-mail. | quality | "no unique index" | must |
| R13 | No migration writes `storefront_product_view` or `storefront_category` data; the legacy approval/publication backfill runs only as an explicit operator command, idempotent, with a dry-run default and a count report. | constraint | "the backfill itself violates MIG-002 (read model written from a migration)" | must |
| R14 | The state-reversibility claim is either made true or withdrawn in writing. If the unconditional backfill is removed, `down()` + `up()` must be proven to leave a rejected row rejected-less-and-pending and an unpublished row unpublished — i.e. the run asserts the *destructive* outcome and the absence of silent re-publication. | constraint | "up() re-approves and re-publishes every row after a down()" | must |
| R15 | The runbook states that a rollback of this feature discards approval state and that recovering it is a backup restore, not a migration; the drill asserts only what it can (schema restoration, row survival). | quality | "reversible in schema, not in state" | must |
| R16 | The reviews query handler refuses a product the storefront hides, and the controller no longer performs the visibility check for the list route. | constraint | "The review guard lives at the HTTP edge (QRY-004)" | must |
| R17 | The handler composes `CatalogVisibility::PRODUCT_PUBLIC` rather than restating it; a second copy of the approved/published/category rule is a defect. | constraint | "(QRY-004)" | must |
| R18 | The write route still refuses a review on a hidden product before inserting, and a refused review leaves no row. | functional | "(QRY-004)" | must |
| R19 | The statement cost is pinned for both verbs at the level the limitation names — the endpoint: GET 2, POST 2 — and the existing handler-level budget test keeps passing. | quality | "raises the endpoint from 2 to 3 statements, which no test pins" | must |
| R20 | The reviews contract is unchanged for public products: 200/201 status codes, the `slug`/`count`/`averageRating`/`items` payload, cursor paging, the rating filter, and the 404 error body. | quality | "do it" | must |
| R21 | The final report lists, for each of the six limitations, its new status and the test or artifact that supports it; a limitation that remains must say why, not just that it remains. | quality | "Remaining limitations (documented, not fixed here)" | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Needs a new migration" is the prompt's diagnosis, not necessarily the correct remedy: a new migration cannot change what `Version20261023000000::up()` does on a `down()` + `up()` cycle, because the re-run executes that same class. | high |
| 2 | R39/R40/R58 are testable in the sense that a test can read the tree or the schema; only the *judgement* ("only genuinely missing schema") stays human. | med |
| 3 | `down()` is inherently destructive here, so "state-reversible" can only mean "re-applying does not silently publish data"; a true restore is not achievable and must be declared. | high |
| 4 | Legacy rows cannot be identified after a `down()`: neither table has `created_at`, and every workflow column is dropped, so any discriminator is gone. | med |
| 5 | Killing a child process mid-transaction is testable only if the child is cooperative (it signals before commit); an arbitrary kill is flaky. | med |
| 6 | Making `lower(email)` unique may redden fixtures that create two customers with the same address; the fixture audit is part of this work, not a follow-up. | med |
| 7 | The reviews POST is a public route with no requester identity, so QRY-004's ownership rule cannot apply to it; only its visibility rule can. | low |

## Open questions
- [BLOCKING] Does a new migration have to *replace* `Version20261023000000` (delete/rewrite it) or may that migration be edited in place, given dev and test have already run it? Default if unanswered: edit in place, because a superseding migration cannot change the behaviour of the re-run `up()` the prompt complains about.
- [BLOCKING] If the backfill leaves migrations, a fresh `down()` + `up()` leaves every row pending and unpublished — the storefront goes dark until the operator runs the command. Is that the intended safe direction, or should the migration keep approving legacy rows and the run only document the hazard? Default if unanswered: the safe direction (nothing silently public), with the command in the runbook.
- [minor] Should the unique index be on `email` (literal) or `lower(email)` (what the code compares)? Default if unanswered: `lower(email)`.
- [minor] Should a POST to a hidden product be 404 (current) or 409? Default if unanswered: 404, unchanged.
- [minor] Should the out-of-process proof use a child PHP process or a second connection? Default if unanswered: second connection as the deterministic assertion, child process as the crash proof, and the child guarded by a marker file so it cannot hang.
- [minor] Does the approval migration's class docblock count as the documentation of the state hazard, or must the runbook change too? Default if unanswered: both.

## Out of scope
- Fixing the "author identity has no uniqueness protection" wording generally; only `customer_profile.email` is in the prompt.
- A projection replay command for the catalog view.
- Changing the approval state machine or the decision matrix.
- Reworking the audit table's shape (it is asserted as-is, not redesigned).
- Any new dependency or frontend change.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Give R39/R40/R58 executable evidence | R1, R2, R3 | ADR-0012, migration, handlers, live schema | new tests reading the tree and `information_schema` | `tests/Architecture/` (new file) | none | three assertions pass and each limitation's residual judgement is stated |
| WP2 | Cover the category move | R4, R5 | product update route, category fixtures | functional test of the move and its public consequence | `tests/Functional/` (new file) | none | reference, audit and visibility all asserted on both sides of the move |
| WP3 | Cross the process boundary | R6, R7 | audit adapter, approval handler | integration test with a second connection and a guarded child process | `tests/Integration/Catalog/` (new file) | none | uncommitted work invisible to the second connection; a dead writer leaves nothing |
| WP4 | Unique e-mail without data loss | R8–R12 | `Version20261022000000.php`, identity handlers, fixtures | migration, index rollback, exception mapping, fixture audit | `migrations/` (new file), `src/Identity/`, identity + fixture tests | none | duplicate `lower(email)` refused, duplicates abort loudly, down() keeps the old index droppable, suite green |
| WP5 | Migration honesty | R13, R14, R15 | `Version20261023000000.php`, `MigrationRollbackTest`, runbook | schema-only migration, operator command, extended drill, runbook note | `migrations/Version20261023000000.php`, new console command, `MigrationRollbackTest`, `docs/runbook-deployment.md` | none | no read-model write in any migration; down+up proven not to re-publish; runbook states the loss |
| WP6 | Reviews authorized where the rule says | R16–R20 | review controller/handlers, `CatalogVisibility`, budget test | handler-level visibility, controller guard removed, endpoint budgets | review path under `src/Catalog/`, review tests | none | handler decides, predicate not restated, GET/POST pinned, payloads unchanged |
| WP7 | Report the truth | R21 | all of the above | updated limitations section | `spec-run-10/coverage.md` | WP1–WP6 | six items, six verdicts, each with evidence or reason |

## Changelog (synthesizer and orchestrator only)
- v1: created

# Spec: Super Admin Management and Approval Workflow for Products and Categories

Version: 1

## Goal

Whatever a regular Admin creates stays out of the public catalogue and owned by its author until a Super Admin reads it, corrects it when needed and explicitly approves it; publication is a second, separate switch; every significant action leaves a trail naming the actor, the moment and, for a rejection, the reason.

## Corrected prompt

Implement a backend-only approval gate for catalog products and categories. Regular Admins create products and categories, which are persisted as `PENDING_APPROVAL` submissions owned by their creator; they read the status of their own submissions and the rejection reason, edit a pending or rejected submission under the existing content rules, and resubmit a corrected rejection. They can never approve, never write approval metadata, and never act on another Admin's submission. The Super Admin reads, creates, updates and deletes products and categories in every approval state — including other Admins' submissions — subject to the existing business rules and referential integrity. Approval is a separate, explicit decision with exactly three states and the five listed transitions; no create or update ever approves. Publication stays independent from approval: pending or rejected records are never public, an approved product may stay unpublished, approving a category publishes nothing, and a product is unreachable while its category is unapproved or hidden. Only a Super Admin decides. Every operation is authorized server-side on every route, and administrative data never leaks through a public response. Significant actions are audited with actor, timestamp and, for a rejection, the reason, reusing the existing audit infrastructure. Reuse the existing architecture, add only the fields that are genuinely missing, migrate safely so existing rows keep today's visibility, add the tests the prompt lists, run them, and report the actual results with evidence.

## What already exists (verified in the working tree)

This is not a greenfield build; the implementation from the previous run is present and uncommitted. Verified by reading the files and by executing the tests:

- `ApprovalStatus` with the closed transition set, `afterModification()` and `isPubliclyVisible()`, plus `ApprovalTransitionRefusedException`; covered by `tests/Unit/Catalog/Domain/ApprovalStatusTest.php`.
- `SubmitCatalogSubmission`, `DecideCatalogSubmission`, `SetProductPublication` commands and handlers; `SubmissionAccessPolicy`; `CatalogAuditPort` + `DbalCatalogAuditWriter`.
- `AdminCatalogSubmissionController` (queue, detail, `submit|resubmit|approve|reject`), `AdminProductController` create/update/publication, `AdminCategoryController` create/update/visibility/delete.
- `migrations/Version20261023000000.php`: approval columns on `storefront_product_view` and `storefront_category`, the `published` flag and `catalog_audit_log`.
- `CatalogVisibility::PRODUCT_PUBLIC` applied by `StorefrontProductRepository` and `StorefrontProductSearchIndexer`; category approval filtered in `CategoryFinder` and `StorefrontReferenceRepository`.
- `tests/Functional/AdminCatalogApprovalWorkflowTest.php` (8 tests) and `tests/Unit/Catalog/Domain/ApprovalStatusTest.php` (8 tests).

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A product created by a regular Admin is persisted with `PENDING_APPROVAL` and the creator as submitter. | functional | §1 create + "New submissions must default to `PENDING_APPROVAL`" | must |
| R2 | A category created by a regular Admin is persisted with `PENDING_APPROVAL` and the creator as submitter. | functional | §1 | must |
| R3 | A regular Admin can submit their own product or category for review, and submitting does not change the approval status. | functional | §1 "Submit newly created products and categories for Super Admin review" | must |
| R4 | A regular Admin reads the approval status of their own submissions and receives not-found semantics for another Admin's submission. | functional | §1 "View the approval status of their submissions"; §5 | must |
| R5 | A regular Admin reads the rejection reason on their own rejected submission. | functional | §1 "View rejection reasons when provided" | must |
| R6 | A regular Admin can edit their own `PENDING_APPROVAL` submission under the existing content rules, and the edit leaves it pending. | functional | §1 "Edit their pending or rejected submissions" | must |
| R7 | A regular Admin can edit their own `REJECTED` submission, and the edit leaves it rejected until it is resubmitted. | functional | §1 | must |
| R8 | A regular Admin can resubmit a `REJECTED` submission, moving it to `PENDING_APPROVAL` and clearing the previous reviewer, decision time and reason. | functional | §1 "Resubmit rejected submissions after making corrections" | must |
| R9 | A regular Admin cannot edit, resubmit, submit, publish or delete another Admin's submission, nor edit or resubmit an approved one. | constraint | §1 + §5 "Only a Super Admin can perform privileged CRUD operations on submissions created by other Admins" | must |
| R10 | A regular Admin cannot approve or reject any submission, including their own. | constraint | §1 "Regular Admins must not be able to approve their own submissions"; §5 | must |
| R11 | Approval metadata supplied in a request payload (status, submitter, reviewer, decision timestamps, rejection reason) is ignored or refused, never persisted. | constraint | §1 "manipulate approval metadata through API payloads"; "Do not trust client-provided approval metadata" | must |
| R12 | A Super Admin can create a product and a category directly, and the created record is pending, never approved. | functional | §2 Create (both) + §3 "no CRUD approves" | must |
| R13 | A Super Admin can enumerate and read products and categories in every approval and publication state, with their approval metadata. | functional | §2 Read (both) | must |
| R14 | A Super Admin can update another Admin's product or category in any approval state. | functional | §2 Update (both) | must |
| R15 | A Super Admin can delete a product when the existing business constraints allow it. | functional | §2 Products Delete | must |
| R16 | A Super Admin can delete a category when the existing constraints and product dependencies allow it. | functional | §2 Categories Delete | must |
| R17 | A refused delete changes nothing and answers with an explicit refusal. | constraint | §2 "subject to existing business constraints"; §7 integrity | must |
| R18 | Existing domain rules still apply to every submission and Super Admin edit (slug, taxonomy, price, stock, sub-level references, category tree). | constraint | §2 "Existing domain rules ... must be preserved" | must |
| R19 | Approval status is exactly one of `PENDING_APPROVAL`, `APPROVED`, `REJECTED`, in storage and in every admin representation. | functional | §3 "Supported approval states" | must |
| R20 | A Super Admin can approve a pending submission; the decision stores the reviewer and the decision time. | functional | §3 transition + §5 | must |
| R21 | A Super Admin can reject a pending submission; the decision stores the reviewer, the decision time and the reason when one is given. | functional | §3 transition + §6 | must |
| R22 | A Super Admin modification of an approved record returns it to `PENDING_APPROVAL` and clears the stale review metadata. | functional | §3 "`APPROVED` → `PENDING_APPROVAL` if a subsequent modification requires reapproval" | must |
| R23 | Any transition outside the five listed is refused with a typed domain refusal and writes nothing. | constraint | §3 "Supported transitions" | must |
| R24 | No create or update path produces `APPROVED` or `REJECTED`; only the decision path does. | constraint | §3 "Performing CRUD operations must not automatically approve" | must |
| R25 | Approval and publication are separate persisted values, and approving never publishes. | constraint | §4 "Keep approval status separate from publication status" | must |
| R26 | An approved product can be published and unpublished explicitly; a non-approved product cannot be published. | functional | §4 "An approved product may remain a draft until explicitly published" | must |
| R27 | A pending or rejected product is absent from every public read, whatever its publication flag says. | constraint | §4 "must never be publicly accessible" | must |
| R28 | Approving a category publishes none of its products. | constraint | §4 "An approved category must not automatically publish its associated products" | must |
| R29 | A product is publicly reachable only when the product is approved and published and its category is approved and visible. | constraint | §4 last bullet | must |
| R30 | The R27–R29 predicates hold on every public surface that can reach a product, a category, a count or a search hit, including the search index. | constraint | §4 "across all relevant public API endpoints" | must |
| R31 | Public responses expose no approval status, submitter, reviewer, decision timestamp or rejection reason. | constraint | §5 "Prevent unauthorized users from accessing administrative data through public endpoints" | must |
| R32 | Only a Super Admin can approve or reject, enforced server-side on the decision routes independently of payload. | constraint | §5 | must |
| R33 | Every path that reaches a product or category — single-resource, alternative admin route, bulk-like operation — applies the same authorization and ownership rules. | constraint | §5 "individual resources, bulk operations, and alternative API operations" | must |
| R34 | Ownership and role are decided from the authenticated principal and the stored submitter, never from a client-supplied identifier. | constraint | §5 "backend must validate every operation regardless of the request payload" | must |
| R35 | Submission creation, submission and resubmission, Super Admin modification, approval, rejection and deletion each produce exactly one audit entry. | functional | §6 | must |
| R36 | Each audit entry carries the action, the affected record, the acting user and the timestamp; a rejection entry carries the reason. | functional | §6 "acting user's identity and action timestamp ... Rejection reasons when applicable" | must |
| R37 | A refused action writes no audit entry and leaves the row unchanged. | constraint | §6 + §7 consistency | must |
| R38 | The audit write commits in the same transaction as the change it describes. | constraint | §7 "Use transactions where required for consistency" | must |
| R39 | Auditing reuses the codebase's existing audit infrastructure rather than a parallel, unrelated mechanism. | constraint | §6 "Use the existing audit infrastructure if available" | must |
| R40 | Only the schema that is genuinely missing is added; no duplicate approval or publication field is introduced. | constraint | §7 "Introduce or extend approval fields only where necessary" | must |
| R41 | The migration is safe and reversible: existing rows survive, currently public rows stay public, and a second run is a no-op. | constraint | §7 "safe migrations that preserve existing records and their current publication behavior" | must |
| R42 | Product-category references stay consistent through create, update and delete of either side. | constraint | §7 "Handle product-category relationships safely" | must |
| R43 | Tests cover Admin product and category submission. | quality | §8 bullet 1 | must |
| R44 | Tests cover Super Admin CRUD on submissions created by another Admin. | quality | §8 bullet 2 | must |
| R45 | Tests cover unauthorized CRUD and approval attempts. | quality | §8 bullet 3 | must |
| R46 | Tests cover explicit approval and rejection. | quality | §8 bullet 4 | must |
| R47 | Tests cover editing a submission before approval. | quality | §8 bullet 5 | must |
| R48 | Tests cover the reapproval rule for a modified approved record. | quality | §8 bullet 6 | must |
| R49 | Tests cover rejection reasons and resubmission. | quality | §8 bullet 7 | must |
| R50 | Tests cover deletion constraints and product-category dependencies. | quality | §8 bullet 8 | must |
| R51 | Tests cover public API visibility restrictions. | quality | §8 bullet 9 | must |
| R52 | Tests cover bypass attempts through alternative endpoints. | quality | §8 bullet 10 | must |
| R53 | Tests cover existing functionality and regressions in the touched areas. | quality | §8 bullet 11 | must |
| R54 | The relevant suites are executed and the actual output is reported, including any remaining failure. | quality | §8 "Execute the relevant tests and report the actual results"; "Do not claim successful implementation or testing without evidence" | must |
| R55 | Available static analysis and architecture checks pass for the touched code. | quality | §9.6 | should |
| R56 | No frontend path is modified. | constraint | "Backend only; do not modify frontend code" | must |
| R57 | No unrelated refactoring and no new dependency. | constraint | Strict constraints | must |
| R58 | The existing create/update/delete and approval paths are extended, not duplicated, and no second approval mechanism appears. | constraint | "Do not duplicate existing CRUD or approval functionality" | must |
| R59 | The final report lists changed files, migrations, executed results and remaining limitations. | quality | §9.7 | must |

## Assumptions

| # | Assumption | Risk if wrong |
|---|------------|---------------|
| 1 | `ROLE_ADMIN` is the regular Admin and `ROLE_SUPER_ADMIN` the Super Admin, per the existing role hierarchy and `/api/v1/admin` access control. | low |
| 2 | A submission is the product or category row plus approval columns; no separate staging entity. | med |
| 3 | The existing `hidden` flag is the category publication control; no second category publication flag. | med |
| 4 | Products need their own publication flag because they have none today. | low |
| 5 | Legacy rows (migration, fixtures, seed SQL) are approved and published so the storefront looks unchanged. | med |
| 6 | Rejection reason is optional free text and length-bounded. | low |
| 7 | Regular Admins cannot delete submissions; §1 lists no delete for them and §2 grants delete to the Super Admin. | med |
| 8 | No bulk admin endpoint exists, so "bulk operations" means: any alternative route that can already reach these records obeys the same rules, and none is added. | low |
| 9 | A published-then-rejected product disappears from the storefront immediately. | low |
| 10 | Category-level and reference-level public reads are part of "every relevant public endpoint" (R30), not only product endpoints. | med |
| 11 | Whenever a Super Admin saves a content change, the record requires reapproval; no finer-grained rule exists. | med |

## Open questions

- [minor] Is a rejection reason mandatory? Default: optional, length-bounded, audited when present.
- [minor] May a regular Admin change the slug of their own submission? Default: existing slug rules apply unchanged.
- [minor] Does the storefront keep serving an approved-but-unpublished product through the search index? Default: no — the index is synchronised with the same predicate.
- [minor] Should legacy admin routes that predate the workflow be removed? Default: no, they keep working under the same rules.
- [minor] Who may publish and unpublish? Default: product publication is Super-Admin-only (the only existing publisher), and category visibility keeps its current creator-scoped access rule; the spec widens neither.

None block: each has a default consistent with the prompt.

## Out of scope

- Any frontend, dashboard or storefront client change (`Zidane_front_web/`, templates, assets).
- Notifying the submitting Admin by email or another channel.
- Approval states beyond the three named, workflow engines, multi-reviewer or delegated approval.
- Approval of banners, brands, promotions, orders, payments or inventory.
- New bulk admin endpoints.
- Backfilling audit history for actions before this feature.
- Re-designing catalog storage beyond the fields the workflow needs.
- Removing or restructuring the legacy admin routes.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Classify every red test as harness fault or product fault | R43–R54 (diagnosis) | `tests/Functional/AdminCatalogApprovalWorkflowTest.php`, `spec-run-10/baseline-approval-functional.log` | A written verdict per failure with a minimal reproduction | `spec-run-10/**` | none | Each of the 8 tests is classified, and no product fix is attempted before the classification exists |
| WP2 | Make actor switching reliable | R43–R53 | WP1 | Test identities that are provably distinct inside one test | `tests/Functional/AdminCatalogApprovalWorkflowTest.php` | WP1 | Two actors in one test receive their own data, and the previously red file is green |
| WP3 | Verify the authorization gaps the diagnosis claimed | R4, R9, R10, R11, R32–R34 | WP1, WP2 | Owner-scoped reads and writes proven from the stored submitter, for both kinds | `src/Catalog/**` (only if a real gap survives) | WP2 | Tests prove foreign reads answer not-found, foreign writes forbidden, decisions Super-Admin-only, payload approval fields ignored |
| WP4 | Super Admin CRUD across states | R12–R18 | Prompt §2, §3 | Create/read/update/delete over any state, plus the review queue with status filtering | `src/Catalog/Infrastructure/Http/**`, `config/routes/**` (verify only) | WP3 | Tests prove CRUD on another Admin's record in each state, refused deletes leave data intact, no CRUD returns `APPROVED` |
| WP5 | Decisions and publication independence | R20–R28, R32, R33 | Prompt §3, §4, §5 | Approve/reject and publish/unpublish gated correctly, nothing auto-approved or auto-published | `src/Catalog/Application/CommandHandler/**` (verify only) | WP4 | Tests prove reviewer/timestamp/reason storage, refused illegal transitions, publishing refused for non-approved products, category approval publishing nothing |
| WP6 | Public reachability and non-leakage | R29–R31 | Prompt §4, §5 | One visibility predicate shared by browse, detail, slug lookup, counts, search index, taxonomy and references, with no approval fields in public payloads | `src/Catalog/Infrastructure/ReadModel/**`, `.../Search/**`, `.../Projection/**`, `src/Shared/Infrastructure/**` (verify only) | WP1 | Tests prove pending, rejected, unpublished and parent-blocked records are absent everywhere public, approved+published present, and no public body carries approval metadata |
| WP7 | Audit trail | R35–R39 | Prompt §6 | One audit write path per significant action, committed with the change | `src/Catalog/Application/Port/**`, `src/Catalog/Infrastructure/Audit/**` (verify only) | WP5 | Tests read `catalog_audit_log` directly: one row per action with actor, timestamp, reason; a refused action writes none |
| WP8 | Evidence | R43–R55, R59 | Prompt §8, §9 | Full suite and static-analysis output, coverage table per bullet, honest gaps | `tests/**`, `spec-run-10/**` | WP2–WP7 | Each §8 bullet maps to a test with its executed result; remaining failures are listed as limitations |
| WP9 | Constraint verification | R56–R58 | Strict constraints | Changed-file audit and report | none | WP8 | No frontend path in the diff, no new dependency, no duplicated CRUD or decision path |

## Decisions (synthesizer only)

| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| What the red suite proves | silent (assumed code defects) | harness fault plus at least one product fault | two symptoms point in opposite directions; treat each on evidence | Harness fault, proven by reproduction: two test users minted in the same millisecond shared the same e-mail prefix, and the session provider resolves a user by e-mail, so the second actor authenticated as the first | The reproduction (unique e-mail per user → all 8 tests green with no `src/**` change) is decisive evidence; the prompt demands no fix before classification |
| Submission model | row + metadata | row + metadata | row + metadata | Row + approval columns, no staging table | All three analysts agree and it matches the existing schema |
| Category publication control | existing visibility flag | existing `hidden` flag | existing `hidden` flag, high risk | Reuse `hidden`; add no second flag | R40 forbids duplicate fields; the storefront already reads `hidden` |
| Product publication | separate flag | separate flag | separate flag, absent today | Add one `published` flag | The storefront has no product-level publish switch today, so R26 is unimplementable without it |
| Who publishes a product | silent | silent | open question, default keeps today's behaviour | Super Admin only; category visibility keeps its current rule | Preserves current access and avoids widening or narrowing authority without evidence |
| Reapproval trigger | any Super Admin modification | any modification requiring reapproval | any content modification counts | Any content modification by a Super Admin reopens the record | No finer classification exists; simplest rule that satisfies §3 |
| Rejection reason | optional | optional, bounded | optional, bounded, audited when present | Optional, bounded, audited when present | §6 says "when applicable"; unbounded text is an integrity risk |
| Admin delete | not granted | not granted | not granted | Super Admin only | §1 grants no delete; §2 grants it to the Super Admin |
| Legacy rows | approved and published | approved and published | approved and published | Approved and published | R41 requires unchanged public visibility |
| Bulk operations | existing only | existing only | existing only, none added | Obey the same rules; add no endpoint | §5 asks for consistency, not new surface |
| Audit infrastructure | reuse | reuse, one new table if unavoidable | reuse or document why | Reuse the established audit pattern with `catalog_audit_log` and one port | All three converge; a single write path keeps R37/R38 provable |

## Changelog (synthesizer and orchestrator only)

- v1: created from analyst-a, analyst-b and analyst-c for spec-run-10; the two blocking questions were resolved by reproduction rather than by asking the user, because the evidence was obtainable in the working tree.

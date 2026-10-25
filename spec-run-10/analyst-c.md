# Spec: Super Admin Management and Approval Workflow for Products and Categories

Version: 1

## Goal

A reviewer can trust that nothing an Admin creates is public before an explicit Super Admin decision, and that nothing the catalogue shows is stale, unapproved or leaky — with the ambiguity about "submit", "publish" and "reapproval" settled once instead of guessed per endpoint.

## Corrected prompt

Implement a backend approval gate for catalog products and categories: regular Admins create submissions that start `PENDING_APPROVAL`, see their own status and rejection reason, edit pending or rejected submissions and resubmit rejected ones; the Super Admin performs full CRUD over submissions in any state and is the only role that approves or rejects; approval is one of three states with the five listed transitions and is never a side effect of CRUD; approval and publication are separate and the public surfaces enforce both plus the category condition; every operation is authorized server-side regardless of payload or route; significant actions are audited with actor, timestamp and reason; the schema grows only where needed, migrations are safe, and tests are added, executed and reported with evidence.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | Creating a product through any backend path yields `PENDING_APPROVAL` unless the payload is rejected; no payload value changes that. | constraint | §1 "New submissions must default to `PENDING_APPROVAL`, enforced by the backend" | must |
| R2 | Creating a category through any backend path yields `PENDING_APPROVAL`; no payload value changes that. | constraint | §1 | must |
| R3 | The creator of a submission is recorded and is the only regular Admin who may read its approval status and rejection reason. | functional | §1 "View the approval status of their submissions" + §5 | must |
| R4 | A regular Admin may edit their own submission while it is pending or rejected, under the existing content rules. | functional | §1 | must |
| R5 | A regular Admin may resubmit their own rejected submission; resubmission moves it to `PENDING_APPROVAL`. | functional | §1 + §3 | must |
| R6 | An approved submission is not editable by a regular Admin. | constraint | §1 (edit is limited to pending or rejected) + §5 | must |
| R7 | No regular Admin can approve or reject any submission, own included. | constraint | §1 + §5 | must |
| R8 | Approval fields present in a request payload are never persisted, on any route, including create and update. | constraint | §1 + §5 + "Do not trust client-provided approval metadata" | must |
| R9 | The Super Admin can create, read, update and delete products across all approval and publication states. | functional | §2 Products | must |
| R10 | The Super Admin can create, read, update and delete categories across all approval states. | functional | §2 Categories | must |
| R11 | Deletion is refused, with nothing written, when an existing constraint forbids it (orders referencing a product, products referencing a category, non-empty category tree). | constraint | §2 "subject to existing business constraints" + §7 | must |
| R12 | Every existing content rule still applies to submissions and to Super Admin edits. | constraint | §2 "Existing domain rules, data integrity constraints, and referential integrity must be preserved" | must |
| R13 | The only approval values are `PENDING_APPROVAL`, `APPROVED`, `REJECTED`; an unknown stored value is a hard error, never a silent default. | constraint | §3 | must |
| R14 | `PENDING_APPROVAL` → `APPROVED` and `PENDING_APPROVAL` → `REJECTED` are available only to a Super Admin and record reviewer and decision time; rejection also records the reason when present. | functional | §3 + §5 + §6 | must |
| R15 | `REJECTED` → `PENDING_APPROVAL` happens only through the owner's resubmission. | constraint | §3 | must |
| R16 | `APPROVED` → `PENDING_APPROVAL` happens when a later modification requires reapproval, and the stale review metadata is cleared. | functional | §3 | must |
| R17 | Every other transition is refused with a typed refusal and no side effect, including approve-after-approve and reject-after-reject. | constraint | §3 "Supported transitions" | must |
| R18 | No create or update path can produce `APPROVED` or `REJECTED`. | constraint | §3 "Performing CRUD operations must not automatically approve a product or category" | must |
| R19 | Approval and publication are independent columns; approving never changes publication and publishing never changes approval. | constraint | §4 | must |
| R20 | A pending or rejected record is unreachable publicly even if its publication flag is on. | constraint | §4 | must |
| R21 | An approved product is publicly unreachable while unpublished, and reachable once published. | functional | §4 | must |
| R22 | Approving a category leaves the publication state of its products untouched. | constraint | §4 | must |
| R23 | A product whose category is unapproved or unpublished is publicly unreachable. | constraint | §4 | must |
| R24 | Every public surface applies R20–R23: product detail, browse, filters, counts, search results, search suggestions, category/taxonomy reads, reference reads and any cached or indexed representation. | constraint | §4 "across all relevant public API endpoints" | must |
| R25 | No public response carries approval status, submitter, reviewer, decision timestamps or rejection reason. | constraint | §5 | must |
| R26 | Only a Super Admin can decide, and only a Super Admin can write on another Admin's submission; the check is server-side and independent of payload. | constraint | §5 | must |
| R27 | Ownership and role are derived from the authenticated principal and stored data, never from request fields. | constraint | §5 | must |
| R28 | Alternative and legacy routes that can reach these records obey the same rules; none offers a bypass of approval or authorization. | constraint | §5 "individual resources, bulk operations, and alternative API operations" | must |
| R29 | Nothing an unauthenticated or non-admin caller sends can reach administrative catalogue data. | constraint | §5 | must |
| R30 | Creation, submission, resubmission, Super Admin modification, approval, rejection and deletion each write exactly one audit entry with action, target, actor and timestamp; rejection entries carry the reason. | functional | §6 | must |
| R31 | A refused operation writes no audit entry and changes no row. | constraint | §6 + §7 | must |
| R32 | The audit entry commits with the change it describes, not before and not after. | constraint | §7 "Use transactions where required" | must |
| R33 | Audit reuses existing infrastructure or documents, once, why it cannot. | constraint | §6 "Use the existing audit infrastructure if available" | must |
| R34 | Only genuinely missing schema is added, and any new column has a defined default for existing rows. | constraint | §7 | must |
| R35 | Migrations are reversible, idempotent in effect, and preserve every existing row and its current public visibility. | constraint | §7 | must |
| R36 | Product-category integrity holds through create/update/delete of either side, including category tree edits that would orphan a product sub-level. | constraint | §7 "Handle product-category relationships safely" | must |
| R37 | Tests exist and pass for: Admin product and category submission. | quality | §8 bullet 1 | must |
| R38 | Tests exist and pass for: Super Admin CRUD on another Admin's submission. | quality | §8 bullet 2 | must |
| R39 | Tests exist and pass for: unauthorized CRUD and approval attempts. | quality | §8 bullet 3 | must |
| R40 | Tests exist and pass for: explicit approval and rejection. | quality | §8 bullet 4 | must |
| R41 | Tests exist and pass for: editing a submission before approval. | quality | §8 bullet 5 | must |
| R42 | Tests exist and pass for: reapproval of a modified approved record. | quality | §8 bullet 6 | must |
| R43 | Tests exist and pass for: rejection reason and resubmission. | quality | §8 bullet 7 | must |
| R44 | Tests exist and pass for: deletion constraints and product-category dependencies. | quality | §8 bullet 8 | must |
| R45 | Tests exist and pass for: public visibility restrictions. | quality | §8 bullet 9 | must |
| R46 | Tests exist and pass for: bypass attempts through alternative endpoints. | quality | §8 bullet 10 | must |
| R47 | Tests exist and pass for: existing functionality and regressions in touched areas. | quality | §8 bullet 11 | must |
| R48 | The executed output is reported as it is; a red or skipped test is reported, not rounded up to success. | quality | §8 + "Do not claim successful implementation or testing without evidence" | must |
| R49 | Static analysis and architecture checks are run and their real result reported. | quality | §9.6 | should |
| R50 | No frontend file is touched. | constraint | Scope | must |
| R51 | No new dependency and no unrelated refactor. | constraint | Strict constraints | must |
| R52 | Existing CRUD and approval paths are extended, not duplicated. | constraint | Strict constraints | must |
| R53 | The report enumerates changed files, the migration, the executed results and what remains unproven. | quality | §9.7 | must |

## Assumptions

| # | Assumption | Risk if wrong |
|---|------------|---------------|
| 1 | The regular Admin is `ROLE_ADMIN` and the Super Admin `ROLE_SUPER_ADMIN`, following the existing hierarchy. | low |
| 2 | A "submission" is the product or category row plus approval metadata, not a separate staging table. | med |
| 3 | The existing category `hidden` flag doubles as the category publication control. | high |
| 4 | Products need a new publication flag, absent today. | med |
| 5 | Every content modification by a Super Admin counts as "requires reapproval"; no finer classification exists. | high |
| 6 | Legacy rows are treated as approved and published. | med |
| 7 | A regular Admin cannot delete; the prompt grants deletion only to the Super Admin. | med |
| 8 | "Bulk operations" means whatever already exists; no bulk endpoint is created. | low |

## Open questions

- [BLOCKING] The existing approval functional suite is red (1 error, 7 failures, `spec-run-10/baseline-approval-functional.log`) and two of its symptoms point in opposite directions: repeated `401 Authentication required` after switching actors suggests a test-harness fault, while a foreign-Admin read that returned `200` with the other Admin's `submittedBy` suggests a real authorization fault. Which is it? Default if unanswered: treat them separately — reproduce each with a minimal test, fix the harness where it is the harness, and treat any surviving leak as a product defect. Nothing may be declared green on the strength of a suite that cannot authenticate its second actor.
- [BLOCKING] Who may publish and unpublish? The prompt separates publication from approval but never names the role allowed to flip it, and the current code lets a regular Admin hide/unhide a category while product publication requires the Super Admin. Default if unanswered: keep today's behaviour for categories (creator-scoped by the existing access policy) and Super-Admin-only for product publication, and state this explicitly in the spec rather than silently widening or narrowing access.
- [minor] If creation already lands as `PENDING_APPROVAL`, what does the separate "submit for review" step mean? Default: the status does not change; a submission event is recorded so the reviewer's queue is ordered by submission time.
- [minor] Does rejecting an already published product flip its publication flag, or only hide it through the approval check? Default: the flag is untouched and the approval predicate hides it; the admin payload must show both values so the difference is visible.
- [minor] Can a Super Admin read the approval metadata of a record through the pre-existing admin product/category endpoints, or only through the submission endpoints? Default: both, consistently, and never publicly.
- [minor] Is a rejection reason required for rejection? Default: optional but bounded in length, and stored/audited only when present.
- [minor] When a Super Admin creates a record directly, does it stay pending? Default: pending — §3 forbids CRUD from approving, with no exception stated for the Super Admin.
- [minor] Is the new approval audit table acceptable as "existing infrastructure"? Default: reuse the established audit pattern; if a new table is unavoidable, record the reason as a decision and keep one write path.
- [minor] Do cart, checkout, quote and review endpoints count as public surfaces for R24? Default: yes — any public endpoint that resolves a product or category by slug must apply the same predicate, otherwise it becomes the leak.

None of the BLOCKING questions needs a product decision from the user: both can be resolved by investigation or by preserving current behaviour. They are marked BLOCKING only because guessing them would send implementation in the wrong direction.

## Out of scope

- Frontend, admin dashboard or storefront client changes.
- Notifications to the submitting Admin.
- More approval states, workflow engines, multi-reviewer or delegation.
- Approval for banners, brands, promotions, orders, payments, inventory.
- New bulk admin endpoints.
- Historical audit backfill.
- Restructuring catalog storage beyond the workflow fields.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Establish what is actually broken | R37–R47 (diagnosis), Q1 | `tests/Functional/AdminCatalogApprovalWorkflowTest.php`, `spec-run-10/baseline-approval-functional.log` | A written, evidence-backed verdict per failure: harness fault or product fault, with the minimal reproduction | `spec-run-10/**` | none | Each of the 8 tests is classified with its root cause and a reproduction, and no fix is attempted before the classification exists |
| WP2 | Make actor switching reliable in functional tests | R37–R47 | WP1 | A test authentication approach that provably yields two distinct identities in one test | `tests/**` | WP1 | Two actors in one test receive distinguishable responses, proven by a test that asserts each identity's own data |
| WP3 | Close the authorization gaps the diagnosis proves | R3–R8, R26–R29 | WP1, WP2 | Owner-scoped reads/writes and role checks fixed at their real cause | `src/Catalog/**` | WP2 | Tests prove foreign reads answer not-found, foreign writes forbidden, decisions Super-Admin-only, payload approval fields ignored |
| WP4 | Settle publication authority | R19–R23, Q2 | Prompt §4 | One documented rule for who publishes what, enforced on both kinds | `src/Catalog/**` | WP3 | Tests prove the documented rule for product publish/unpublish and category visibility, and that approval never publishes |
| WP5 | Close every public leak path | R24–R25 | Prompt §4, §5 | One predicate applied to detail, browse, counts, search, categories, references and any slug-resolving public endpoint | `src/Catalog/**`, `src/Shared/**` | WP1 | Tests prove a pending, rejected, unpublished or parent-blocked product is unreachable through every public path found in the inventory, and no public body carries approval fields |
| WP6 | Audit exactly once per action | R30–R33 | Prompt §6 | One write path per significant action inside the same transaction | `src/Catalog/**` | WP3 | Tests read the audit table directly and count entries per action, including the refused-action case |
| WP7 | Safe schema | R34–R36 | Prompt §7 | Verified schema, plus one extra safe migration only if a real gap exists | `migrations/**` | none | Up/down executed on a database with pre-existing rows; existing visibility unchanged; no duplicate flag |
| WP8 | Evidence | R37–R53 | Prompt §8, §9 | Full test and static-analysis output, coverage table per bullet, honest gaps | `tests/**`, `spec-run-10/**` | WP2–WP7 | Each §8 bullet maps to a test with its executed result; remaining failures are listed as limitations |

## Decisions (synthesizer only)

## Changelog (synthesizer and orchestrator only)

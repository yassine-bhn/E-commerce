# Spec: Super Admin Management and Approval Workflow for Products and Categories

Version: 1

## Goal

A regular Admin can submit products and categories that stay out of the public catalogue until a Super Admin explicitly approves them; the Super Admin has full backend CRUD over those submissions and is the only role that can approve, reject or resubmit-review; approval and publication stay separate; every significant action is audited.

## Corrected prompt

Implement, backend only, a workflow in which regular Admins submit products and categories and a Super Admin performs full CRUD on those submissions and then manages their approval lifecycle. New submissions are `PENDING_APPROVAL` by backend rule. Only a Super Admin approves or rejects. Approval is never implied by a CRUD operation and publication is a separate concern. Every operation is authorized on the backend, client-supplied approval metadata is never trusted, significant actions are audited, migrations are safe, tests are added and executed, and the results are reported with evidence.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A regular Admin can create a product. | functional | §1 "Create products and categories" | must |
| R2 | A regular Admin can create a category. | functional | §1 "Create products and categories" | must |
| R3 | A regular Admin can submit a product they created for Super Admin review. | functional | §1 "Submit newly created products and categories for Super Admin review" | must |
| R4 | A regular Admin can submit a category they created for Super Admin review. | functional | §1 | must |
| R5 | A regular Admin can view the approval status of their own submissions. | functional | §1 "View the approval status of their submissions" | must |
| R6 | A regular Admin can view the rejection reason when one is provided. | functional | §1 "View rejection reasons when provided" | must |
| R7 | A regular Admin can edit their own `PENDING_APPROVAL` submissions under the existing business rules. | functional | §1 "Edit their pending or rejected submissions according to existing business rules" | must |
| R8 | A regular Admin can edit their own `REJECTED` submissions under the existing business rules. | functional | §1 | must |
| R9 | A regular Admin can resubmit a `REJECTED` submission after making corrections. | functional | §1 "Resubmit rejected submissions after making corrections" | must |
| R10 | A new product submission is stored as `PENDING_APPROVAL` by the backend, independent of the request payload. | constraint | §1 "New submissions must default to `PENDING_APPROVAL`, enforced by the backend" | must |
| R11 | A new category submission is stored as `PENDING_APPROVAL` by the backend, independent of the request payload. | constraint | §1 | must |
| R12 | A regular Admin cannot approve their own submission. | constraint | §1 "Regular Admins must not be able to approve their own submissions" | must |
| R13 | A regular Admin cannot set approval metadata (status, reviewer, approval timestamps, rejection reason) through any API payload. | constraint | §1 "or manipulate approval metadata through API payloads" | must |
| R14 | A Super Admin can create a product directly. | functional | §2 Products "Create" | must |
| R15 | A Super Admin can create a category directly. | functional | §2 Categories "Create" | must |
| R16 | A Super Admin can read complete product details for `PENDING_APPROVAL`, `APPROVED`, `REJECTED` and published records. | functional | §2 Products "Read" | must |
| R17 | A Super Admin can read complete category details across all approval states. | functional | §2 Categories "Read" | must |
| R18 | A Super Admin can update product information submitted by an Admin before approval. | functional | §2 Products "Update" | must |
| R19 | A Super Admin can update submitted category information before or during the approval process. | functional | §2 Categories "Update" | must |
| R20 | A Super Admin can delete a submitted product, subject to existing business constraints. | functional | §2 Products "Delete" | must |
| R21 | A Super Admin can delete a submitted category, subject to existing business constraints and product dependencies. | functional | §2 Categories "Delete" | must |
| R22 | Every CRUD operation is authorized on the backend. | constraint | §2 "All CRUD operations must be authorized on the backend" | must |
| R23 | Existing domain rules, data-integrity constraints and referential integrity are preserved by every new operation. | constraint | §2 | must |
| R24 | The approval states are exactly `PENDING_APPROVAL`, `APPROVED` and `REJECTED`. | functional | §3 "Supported approval states" | must |
| R25 | A Super Admin can transition `PENDING_APPROVAL` to `APPROVED`; the decision is recorded with reviewer identity and time. | functional | §3 "`PENDING_APPROVAL` → `APPROVED`" | must |
| R26 | A Super Admin can transition `PENDING_APPROVAL` to `REJECTED`; the decision is recorded with reviewer identity, time and rejection reason when supplied. | functional | §3 "`PENDING_APPROVAL` → `REJECTED`" | must |
| R27 | `REJECTED` returns to `PENDING_APPROVAL` through correction and resubmission. | functional | §3 "`REJECTED` → `PENDING_APPROVAL` after correction and resubmission" | must |
| R28 | `APPROVED` returns to `PENDING_APPROVAL` when a subsequent modification requires reapproval under the established business rules. | functional | §3 "`APPROVED` → `PENDING_APPROVAL` if a subsequent modification requires reapproval" | must |
| R29 | No CRUD operation transitions a product or category to `APPROVED`. | constraint | §3 "Performing CRUD operations must not automatically approve" | must |
| R30 | A Super Admin can approve or reject a submission after reviewing it, and after modifying it when necessary. | functional | §3 "must be able to approve or reject submissions after reviewing them and, when necessary, modifying their information" | must |
| R31 | Approval status and publication status are persisted as separate, independent values. | constraint | §4 "Keep approval status separate from publication status" | must |
| R32 | An approved product can remain unpublished until explicitly published. | functional | §4 "An approved product may remain a draft until explicitly published" | must |
| R33 | A `PENDING_APPROVAL` or `REJECTED` product is never publicly accessible. | constraint | §4 "A pending or rejected product must never be publicly accessible" | must |
| R34 | Approving a category does not publish the products associated with it. | constraint | §4 "An approved category must not automatically publish its associated products" | must |
| R35 | A product is not publicly accessible when its category is unapproved or unpublished. | constraint | §4 "A product must not be publicly accessible if its category is unapproved or unpublished" | must |
| R36 | The visibility restrictions of R33–R35 hold on every relevant public API endpoint. | constraint | §4 "Enforce these restrictions across all relevant public API endpoints" | must |
| R37 | Only a Super Admin can approve or reject products and categories. | constraint | §5 bullet 1 | must |
| R38 | Only a Super Admin can perform privileged CRUD operations on submissions created by other Admins. | constraint | §5 bullet 2 | must |
| R39 | Regular Admins cannot manipulate approval statuses, reviewer identities or approval timestamps. | constraint | §5 bullet 3 | must |
| R40 | The backend validates every operation regardless of the request payload or the endpoint used. | constraint | §5 bullet 4 | must |
| R41 | Authorization is applied consistently to individual resources, bulk operations and alternative API operations. | constraint | §5 bullet 5 | must |
| R42 | Unauthorized users cannot reach administrative data through public endpoints. | constraint | §5 bullet 6 | must |
| R43 | Submission creation and resubmission are recorded in the audit trail. | functional | §6 "Submission creation and resubmission" | must |
| R44 | Super Admin modifications are recorded in the audit trail. | functional | §6 "Super Admin modifications" | must |
| R45 | Approval and rejection decisions are recorded in the audit trail. | functional | §6 | must |
| R46 | Deletions are recorded in the audit trail. | functional | §6 "Deletions" | must |
| R47 | Every audit record carries the acting user's identity and the action timestamp. | functional | §6 "The acting user's identity and action timestamp" | must |
| R48 | Rejection reasons are recorded in the audit trail when applicable. | functional | §6 "Rejection reasons when applicable" | must |
| R49 | The existing audit infrastructure is used when one is available. | constraint | §6 "Use the existing audit infrastructure if available" | must |
| R50 | Approval fields are introduced or extended only where necessary. | constraint | §7 "Introduce or extend approval fields only where necessary" | must |
| R51 | Server-controlled statuses cannot be overridden by a regular Admin. | constraint | §7 "Ensure server-controlled statuses cannot be overridden by regular Admins" | must |
| R52 | Product-category relationships are handled safely during create, update and delete. | constraint | §7 "Handle product-category relationships safely" | must |
| R53 | Transactions are used where consistency requires them. | constraint | §7 "Use transactions where required for consistency" | must |
| R54 | Migrations are safe: existing records are preserved and their current publication behaviour is retained where appropriate. | constraint | §7 "Create safe migrations that preserve existing records and their current publication behavior where appropriate" | must |
| R55 | Backend tests verify Admin product and category submissions. | quality | §8 bullet 1 | must |
| R56 | Backend tests verify Super Admin CRUD on submissions created by other Admins. | quality | §8 bullet 2 | must |
| R57 | Backend tests verify unauthorized CRUD and approval attempts. | quality | §8 bullet 3 | must |
| R58 | Backend tests verify explicit approval and rejection. | quality | §8 bullet 4 | must |
| R59 | Backend tests verify editing submissions before approval. | quality | §8 bullet 5 | must |
| R60 | Backend tests verify reapproval rules for modified approved records. | quality | §8 bullet 6 | must |
| R61 | Backend tests verify rejection reasons and resubmission. | quality | §8 bullet 7 | must |
| R62 | Backend tests verify deletion constraints and product-category dependencies. | quality | §8 bullet 8 | must |
| R63 | Backend tests verify public API visibility restrictions. | quality | §8 bullet 9 | must |
| R64 | Backend tests verify attempts to bypass approval through alternative endpoints. | quality | §8 bullet 10 | must |
| R65 | Backend tests verify existing functionality and regression scenarios. | quality | §8 bullet 11 | must |
| R66 | The relevant tests are executed and the actual results are reported. | quality | §8 "Execute the relevant tests and report the actual results" | must |
| R67 | A concise implementation plan is presented before implementation. | quality | §9.3 | must |
| R68 | The existing entities, services, repositories, API operations, authorization rules and tests are identified and reused. | quality | §9.1, §9.2, §7 | must |
| R69 | The existing architecture and conventions are reused. | constraint | §7 "Reuse existing architecture and conventions" | must |
| R70 | Necessary migrations and tests are added. | functional | §9.5 | must |
| R71 | Available code-quality checks are run. | quality | §9.6 | should |
| R72 | The report lists changed files, migrations, test results and remaining limitations. | quality | §9.7 | must |
| R73 | No frontend code is modified. | constraint | "Backend only; do not modify frontend code" | must |
| R74 | No unrelated refactoring is introduced. | constraint | Strict constraints | must |
| R75 | No unnecessary dependency is introduced. | constraint | Strict constraints | must |
| R76 | Existing CRUD or approval functionality is not duplicated. | constraint | "Do not duplicate existing CRUD or approval functionality" | must |
| R77 | Submissions are not automatically approved after CRUD operations. | constraint | "Do not automatically approve submissions after CRUD operations" | must |
| R78 | Client-provided approval metadata is not trusted. | constraint | "Do not trust client-provided approval metadata" | must |
| R79 | Success is not claimed without evidence. | quality | "Do not claim successful implementation or testing without evidence" | must |

## Assumptions

| # | Assumption | Risk if wrong |
|---|------------|---------------|
| 1 | "Regular Admin" and "Super Admin" map to roles that already exist in the Identity context. | low |
| 2 | The submission is the product or category record itself plus approval metadata, not a separate staging entity. | med |
| 3 | A rejected record stays rejected after an edit and only an explicit resubmission returns it to pending. | med |
| 4 | The rejection reason is optional free text. | low |
| 5 | Deletion is a Super Admin capability only; §1 does not grant it to regular Admins. | med |
| 6 | "Bulk operations" refers to endpoints that already exist; no new bulk API is implied. | low |

## Open questions

- [minor] Which existing column expresses category publication? Default: whatever the current visibility/hidden flag is; no second flag is added.
- [minor] Does a Super Admin modification of an approved record take it off the storefront immediately? Default: yes, it returns to `PENDING_APPROVAL` and is therefore not publicly accessible.
- [minor] Is a rejection reason mandatory on rejection? Default: optional.

None of these block: each has a default consistent with the prompt.

## Out of scope

- Frontend, dashboard or storefront client changes.
- Notifying the submitting Admin of a decision by email or other channel.
- Approval states beyond the three named, workflow engines, multi-reviewer or delegated approval.
- Approval workflows for banners, brands, promotions, orders, payments or inventory.
- New bulk admin endpoints.
- Backfilling audit history for actions before this feature.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Persist approval separately from publication | R10, R11, R24, R31, R50, R53, R54 | Prompt §3, §4, §7 | Safe migration plus the persisted approval metadata and product publication state | `migrations/**` | none | Migration up/down run clean on a database with existing rows; existing rows keep their current public visibility; no approval column is nullable-ambiguous |
| WP2 | Encode the lifecycle once | R24–R29 | Prompt §3 | Closed status type with the allowed transitions and typed refusals | `src/Catalog/Domain/**` | WP1 | Every allowed transition accepted and every other refused; no CRUD path yields `APPROVED` |
| WP3 | Admin submission workflow | R1–R9, R12 | Prompt §1 | Commands/handlers for create, submit, edit and resubmit plus own-status reads | `src/Catalog/Application/**` | WP2 | Pending by default; owner-only status; rejected→pending only on resubmission; no Admin route to `APPROVED` |
| WP4 | Super Admin CRUD and decisions | R14–R21, R25, R26, R30, R37, R38 | Prompt §2, §3, §5 | CRUD commands across all states and explicit approve/reject commands | `src/Catalog/Application/**` | WP3 | Super Admin can CRUD another Admin's record and decide it; deletes respect existing constraints; CRUD never approves |
| WP5 | Enforce at the HTTP edge | R13, R22, R39, R40, R41, R42, R51 | Prompt §2, §5 | Admin endpoints with role and ownership checks that strip approval metadata from input | `src/Catalog/Infrastructure/Http/**`, `config/routes/**` | WP4 | Functional tests prove regular-Admin refusal on decision endpoints, foreign writes refused, injected approval fields ignored |
| WP6 | Public reachability follows approval and publication | R32–R36 | Prompt §4 | Approval, publication and parent-category predicates on every public read path and in the search index | `src/Catalog/Infrastructure/ReadModel/**`, `.../Search/**`, `.../Projection/**`, `src/Shared/Infrastructure/**` | WP1 | Pending, rejected, unpublished and parent-blocked products absent from every public read; no approval metadata in public payloads |
| WP7 | Audit | R43–R49 | Prompt §6 | One audit write path used by every workflow handler, committed with the change | audit port and adapter | WP4 | One row per creation, resubmission, Super Admin modification, decision and deletion with actor and timestamp; refused actions write nothing |
| WP8 | Tests and evidence | R55–R66, R70–R72 | Prompt §8, §9 | Unit/integration/projection/functional tests plus captured command output | `tests/**` | WP5, WP6, WP7 | Each test bullet has a passing test or a reported failure; test and quality-check output quoted |
| WP9 | Constraint verification | R67–R69, R73–R79 | Prompt §9, Strict constraints | Plan, constraint check and final report | none | WP8 | No frontend path among changed files, no new dependency, no duplicated CRUD/approval path, report backed by evidence |

## Decisions (synthesizer only)

## Changelog (synthesizer and orchestrator only)

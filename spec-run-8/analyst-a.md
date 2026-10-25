# Spec: Super Admin Management and Approval Workflow for Products and Categories

Version: analyst-a (literal stance)

## Goal

A backend workflow exists in which regular Admins create products and categories that are submitted for Super Admin review, and the Super Admin can create/read/update/delete those submissions before explicitly approving or rejecting them, with approval kept separate from publication and enforced on every public endpoint.

## Corrected prompt

Implement, backend only, an approval workflow for products and categories. Regular Admins create products and categories and submit them for review; the backend forces new submissions to `PENDING_APPROVAL`. Regular Admins can read the approval status and rejection reason of their own submissions, edit their own pending or rejected submissions, and resubmit rejected ones. The Super Admin has full backend CRUD over any submission, including before approval, and is the only role allowed to move a submission to `APPROVED` or `REJECTED`. CRUD never approves anything; approval is always an explicit action. Approval status is stored separately from publication status: a pending or rejected record is never public, an approved record may stay unpublished, an approved category does not publish its products, and a product is public only when it and its category are approved and published. Approval metadata (status, reviewer identity, timestamps, rejection reason) is server-controlled and ignored when present in a request payload. Significant actions are audited with the actor and timestamp, reusing existing audit infrastructure. Existing domain rules, referential integrity and data are preserved by a safe migration. Backend tests cover submissions, Super Admin CRUD, unauthorized attempts, approval/rejection, pre-approval edits, reapproval of modified approved records, rejection reasons and resubmission, deletion constraints, public visibility, bypass attempts and regressions, and the tests are actually executed and reported.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A regular Admin can create a product through the backend. | functional | "Regular Admins can: Create products and categories." | must |
| R2 | A regular Admin can create a category through the backend. | functional | same | must |
| R3 | A newly created product can be submitted for Super Admin review. | functional | "Submit newly created products and categories for Super Admin review." | must |
| R4 | A newly created category can be submitted for Super Admin review. | functional | same | must |
| R5 | A regular Admin can read the approval status of a submission they created. | functional | "View the approval status of their submissions." | must |
| R6 | A regular Admin can edit a submission they created while it is `PENDING_APPROVAL`. | functional | "Edit their pending or rejected submissions according to existing business rules." | must |
| R7 | A regular Admin can edit a submission they created while it is `REJECTED`. | functional | same | must |
| R8 | A regular Admin can resubmit a `REJECTED` submission after correction, moving it back to `PENDING_APPROVAL`. | functional | "Resubmit rejected submissions after making corrections." | must |
| R9 | A regular Admin can read the rejection reason recorded on their submission, when one exists. | functional | "View rejection reasons when provided." | must |
| R10 | Every new Admin submission is persisted with approval status `PENDING_APPROVAL` unless the acting user is a Super Admin performing an explicit approval action. | constraint | "New submissions must default to `PENDING_APPROVAL`, enforced by the backend." | must |
| R11 | A regular Admin cannot move a submission to `APPROVED` or `REJECTED`, including their own. | constraint | "Regular Admins must not be able to approve their own submissions." | must |
| R12 | Approval metadata present in a request payload is ignored by the backend. | constraint | "or manipulate approval metadata through API payloads." | must |
| R13 | The Super Admin can create a product directly. | functional | "Create: Create a product directly when necessary." | must |
| R14 | The Super Admin can read a product in any approval state (`PENDING_APPROVAL`, `APPROVED`, `REJECTED`) and in any publication state. | functional | "Retrieve complete product details, including pending, approved, rejected, and published records." | must |
| R15 | The Super Admin can update an Admin-submitted product before approval. | functional | "Modify product information submitted by an Admin before approval." | must |
| R16 | The Super Admin can delete a submitted product, subject to existing business constraints. | functional | "Delete a submitted product when necessary, subject to existing business constraints." | must |
| R17 | The Super Admin can create a category directly. | functional | "Create: Create a category directly when necessary." | must |
| R18 | The Super Admin can read a category in any approval state. | functional | "Retrieve complete category details across all approval states." | must |
| R19 | The Super Admin can update a submitted category before or during the approval process. | functional | "Modify submitted category information before or during the approval process." | must |
| R20 | The Super Admin can delete a submitted category, subject to existing business constraints and product dependencies. | functional | "Delete a submitted category ... subject to existing business constraints and product dependencies." | must |
| R21 | Every CRUD operation on products and categories is authorized in the backend, and existing domain rules, data-integrity constraints and referential integrity keep holding. | constraint | "All CRUD operations must be authorized on the backend. Existing domain rules ... preserved." | must |
| R22 | The approval status of a product or category is one of `PENDING_APPROVAL`, `APPROVED`, `REJECTED`. | functional | "Supported approval states" | must |
| R23 | `PENDING_APPROVAL` → `APPROVED` is possible for the Super Admin. | functional | transition list | must |
| R24 | `PENDING_APPROVAL` → `REJECTED` is possible for the Super Admin. | functional | transition list | must |
| R25 | `REJECTED` → `PENDING_APPROVAL` is possible only through an Admin correction-and-resubmission. | functional | transition list | must |
| R26 | `APPROVED` → `PENDING_APPROVAL` happens when a modification of an approved record requires reapproval under the established business rules. | functional | transition list | must |
| R27 | No CRUD operation changes the approval status to `APPROVED` as a side effect. | constraint | "Performing CRUD operations must not automatically approve." | must |
| R28 | Approval status and publication status are stored and evaluated independently. | constraint | "Keep approval status separate from publication status." | must |
| R29 | An approved product can remain unpublished until it is explicitly published. | functional | "An approved product may remain a draft until explicitly published." | must |
| R30 | A product whose approval status is `PENDING_APPROVAL` or `REJECTED` is never returned by a public endpoint. | constraint | "A pending or rejected product must never be publicly accessible." | must |
| R31 | Approving a category does not change the publication state of its products. | constraint | "An approved category must not automatically publish its associated products." | must |
| R32 | A product is publicly accessible only when its own approval and publication states allow it and its category is approved and published. | constraint | "A product must not be publicly accessible if its category is unapproved or unpublished." | must |
| R33 | The visibility rules of R30–R32 hold on every public API endpoint, including list, detail, search and taxonomy endpoints. | constraint | "Enforce these restrictions across all relevant public API endpoints." | must |
| R34 | Only a Super Admin can move a product or category to `APPROVED` or `REJECTED`. | constraint | "Only a Super Admin can approve or reject products and categories." | must |
| R35 | Only a Super Admin can perform privileged CRUD on submissions created by other Admins. | constraint | "Only a Super Admin can perform privileged CRUD operations on submissions created by other Admins." | must |
| R36 | A regular Admin cannot write approval status, reviewer identity or approval timestamps. | constraint | "Regular Admins cannot manipulate approval statuses, reviewer identities, or approval timestamps." | must |
| R37 | Authorization is validated server-side for every operation regardless of which payload or endpoint is used. | constraint | "The backend must validate every operation regardless of the request payload or endpoint used." | must |
| R38 | Authorization applies identically to single-resource operations, bulk operations and alternative API operations that reach the same records. | constraint | "Apply authorization consistently to individual resources, bulk operations, and alternative API operations." | must |
| R39 | Public endpoints never expose administrative approval data or unpublished administrative records. | constraint | "Prevent unauthorized users from accessing administrative data through public endpoints." | must |
| R40 | Submission creation and resubmission are recorded in an audit trail. | functional | "Submission creation and resubmission." | must |
| R41 | Super Admin modifications of a submission are audited. | functional | "Super Admin modifications." | must |
| R42 | Approval and rejection decisions are audited. | functional | "Approval and rejection decisions." | must |
| R43 | Deletions of products and categories are audited. | functional | "Deletions." | must |
| R44 | Every audit record stores the acting user identity and the action timestamp. | constraint | "The acting user's identity and action timestamp." | must |
| R45 | Rejection reasons are part of the audit record when applicable. | functional | "Rejection reasons when applicable." | must |
| R46 | The audit trail reuses the existing audit infrastructure when one exists. | constraint | "Use the existing audit infrastructure if available." | must |
| R47 | Approval fields are introduced or extended only where the existing schema does not already carry the needed state. | constraint | "Introduce or extend approval fields only where necessary." | must |
| R48 | Server-controlled statuses cannot be overridden by a regular Admin. | constraint | "Ensure server-controlled statuses cannot be overridden by regular Admins." | must |
| R49 | Product-category relationships are handled without leaving dangling or inconsistent references. | constraint | "Handle product-category relationships safely." | must |
| R50 | Multi-statement writes that must stay consistent run inside a transaction. | constraint | "Use transactions where required for consistency." | must |
| R51 | A migration adds the approval schema safely: existing product and category rows keep their current publication behaviour and their current records are preserved. | constraint | "Create safe migrations that preserve existing records and their current publication behavior." | must |
| R52 | Backend tests exist for Admin product and category submissions. | quality | "Admin product and category submissions." | must |
| R53 | Backend tests exist for Super Admin CRUD on submissions created by other Admins. | quality | "Super Admin CRUD operations on submissions created by other Admins." | must |
| R54 | Backend tests exist for unauthorized CRUD and approval attempts. | quality | "Unauthorized CRUD and approval attempts." | must |
| R55 | Backend tests exist for explicit approval and rejection. | quality | "Explicit approval and rejection." | must |
| R56 | Backend tests exist for editing a submission before approval. | quality | "Editing submissions before approval." | must |
| R57 | Backend tests exist for the reapproval rules of modified approved records. | quality | "Reapproval rules for modified approved records." | must |
| R58 | Backend tests exist for rejection reasons and resubmission. | quality | "Rejection reasons and resubmission." | must |
| R59 | Backend tests exist for deletion constraints and product-category dependencies. | quality | "Deletion constraints and product-category dependencies." | must |
| R60 | Backend tests exist for public API visibility restrictions. | quality | "Public API visibility restrictions." | must |
| R61 | Backend tests exist for bypass attempts through alternative endpoints. | quality | "Attempts to bypass approval through alternative endpoints." | must |
| R62 | Backend tests exist for existing functionality and regression scenarios. | quality | "Existing functionality and regression scenarios." | must |
| R63 | The relevant tests are executed and their actual results reported. | quality | "Execute the relevant tests and report the actual results." | must |
| R64 | No frontend code is modified. | constraint | "Backend only; do not modify frontend code." | must |
| R65 | No unrelated refactoring and no new dependency are introduced. | constraint | "Do not introduce unrelated refactoring or unnecessary dependencies." | must |
| R66 | Existing CRUD or approval functionality is extended, not duplicated. | constraint | "Do not duplicate existing CRUD or approval functionality." | must |
| R67 | The report lists changed files, migrations, test results and remaining limitations, with evidence. | quality | "Report changed files, migrations, test results, and remaining limitations." / "Do not claim successful implementation or testing without evidence." | must |

## Assumptions

| # | Assumption | Risk if wrong |
|---|------------|---------------|
| 1 | "Submission" is the product or category record itself plus its approval metadata; there is no separate submission entity to introduce. | med |
| 2 | `ROLE_ADMIN` is the regular Admin role and `ROLE_SUPER_ADMIN` the Super Admin role, both already present in the security configuration. | low |
| 3 | Publication status for a product is a new explicit flag; publication status for a category is the existing hidden/visible flag, which is not redefined by this feature. | med |
| 4 | "The established business rules" for reapproval mean: a Super Admin or Admin modification of an `APPROVED` record returns it to `PENDING_APPROVAL`. | med |
| 5 | Existing audit infrastructure means the audit table and append-only convention already used by the identity context. | low |
| 6 | Backend tests are the automated test suites already in the repository; no new test framework or dependency is needed. | low |
| 7 | Existing seeded and migrated products/categories are `APPROVED` and published after the migration, preserving current storefront behaviour. | med |

## Open questions

- [minor] Which exact fields count as "approval metadata" to be ignored on input? Default: approval status, reviewer identity, review timestamp, submission timestamp, submitting user, rejection reason — all ignored unless the caller is a Super Admin performing the explicit approve/reject action.
- [minor] Is the rejection reason mandatory on rejection? Default: optional, but stored and returned when provided.
- [minor] Does deleting a submission require it to be unpublished first? Default: existing deletion constraints apply unchanged; a product with order history still cannot be deleted.
- [minor] Are bulk endpoints in scope, or only the requirement that any existing bulk/alternative path be authorized? Default: enforce authorization on whatever path exists; do not build new bulk endpoints.

## Out of scope

- Frontend, dashboard UI, or API client changes.
- Email or notification delivery to the submitting Admin.
- A multi-step review queue with more than the three stated approval states (no `DRAFT`, `ARCHIVED`, `CHANGES_REQUESTED`).
- Reviewer assignment, four-eyes/multi-reviewer approval, or approval delegation.
- Re-approval of inventory, pricing or payment records.
- New authentication or role model; the existing roles are reused.
- Retroactive audit reconstruction of historical admin actions.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Persist approval and publication state | R21, R22, R28, R47, R51 | Prompt §3, §4, §7; existing schema | Migration plus schema change adding approval status, submitter, reviewer, timestamps, rejection reason and product publication flag, with defaults that keep existing rows public exactly as today | `migrations/**` | none | Migration applies on a database holding pre-existing products and categories; those rows read back as approved and in their current publication state; rollback restores the old schema |
| WP2 | Model the approval lifecycle | R10, R22, R23, R24, R25, R26, R27, R47, R48 | Prompt §3; WP1 schema | Domain model for approval status with the allowed transitions and the exceptions for illegal transitions, plus the reapproval rule | `src/Catalog/Domain/**` | WP1 | Unit tests show every allowed transition accepted and every illegal one refused with a typed domain exception; a data update cannot set status without going through the model |
| WP3 | Admin submission use cases | R1, R2, R3, R4, R5, R6, R7, R8, R9, R10, R11, R12 | Prompt §1; WP2 | Commands, handlers and read models for create, submit, edit-own, resubmit, and status/rejection-reason reads scoped to the caller | `src/Catalog/Application/Command/**`, `src/Catalog/Application/CommandHandler/**`, `src/Catalog/Application/Query/**` | WP2 | Tests show a new submission landing in `PENDING_APPROVAL`, an Admin reading only their own status and rejection reason, an Admin editing only their own pending/rejected record, and a resubmission returning a rejected record to pending |
| WP4 | Super Admin review and CRUD use cases | R13–R21, R23, R24, R27, R34, R35, R50 | Prompt §2, §3, §5; WP3 | Commands and handlers for Super Admin create/update/delete of products and categories in any state, and explicit approve/reject | `src/Catalog/Application/Command/**`, `src/Catalog/Application/CommandHandler/**` | WP3 | Tests show Super Admin CRUD on another Admin's pending record working, approval/rejection only through the explicit action, and no CRUD path setting `APPROVED` |
| WP5 | Enforce the rules at the admin API edge | R5–R21, R33, R34, R35, R36, R37, R38, R39 | Prompt §2, §5; WP3, WP4 | Admin endpoints for submission, status, approve, reject, CRUD, with role checks and approval metadata stripped from every input | `src/Catalog/Infrastructure/Http/Admin*.php` | WP4 | Functional tests show a regular Admin refused on approve/reject and on another Admin's record, payload approval fields ignored, and the same refusal on any alternative admin path that reaches the record |
| WP6 | Keep unapproved records off every public surface | R28, R29, R30, R31, R32, R33, R39 | Prompt §4; WP1 | Publication command for products, plus approval and publication predicates in every storefront read path (list, detail, search, taxonomy) | `src/Catalog/Infrastructure/ReadModel/**`, `src/Catalog/Infrastructure/Search/**`, `src/Shared/Infrastructure/ReadModel/**` | WP1 | Functional tests show pending and rejected products absent from list, detail and search; an approved but unpublished product absent; a product absent while its category is unapproved or hidden; and an approved, published product with an approved, visible category present |
| WP7 | Audit the workflow | R40, R41, R42, R43, R44, R45, R46 | Prompt §6; WP3, WP4 | One audit write path used by every workflow action, recording action, target, actor, timestamp and rejection reason on the existing audit infrastructure | `src/Catalog/Application/**` (audit port usage), audit adapter location decided at the pattern gate | WP4 | Tests show an audit row per creation, resubmission, Super Admin modification, approval, rejection and deletion, each carrying actor and timestamp, the rejection reason included when provided |
| WP8 | Prove the behaviour | R52–R63, R67 | Prompt §8, §9; WP5, WP6, WP7 | Unit, integration, projection and functional tests covering every listed scenario, plus the executed results | `tests/**` | WP5, WP6, WP7 | Every requirement of R52–R62 maps to at least one test that passes or to a reported failure; the command output is recorded |
| WP9 | Verify the constraints and report | R64, R65, R66, R67 | Prompt §9, Strict constraints | Constraint check and the final report | none (verification only) | WP8 | No frontend file appears in the changed-file list; no new Composer dependency; no duplicated create/approve path; report lists files, migration, test results and limitations with output as evidence |

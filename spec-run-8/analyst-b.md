# Spec: Super Admin Management and Approval Workflow for Products and Categories

Version: analyst-b (intent stance)

## Goal

Regular Admins get a self-service path to propose catalog content that is invisible to the storefront until a Super Admin reviews it; the Super Admin gets a real moderation surface (queue, read, edit, delete, approve, reject) over those proposals; and the platform can prove who changed what and when, without breaking any existing catalog rule or public page.

## Corrected prompt

Add a backend moderation workflow to the catalog. A regular Admin creates products and categories as submissions. Submissions enter `PENDING_APPROVAL` by default, because the backend — not the client — decides that. The Admin can follow the status of their own submissions, see why one was rejected, fix pending or rejected content, and send rejected content back for review. The Super Admin owns the catalog: they can create, read, correct and delete any product or category, including those submitted by other Admins and including records that are pending, approved or rejected, and they alone decide when a submission becomes `APPROVED` or `REJECTED`. Editing is never the same act as approving. Approval does not mean published: visibility is a separate decision, an approved record can stay in draft, and nothing pending or rejected may be reachable from the public API, directly or through search, lists, or its parent category. Approval metadata is server-owned, so a payload that carries it must be ignored. Every meaningful transition is written to the audit trail with the actor, the moment and, on rejection, the reason. The schema change must leave existing products and categories exactly as visible as they are today. Tests must demonstrate each of these behaviours, including the attempts to bypass them, and must be run with their real results reported.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A regular Admin can create a product as a submission through an authenticated backend endpoint. | functional | §1 "Create products and categories" | must |
| R2 | A regular Admin can create a category as a submission through an authenticated backend endpoint. | functional | §1 | must |
| R3 | Submitting a product for review records who submitted it and when. | functional | §1 "Submit newly created products and categories"; §6 actor identity | must |
| R4 | Submitting a category for review records who submitted it and when. | functional | same | must |
| R5 | The approval status of every new submission is `PENDING_APPROVAL`, decided by the backend, and no request field can change that at creation time. | constraint | §1 "New submissions must default to `PENDING_APPROVAL`, enforced by the backend" | must |
| R6 | A regular Admin can list and read their own submissions with their approval status. | functional | §1 "View the approval status of their submissions" | must |
| R7 | A regular Admin can read the rejection reason attached to their own rejected submission. | functional | §1 "View rejection reasons when provided" | must |
| R8 | A regular Admin can update a submission they own while it is `PENDING_APPROVAL`, and the update keeps its approval status unchanged. | functional | §1 "Edit their pending or rejected submissions according to existing business rules" | must |
| R9 | A regular Admin can update a submission they own while it is `REJECTED`, and the update keeps it `REJECTED` until it is resubmitted. | functional | §1 | must |
| R10 | Resubmitting a rejected submission returns it to `PENDING_APPROVAL` and clears or supersedes the previous rejection outcome. | functional | §1 "Resubmit rejected submissions"; §3 `REJECTED → PENDING_APPROVAL` | must |
| R11 | A regular Admin cannot change, read or fabricate the approval status of a submission they do not own. | constraint | §1, §5 | must |
| R12 | Approval status is exactly one of `PENDING_APPROVAL`, `APPROVED`, `REJECTED` in storage and in every API representation. | functional | §3 state list | must |
| R13 | Only `PENDING_APPROVAL → APPROVED` and `PENDING_APPROVAL → REJECTED` are decided by the Super Admin at review time. | functional | §3 transitions | must |
| R14 | `REJECTED → PENDING_APPROVAL` happens only through resubmission after correction. | functional | §3 transitions | must |
| R15 | `APPROVED → PENDING_APPROVAL` happens automatically when an approved record is modified in a way that requires reapproval. | functional | §3 transitions | must |
| R16 | Any transition not in the list above is refused, leaving the stored record unchanged. | constraint | §3 "Supported transitions" | must |
| R17 | Editing a submission never sets `APPROVED`; approval requires the explicit approve action. | constraint | §3 "CRUD operations must not automatically approve" | must |
| R18 | The Super Admin can create a product directly, without an Admin submission. | functional | §2 Products "Create" | must |
| R19 | The Super Admin can read any product, whatever its approval and publication state. | functional | §2 Products "Read" | must |
| R20 | The Super Admin can correct an Admin-submitted product before approving or rejecting it. | functional | §2 Products "Update" | must |
| R21 | The Super Admin can delete a submitted product when existing constraints allow it. | functional | §2 Products "Delete" | must |
| R22 | The Super Admin can create a category directly. | functional | §2 Categories "Create" | must |
| R23 | The Super Admin can read any category, whatever its approval state. | functional | §2 Categories "Read" | must |
| R24 | The Super Admin can correct an Admin-submitted category before or during review. | functional | §2 Categories "Update" | must |
| R25 | The Super Admin can delete a submitted category when existing constraints and product dependencies allow it, and the refusal is explicit when they do not. | functional | §2 Categories "Delete" | must |
| R26 | The Super Admin can approve a pending product, recording reviewer identity and the moment of the decision. | functional | §2, §3, §6 | must |
| R27 | The Super Admin can reject a pending product, recording reviewer identity, the moment and a reason. | functional | §2, §3, §6 | must |
| R28 | The Super Admin can approve a pending category, recording reviewer identity and the moment of the decision. | functional | §2, §3, §6 | must |
| R29 | The Super Admin can reject a pending category, recording reviewer identity, the moment and a reason. | functional | §2, §3, §6 | must |
| R30 | Approve and reject are separate endpoints from CRUD, so no update call can carry a status change. | constraint | §3 "Approval must remain an explicit action" | must |
| R31 | Approval status and publication status are two independent stored values, and neither is derived from the other. | constraint | §4 "Keep approval status separate from publication status" | must |
| R32 | An approved product can be published explicitly and unpublished explicitly, and stays invisible on the storefront while unpublished. | functional | §4 first example | must |
| R33 | A product that is `PENDING_APPROVAL` or `REJECTED` is never returned by a public endpoint, even if a publication flag is set. | constraint | §4 second example | must |
| R34 | Approving a category leaves its products' publication state untouched. | constraint | §4 third example | must |
| R35 | A product is public only if the product is approved and published and its category exists, is approved and is publicly visible. | constraint | §4 fourth example | must |
| R36 | The public visibility rules hold on the storefront list, detail, search index and taxonomy endpoints alike. | constraint | §4 "across all relevant public API endpoints" | must |
| R37 | An approved record that becomes unapproved leaves the public surfaces immediately, including the search index. | constraint | §4 implied by the visibility rules | must |
| R38 | Only a Super Admin can approve or reject a product or category. | constraint | §5 | must |
| R39 | A regular Admin attempting approve or reject is refused, whatever the payload or route. | constraint | §5 "backend must validate every operation" | must |
| R40 | Only a Super Admin can modify or delete a submission owned by another Admin. | constraint | §5 | must |
| R41 | Approval metadata supplied by the client is discarded, never persisted, on every write path. | constraint | §5, "Do not trust client-provided approval metadata" | must |
| R42 | The same authorization and metadata rules apply on every alternative path that can reach the same record (list, detail, taxonomy, brand, bulk or proxy operations). | constraint | §5 "individual resources, bulk operations, and alternative API operations" | must |
| R43 | Public endpoints expose no approval metadata, no reviewer identity, no rejection reason and no unreviewed record. | constraint | §5 "Prevent unauthorized users from accessing administrative data through public endpoints" | must |
| R44 | Submission, resubmission, Super Admin modification, approval, rejection and deletion each produce an audit record. | functional | §6 | must |
| R45 | Each audit record carries the acting user, the timestamp and the affected record. | constraint | §6 | must |
| R46 | A rejection audit record carries the rejection reason. | functional | §6 | must |
| R47 | The audit trail uses the audit infrastructure the platform already has rather than a parallel mechanism. | constraint | §6 "Use the existing audit infrastructure if available" | must |
| R48 | The audit write happens in the same transaction as the state change it describes. | quality | §6, §7 "Use transactions where required for consistency" | must |
| R49 | The approval schema is added by one safe migration that preserves every existing product and category row and keeps its current public visibility. | constraint | §7 "safe migrations that preserve existing records and their current publication behavior" | must |
| R50 | New approval fields are added only where the existing schema lacks them, and existing fields keep their meaning. | constraint | §7 "Introduce or extend approval fields only where necessary" | must |
| R51 | Category-product relationships stay consistent: deleting or unapproving a category never leaves a product publicly reachable through a missing category. | constraint | §7 "Handle product-category relationships safely" | must |
| R52 | Existing domain rules keep applying to submissions and Super Admin edits: taxonomy validity, price and variant rules, hierarchy rules, deletion guards. | constraint | §2 "Existing domain rules ... preserved"; §7 | must |
| R53 | Existing functionality that has no relationship to approval keeps behaving as before. | quality | §8 "Existing functionality and regression scenarios" | must |
| R54 | Automated backend tests cover Admin product and category submissions. | quality | §8 | must |
| R55 | Automated backend tests cover Super Admin CRUD over submissions created by another Admin. | quality | §8 | must |
| R56 | Automated backend tests cover unauthorized CRUD and approval attempts. | quality | §8 | must |
| R57 | Automated backend tests cover explicit approval and rejection decisions. | quality | §8 | must |
| R58 | Automated backend tests cover editing a submission before approval. | quality | §8 | must |
| R59 | Automated backend tests cover the reapproval rule for modified approved records. | quality | §8 | must |
| R60 | Automated backend tests cover rejection reasons and resubmission. | quality | §8 | must |
| R61 | Automated backend tests cover deletion constraints and product-category dependencies. | quality | §8 | must |
| R62 | Automated backend tests cover public API visibility restrictions. | quality | §8 | must |
| R63 | Automated backend tests cover bypass attempts through alternative endpoints. | quality | §8 | must |
| R64 | The relevant test suites are executed and the real results reported, including any failure. | quality | §8, §9.6 | must |
| R65 | Available code-quality checks are run and reported. | quality | §9.6 "available code-quality checks" | should |
| R66 | No frontend file is touched. | constraint | Strict constraints | must |
| R67 | No unrelated refactor and no new dependency. | constraint | Strict constraints | must |
| R68 | The workflow is layered on the existing create and delete paths instead of duplicating them. | constraint | "Do not duplicate existing CRUD or approval functionality" | must |
| R69 | The final report names every changed file, the migration, the test results and the remaining limitations. | quality | §9.7 | must |

## Assumptions

| # | Assumption | Risk if wrong |
|---|------------|---------------|
| 1 | Ownership of a submission is recorded as the creating Admin's user identifier, because nothing in the current catalog data distinguishes who created a product or category. | med |
| 2 | A Super Admin is also a privileged reader of the moderation queue: they need a way to enumerate pending submissions, otherwise "review" is not operable. | med |
| 3 | `ROLE_ADMIN` means regular Admin and `ROLE_SUPER_ADMIN` means Super Admin, per the existing role hierarchy. | low |
| 4 | A product's publication flag is new; a category's existing hidden/visible flag already plays that role and is left as the publication control. | med |
| 5 | "Reapproval required" is triggered by content modifications on an `APPROVED` record, and the record then returns to `PENDING_APPROVAL` rather than staying approved with pending changes. | med |
| 6 | The rejection reason is free text, optional on rejection, and cleared or replaced when the submission is resubmitted. | low |
| 7 | Existing products and categories are treated as already approved and already published, because that is how they behave today. | med |
| 8 | The storefront search index is derived from the same visibility rules, so it must be updated when a record stops being public. | low |
| 9 | Tests are written against the existing suites and helpers; no new test tooling is added. | low |

## Open questions

- [minor] Should a Super Admin creating a product directly be allowed to have it approved immediately in the same request? Default: no — creation leaves it `PENDING_APPROVAL`, and approval is a separate explicit call, because CRUD must not approve.
- [minor] Is the rejection reason mandatory? Default: optional but recommended; when provided it is stored, returned to the owner and audited.
- [minor] What happens to an approved product whose category is later unapproved or hidden? Default: the product becomes publicly unreachable while the parent is not public; its own flag is unchanged.
- [minor] Is a pending submission editable by its owner an unlimited number of times? Default: yes, while it is pending or rejected.
- [minor] Should existing seeded fixtures/products need an explicit approval step in tests? Default: rows inserted by migration or fixture are pre-approved; rows created through the admin API must be approved and published explicitly by the test.

## Out of scope

- Any frontend, dashboard screen or client SDK work.
- Notifying the submitting Admin by email, SMS or push.
- More than three approval states; no draft, archived, changes-requested or scheduled-publication state.
- Reviewer assignment, multi-reviewer consensus, approval expiry, or approval levels.
- Approval of banners, brands, promotions, inventory or pricing.
- Migrating historical admin actions into the audit trail.
- Reworking how the catalog stores product data beyond the fields the workflow needs.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add approval and publication state to the catalog schema | R31, R49, R50 | Prompt §3, §4, §7 | Reversible migration with approval status, owner, reviewer, decision timestamps, rejection reason, and a product publication flag; existing rows land approved and as visible as before | `migrations/**` | none | Migration applies over existing data, existing rows still appear on the storefront, `down()` removes the columns and the catalog keeps working |
| WP2 | Encode the lifecycle once | R12, R13, R14, R15, R16, R17 | Prompt §3 | Approval value object with a closed transition set and typed refusal for illegal moves, plus the reapproval trigger rule | `src/Catalog/Domain/**` | WP1 | Unit tests prove every legal transition and refusal of every illegal one; no status change is possible without the object |
| WP3 | Give the Admin a submission workflow | R1–R11, R52 | Prompt §1 | Commands and handlers for create-as-submission, submit, own-edit, resubmit and own-status reads, ownership enforced from the authenticated user | `src/Catalog/Application/Command/**`, `src/Catalog/Application/CommandHandler/**` | WP2 | Tests show pending by default, owner-scoped reads, owner-scoped edits, rejected→pending on resubmission, and refusal for a non-owner |
| WP4 | Give the Super Admin moderation and CRUD | R18–R29, R40, R52 | Prompt §2, §5 | Commands and handlers for direct create, review-queue read, edit, delete and explicit approve/reject for both aggregates | `src/Catalog/Application/Command/**`, `src/Catalog/Application/CommandHandler/**` | WP3 | Tests show Super Admin edit and delete on another Admin's record, approve and reject with reviewer recorded, and no approval produced by any CRUD command |
| WP5 | Expose the workflow and enforce authorization at the edge | R5, R26–R30, R38, R39, R41, R42, R43 | Prompt §2, §5 | Admin endpoints for submit, my-submissions, queue, approve, reject and CRUD; approval fields stripped from input; role and ownership checks on every route | `src/Catalog/Infrastructure/Http/Admin*.php` | WP4 | Functional tests show 403 for a regular Admin on approve/reject and on foreign records, injected approval fields ignored, and the same rules on every alternative admin route that reaches the record |
| WP6 | Make publication real and keep unapproved content invisible | R31–R37, R43, R51 | Prompt §4 | Publication command plus approval and publication predicates in every storefront read path and in the search projection | `src/Catalog/Infrastructure/ReadModel/**`, `src/Catalog/Infrastructure/Search/**`, `src/Catalog/Infrastructure/Projection/**`, `src/Shared/Infrastructure/ReadModel/**` | WP1 | Tests show pending, rejected and unpublished products absent from list, detail, search and taxonomy; parent-category gating enforced; approved+published content visible; search index updated on unpublish |
| WP7 | Audit every meaningful action in the same transaction | R44, R45, R46, R47, R48 | Prompt §6 | An audit write path invoked by each workflow command handler, recording action, target, actor, timestamp and reason | audit writer used from `src/Catalog/Application/CommandHandler/**`; adapter placement decided at the pattern gate | WP4 | Tests show one audit row per action with actor and timestamp, the rejection reason present on rejection, and a rolled-back action leaving no audit row |
| WP8 | Prove it | R53–R65 | Prompt §8, §9 | Tests at unit, integration, projection and functional level, plus executed results and quality-check output | `tests/**` | WP5, WP6, WP7 | Each behaviour of R54–R63 has a test that passes, or a reported failure; existing suites run and their output is recorded |
| WP9 | Report honestly | R66, R67, R68, R69 | Prompt §9, Strict constraints | Verification of the constraints plus the final report | none (verification only) | WP8 | Changed-file list contains no frontend path and no new dependency; the create/delete paths are extended not duplicated; results quoted from real command output |

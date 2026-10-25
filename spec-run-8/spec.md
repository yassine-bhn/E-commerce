# Spec: Super Admin Management and Approval Workflow for Products and Categories

Version: 1

## Goal

Regular Admins can create products and categories that stay invisible to the storefront as `PENDING_APPROVAL` submissions; the Super Admin can read, correct and delete those submissions and is the only role that can approve or reject them; approval stays separate from publication on every public endpoint; and every significant action is audited with the acting user and timestamp.

## Corrected prompt

Implement a backend-only approval workflow for catalog products and categories.

**Regular Admin.** A regular Admin can create products and categories. A newly created submission is `PENDING_APPROVAL`, and the backend enforces that, not the client. The Admin can submit a created record for review, see the approval status of their own submissions, see the rejection reason when one exists, edit their own pending or rejected submissions under the existing business rules, and resubmit a rejected submission after correcting it. A regular Admin can never approve their own submission and can never write approval metadata through a request payload.

**Super Admin.** The Super Admin has full backend CRUD over products and categories, including records submitted by other Admins and records in any approval state. For products that means creating directly, reading complete details across every approval and publication state, updating a submission before approval, and deleting when existing business constraints allow. For categories it means the same, with deletion also respecting product dependencies. All operations are authorized in the backend, and existing domain rules, data-integrity constraints and referential integrity must keep holding.

**Lifecycle.** The approval states are `PENDING_APPROVAL`, `APPROVED` and `REJECTED`. `PENDING_APPROVAL` becomes `APPROVED` or `REJECTED` only when the Super Admin decides it. `REJECTED` returns to `PENDING_APPROVAL` through resubmission after correction. `APPROVED` returns to `PENDING_APPROVAL` when a later modification requires reapproval under the established business rules. The Super Admin decides after reviewing, and may modify the submission first. No CRUD operation approves anything: approval is always an explicit action.

**Publication.** Approval status and publication status are separate. An approved product can stay unpublished. A pending or rejected product is never publicly accessible. An approved category does not publish its products. A product is not publicly accessible if its category is not approved or not published. These restrictions hold on every relevant public API endpoint.

**Authorization.** Only a Super Admin approves or rejects. Only a Super Admin performs privileged CRUD on another Admin's submission. Regular Admins cannot manipulate approval status, reviewer identity or approval timestamps. The backend validates every operation regardless of payload or endpoint, applies the same rules to single-resource, bulk and alternative operations, and prevents unauthorized users from reaching administrative data through public endpoints.

**Audit.** Record submission creation and resubmission, Super Admin modifications, approval and rejection decisions, and deletions, with the acting user's identity, the timestamp and the rejection reason where applicable, reusing the existing audit infrastructure.

**Data.** Inspect the existing entities, repositories, services, controllers, API operations and schema first, and reuse the existing architecture and conventions. Add or extend approval fields only where necessary. Server-controlled statuses must not be overridable by a regular Admin. Handle product-category relationships safely. Use transactions where consistency requires them. Add safe migrations that preserve existing records and their current publication behaviour where appropriate.

**Tests.** Add backend tests covering Admin submissions, Super Admin CRUD on other Admins' submissions, unauthorized CRUD and approval attempts, explicit approval and rejection, editing before approval, reapproval of modified approved records, rejection reasons and resubmission, deletion constraints and product-category dependencies, public API visibility, bypass attempts through alternative endpoints, and existing functionality and regressions. Execute the tests and report the real results.

**Constraints.** Backend only, no frontend changes. No unrelated refactoring and no unnecessary dependency. Do not duplicate existing CRUD or approval functionality. Do not auto-approve. Do not trust client-provided approval metadata. Do not claim success without evidence; report changed files, migrations, test results and remaining limitations.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A regular Admin can create a product; the new record is stored as `PENDING_APPROVAL` with the creating user recorded as submitter. | functional | §1 "Create products and categories" / "New submissions must default to `PENDING_APPROVAL`" | must |
| R2 | A regular Admin can create a category; the new record is stored as `PENDING_APPROVAL` with the creating user recorded as submitter. | functional | §1 | must |
| R3 | A regular Admin can submit a product they created for review. | functional | §1 "Submit newly created products and categories for Super Admin review" | must |
| R4 | A regular Admin can submit a category they created for review. | functional | §1 | must |
| R5 | A regular Admin can read the approval status of submissions they created, and cannot read the approval data of submissions created by another Admin. | functional | §1 "View the approval status of their submissions" | must |
| R6 | A regular Admin can read the rejection reason on a rejected submission they created. | functional | §1 "View rejection reasons when provided" | must |
| R7 | A regular Admin can edit a submission they created while it is `PENDING_APPROVAL`, and the edit leaves the approval status unchanged. | functional | §1 "Edit their pending or rejected submissions according to existing business rules" | must |
| R8 | A regular Admin can edit a submission they created while it is `REJECTED`, and the edit leaves it `REJECTED` until it is resubmitted. | functional | §1 | must |
| R9 | A regular Admin can resubmit a `REJECTED` submission after correction, moving it to `PENDING_APPROVAL` and clearing the previous rejection outcome. | functional | §1 "Resubmit rejected submissions after making corrections"; §3 `REJECTED` → `PENDING_APPROVAL` | must |
| R10 | Every new product or category created through the backend is persisted as `PENDING_APPROVAL`, whatever the request payload says and whoever the caller is. | constraint | §1 "enforced by the backend"; §3 "Approval must remain an explicit action" | must |
| R11 | Approval metadata present in a request body (status, submitter, reviewer, decision or submission timestamps, rejection reason) is discarded and never persisted on any write path. | constraint | §1 "manipulate approval metadata through API payloads"; Strict constraints "Do not trust client-provided approval metadata" | must |
| R12 | No create or update operation, on any actor's behalf, results in `APPROVED`. | constraint | §3 "Performing CRUD operations must not automatically approve" | must |
| R13 | The Super Admin can create a product directly. | functional | §2 Products "Create" | must |
| R14 | The Super Admin can create a category directly. | functional | §2 Categories "Create" | must |
| R15 | The Super Admin can list and read any product, including pending, approved, rejected, published and unpublished records, with its full detail and approval metadata. | functional | §2 Products "Read" | must |
| R16 | The Super Admin can list and read any category across all approval states, with its full detail and approval metadata. | functional | §2 Categories "Read" | must |
| R17 | The Super Admin can update a product submitted by an Admin before approval, including while it is pending or rejected. | functional | §2 Products "Update" | must |
| R18 | The Super Admin can update a category submitted by an Admin before or during the approval process. | functional | §2 Categories "Update" | must |
| R19 | The Super Admin can delete a submitted product when the existing business constraints allow it, and a refused delete returns an explicit refusal and changes nothing. | functional | §2 Products "Delete" | must |
| R20 | The Super Admin can delete a submitted category when the existing business constraints and product dependencies allow it, and a refused delete returns an explicit refusal and changes nothing. | functional | §2 Categories "Delete" | must |
| R21 | Every product and category has exactly one approval status, one of `PENDING_APPROVAL`, `APPROVED`, `REJECTED`, in storage and in every admin representation. | functional | §3 state list | must |
| R22 | The Super Admin can move `PENDING_APPROVAL` to `APPROVED`; the decision stores the reviewer identity and the decision moment. | functional | §3 transition / §6 actor and timestamp | must |
| R23 | The Super Admin can move `PENDING_APPROVAL` to `REJECTED`; the decision stores the reviewer identity, the decision moment and the rejection reason when one is provided. | functional | §3 transition / §6 rejection reasons | must |
| R24 | `REJECTED` moves to `PENDING_APPROVAL` only through resubmission by the owning Admin (R9). | functional | §3 transition | must |
| R25 | A Super Admin modification of an `APPROVED` record returns it to `PENDING_APPROVAL` under the reapproval rule. | functional | §3 `APPROVED` → `PENDING_APPROVAL` | must |
| R26 | Any transition outside R22–R25 is refused with a domain-level refusal and writes nothing. | constraint | §3 "Supported transitions" | must |
| R27 | Approval status and publication status are separate persisted values; neither is derived from the other, and approving never publishes. | constraint | §4 "Keep approval status separate from publication status" | must |
| R28 | An approved product can be published and unpublished explicitly, and is publicly inaccessible while unpublished. | functional | §4 "An approved product may remain a draft until explicitly published" | must |
| R29 | A product in `PENDING_APPROVAL` or `REJECTED` is never returned by a public endpoint, even when its publication flag is set. | constraint | §4 "A pending or rejected product must never be publicly accessible" | must |
| R30 | Approving a category leaves the publication state of its products unchanged. | constraint | §4 "An approved category must not automatically publish its associated products" | must |
| R31 | A product is publicly accessible only when the product is approved and published and its category exists, is approved and is publicly visible. | constraint | §4 "A product must not be publicly accessible if its category is unapproved or unpublished" | must |
| R32 | The visibility rules of R29–R31 hold on every public endpoint that can return a product or a product count: browse, detail, search, taxonomy, reference data, cart and quote paths. | constraint | §4 "Enforce these restrictions across all relevant public API endpoints" | must |
| R33 | Public responses expose no approval status, submitter, reviewer, decision timestamp or rejection reason. | constraint | §5 "Prevent unauthorized users from accessing administrative data through public endpoints" | must |
| R34 | Only a Super Admin can approve or reject a product or category. | constraint | §5 | must |
| R35 | A regular Admin's write scope is limited to their own pending or rejected submissions plus resubmission; modifying or deleting another Admin's submission is Super Admin only. | constraint | §5 "Only a Super Admin can perform privileged CRUD operations on submissions created by other Admins" | must |
| R36 | A regular Admin cannot write approval status, reviewer identity or approval timestamps, in any payload on any route. | constraint | §5 | must |
| R37 | Authorization is enforced server-side for every operation, independently of the payload and of which endpoint was used. | constraint | §5 "The backend must validate every operation regardless of the request payload or endpoint used" | must |
| R38 | Every existing or new path that can reach a product or category record applies the same authorization and visibility rules, including alternative admin routes, list and detail routes, and taxonomy or brand mutations. | constraint | §5 "individual resources, bulk operations, and alternative API operations" | must |
| R39 | Submission creation, first submission and resubmission are written to the audit trail. | functional | §6 "Submission creation and resubmission" | must |
| R40 | A Super Admin modification of a submission is written to the audit trail. | functional | §6 "Super Admin modifications" | must |
| R41 | Approval and rejection decisions are written to the audit trail. | functional | §6 | must |
| R42 | Product and category deletions are written to the audit trail. | functional | §6 "Deletions" | must |
| R43 | Every audit record carries the acting user's identity, the action, the affected record and the action timestamp; a rejection record carries the rejection reason when provided. | constraint | §6 "The acting user's identity and action timestamp" / "Rejection reasons when applicable" | must |
| R44 | Auditing reuses the audit infrastructure already present in the codebase instead of a parallel mechanism. | constraint | §6 "Use the existing audit infrastructure if available" | must |
| R45 | The audit write and the state change it describes commit in the same transaction, so a refused action leaves no audit record. | constraint | §6 / §7 "Use transactions where required for consistency" | must |
| R46 | Only the fields the existing schema lacks are added: approval status, submitter, reviewer, decision timestamps, rejection reason, and a product publication flag. Existing columns keep their meaning. | constraint | §7 "Introduce or extend approval fields only where necessary" | must |
| R47 | One safe migration introduces the new fields, preserving every existing product and category row, and leaves existing rows in their current public visibility — treated as approved, with products published and category hidden flags untouched. | constraint | §7 "safe migrations that preserve existing records and their current publication behavior" | must |
| R48 | Existing domain rules keep applying to every submission and Super Admin edit: taxonomy and subcategory validity, price, old-price and variant rules, category hierarchy rules, and product deletion guards. | constraint | §2 "Existing domain rules, data integrity constraints, and referential integrity must be preserved" | must |
| R49 | Multi-statement writes introduced by this workflow run inside the handler's transaction, and a refused write leaves the row exactly as it was. | constraint | §7 / §9.4 "Use transactions where required for consistency" | must |
| R50 | Tests cover Admin product and category submissions. | quality | §8 | must |
| R51 | Tests cover Super Admin CRUD over submissions created by another Admin. | quality | §8 | must |
| R52 | Tests cover unauthorized CRUD and approval attempts. | quality | §8 | must |
| R53 | Tests cover explicit approval and rejection. | quality | §8 | must |
| R54 | Tests cover editing a submission before approval. | quality | §8 | must |
| R55 | Tests cover the reapproval rule for a modified approved record. | quality | §8 | must |
| R56 | Tests cover rejection reasons and resubmission. | quality | §8 | must |
| R57 | Tests cover deletion constraints and product-category dependencies. | quality | §8 | must |
| R58 | Tests cover public API visibility restrictions. | quality | §8 | must |
| R59 | Tests cover approval bypass attempts through alternative endpoints. | quality | §8 | must |
| R60 | Tests cover existing functionality and regression scenarios. | quality | §8 | must |
| R61 | The relevant test suites are executed and their actual results reported, including any failure. | quality | §8 / §9.6 | must |
| R62 | The available code-quality checks are executed and reported. | quality | §9.6 "available code-quality checks" | should |
| R63 | No frontend code is modified. | constraint | Strict constraints | must |
| R64 | No unrelated refactoring and no new dependency. | constraint | Strict constraints | must |
| R65 | The existing create, update and delete paths are extended rather than duplicated, and no second approval mechanism is introduced. | constraint | Strict constraints "Do not duplicate existing CRUD or approval functionality" | must |
| R66 | The report lists changed files, the migration, executed test results and remaining limitations, with command output as evidence. | quality | §9.7 / Strict constraints | must |

## Assumptions

| # | Assumption | Risk if wrong |
|---|------------|---------------|
| 1 | `ROLE_ADMIN` is the regular Admin and `ROLE_SUPER_ADMIN` the Super Admin; the existing role hierarchy is unchanged. | low |
| 2 | The existing catalog has no approval concept, so status, submitter, reviewer, decision timestamps and rejection reason are all new columns; no existing column is repurposed. | low |
| 3 | The existing category visibility flag is the category publication control: "unpublished category" means "hidden category". No second category publication flag is added. | med |
| 4 | Products have no publication flag today; their presence in the catalog is their publication, so a new flag defaulting to published for legacy rows reproduces today's behaviour. | med |
| 5 | Submissions are the product and category records themselves plus approval metadata; no separate submission entity or staging table is introduced. | med |
| 6 | Ownership is stored as the creating user's identifier on the record. | med |
| 7 | "Requires reapproval" means any Super Admin content modification of an `APPROVED` record; the record then returns to `PENDING_APPROVAL` instead of keeping a parallel pending copy. | med |
| 8 | The rejection reason is optional free text; it is cleared when the submission is resubmitted. | low |
| 9 | Regular Admins cannot delete submissions; §1 lists create, submit, view, edit and resubmit, and §2 grants deletion to the Super Admin. | med |
| 10 | The audit infrastructure to reuse is the existing append-only admin audit table already written by other admin flows. | low |
| 11 | Rows inserted directly by migrations, fixtures or seed SQL are legacy rows: approved and published. | low |
| 12 | No bulk endpoints exist in the current admin catalog surface, so R38 means: extend whatever alternative path can already reach these records, and build no new bulk API. | low |

## Open questions

- [minor] Is a rejection reason mandatory? Default if unanswered: optional; stored, returned to the owner and audited when present.
- [minor] Does a rejected submission stay rejected after an edit until an explicit resubmission? Default: yes.
- [minor] May a regular Admin change the slug of their own submission? Default: existing slug rules apply unchanged; changing it on an owned pending or rejected submission is a normal content update.
- [minor] Does a Super Admin's edit of an approved record remove it from the storefront immediately? Default: yes — it becomes `PENDING_APPROVAL` and therefore publicly invisible until re-approved.
- [minor] Are deprecated legacy admin routes that write these tables kept? Default: keep them working but subject them to the same authorization and approval-metadata rules; removing them is out of scope.

None of these questions block implementation: each has a safe default consistent with the prompt.

## Out of scope

- Any frontend, dashboard or storefront client change (`Zidane_front_web/`, templates, assets).
- Email, SMS or push notification of decisions to the submitting Admin.
- Approval states beyond the three named, workflow engines or configurable approval chains.
- Multi-reviewer approval, reviewer assignment, approval delegation or expiry.
- Approval of banners, brands, promotions, orders, payments or inventory adjustments.
- Backfilling audit history for actions performed before this feature.
- Redesigning how the catalog stores product data beyond the fields this workflow needs.
- New bulk admin endpoints.

## Implementation anchors

Facts established by inspecting the current backend; workers must verify before changing anything.

- Catalog lives in `src/Catalog/{Domain,Application,Infrastructure}`; the admin HTTP surface is `AdminProductController`, `AdminCategoryController`, `AdminBrandController`, `AdminBannerController`.
- `AdminProductController` writes `storefront_product_view` directly through DBAL and is guarded by `#[IsGranted('ROLE_SUPER_ADMIN')]`; product deletion lives in `AdminCategoryController::deleteProduct()` and goes through `ProductDeletionGuard`.
- Category writes already go through `command.bus` (`CreateCategory`, `ReplaceCategoryTree`, `HideCategory`, `ShowCategory`, `DeleteCategory`, `AddCategorySubLevel`) with DBAL handlers that are the transaction boundary.
- `storefront_product_view` and `storefront_category` are the denormalized read-model tables; there is no full product write model (the `Product` aggregate and `catalog_products` table only hold id, name and price).
- Storefront reads: `StorefrontProductRepository` (Meilisearch-first with SQL fallback), `GetStorefrontProductBySlugHandler`, `ListStorefrontProductsHandler`, `SearchStorefrontProductsHandler`, `StorefrontProductSearchIndexer`, `IndexCatalogSearchDocument`, `CategoryFinder`, and `src/Shared/Infrastructure/{ReadModel,Adapter}` ports. Product reads do not currently consider category visibility — R31 is a new rule.
- Audit infrastructure: `identity_audit_log` (id, action, target_user_id, actor_admin_id, reason, created_at), written from Identity command handlers.
- Security: `ROLE_ADMIN`/`ROLE_SUPER_ADMIN` hierarchy, `/api/v1/admin` requires `ROLE_ADMIN`, `JsonAccessDeniedHandler` renders refusals.
- Harness rules apply: writes through `command.bus` with one handler per command and the handler as transaction boundary; query handlers DBAL-only and authorized; `Deptrac` forbids `Catalog.Application` from naming `Identity.*` and forbids `Shared.Infrastructure` from naming any context, so the audit port/adapter placement needs an ADR before code.
- Migrations live in `migrations/` (Doctrine); the next version follows `Version20261022000006`.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Approval and publication schema | R21, R27, R46, R47 | Prompt §3, §4, §7; anchors | One reversible Doctrine migration adding approval status (default `PENDING_APPROVAL`), submitter, reviewer, submitted/decided timestamps, rejection reason, and a product publication flag (default published), with legacy rows landed approved and published | `migrations/**` | none | Up and down run clean on a database holding pre-existing products and categories; after up, legacy rows read as `APPROVED` and keep their current public visibility; a second `migrate` run is a no-op |
| WP2 | Encode the lifecycle once | R10, R21–R27 | Prompt §3; WP1 | Approval status type with a closed transition set, the reapproval rule, and typed refusals for illegal transitions | `src/Catalog/Domain/**` | WP1 | Unit tests prove R22–R25 accepted and every other pair refused with a typed domain exception; no status change is possible without going through the type |
| WP3 | Admin submission workflow | R1–R12, R35, R48 | Prompt §1, §5; WP2 | Commands, handlers and owner-scoped reads for create-as-submission, submit, own-edit, resubmit, own status and rejection-reason reads | `src/Catalog/Application/Command/**`, `src/Catalog/Application/CommandHandler/**`, `src/Catalog/Application/Query/**` | WP2 | Tests show pending by default, owner-only status and edit access, rejected→pending on resubmission, and no Admin path to `APPROVED` |
| WP4 | Super Admin CRUD, decisions and publication | R13–R20, R22–R26, R28, R34, R35, R49 | Prompt §2, §3, §4, §5; WP3 | Commands and handlers for direct create, update in any state, delete, approve, reject, publish and unpublish, plus the Super Admin review listing filter | `src/Catalog/Application/Command/**`, `src/Catalog/Application/CommandHandler/**` | WP3 | Tests show Super Admin CRUD on another Admin's record, approve/reject recording reviewer and timestamp, refused deletes leaving data intact, publish/unpublish independent of approval, and no CRUD command producing `APPROVED` |
| WP5 | Enforce the rules at the admin edge | R11, R19, R20, R22, R23, R33, R34, R35, R36, R37, R38 | Prompt §2, §5; WP4 | Admin routes for submit, my-submissions, review queue, approve, reject, publish, unpublish and CRUD, with role and ownership checks and approval metadata stripped from every input | `src/Catalog/Infrastructure/Http/AdminProductController.php`, `AdminCategoryController.php`, new admin workflow controller | WP4 | Functional tests show regular-Admin refusal on approve, reject and foreign-record writes; injected approval fields ignored; the same rules on every alternative admin route that reaches these records |
| WP6 | Public reachability follows approval and publication | R28, R29, R30, R31, R32, R33 | Prompt §4; WP1 | Approval, publication and parent-category predicates plus restricted counts in every public read path, and search index synchronisation on visibility change | `src/Catalog/Infrastructure/ReadModel/**`, `src/Catalog/Infrastructure/Search/**`, `src/Catalog/Infrastructure/Projection/**`, `src/Shared/Infrastructure/ReadModel/**`, `src/Shared/Infrastructure/Adapter/**` | WP1 | Tests show pending, rejected, unpublished and parent-blocked products absent from browse, detail, search, taxonomy and reference reads; approved+published products present; counts and search index agree; no approval metadata in public payloads |
| WP7 | Audit every significant action | R39–R45 | Prompt §6; WP4 | One audit write path invoked by each workflow command handler, recording action, target, actor, timestamp and rejection reason, committed with the change | audit port under `src/Shared/Application/**` and its adapter, path fixed by the PATTERN-gate ADR (default `src/Catalog/Infrastructure/Audit/**`) | WP4 | Tests show exactly one audit row per creation, submission, resubmission, Super Admin modification, decision and deletion, each with actor and timestamp; a refused action writes no row |
| WP8 | Prove every requirement | R50–R62 | Prompt §8, §9; WP5, WP6, WP7 | Unit, integration, projection and functional tests covering R50–R60, plus executed output for R61 and R62 | `tests/**` | WP5, WP6, WP7 | Every R50–R60 item has a passing test or a reported failure; R61 and R62 are answered with quoted command output |
| WP9 | Verify constraints and report | R63–R66 | Prompt §9, Strict constraints | Constraint verification and the final report | none (verification only) | WP8 | Changed-file list contains no frontend path and no new dependency; create/update/delete paths extended not duplicated; report names files, migration, test results and limitations with evidence |

## Decisions (synthesizer only)

| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Status of a record the Super Admin creates directly | pending, approval only via explicit action | pending (open question default) | BLOCKING, default pending | `PENDING_APPROVAL` for every creation, including Super Admin | §3 states unconditionally that CRUD must not approve and that approval stays an explicit action; a Super-Admin exception would need words the prompt does not have |
| Product visibility when its category is unapproved or hidden | require R32, keep migration behaviour | require R35/R37 | BLOCKING conflict with preserving current visibility | Enforce R31 going forward; legacy rows approved and published, hidden flags untouched, so products under currently hidden categories become publicly unreachable | §4 is explicit and commands enforcement on all public endpoints, while §7 says preserve behaviour only "where appropriate"; the resulting change is intentional and must be reported plus tested |
| Category publication control | existing hidden flag | existing hidden flag | existing hidden flag, no second flag | The existing visibility flag is the category publication state; no second flag | All three analysts agree, and adding a duplicate flag would violate "introduce approval fields only where necessary" |
| Product publication control | new flag | new flag | new flag | New explicit publication flag defaulting to published for legacy rows | Products have no publication state today, and §4 requires one that is independent from approval |
| Submission ownership | implied | explicit recorded submitter | explicit recorded submitter | New submitter column holding the creating user's id | Nothing in the current catalog distinguishes who created a record, and §5 requires ownership-based authorization |
| Can a regular Admin delete own submission | silent | silent | default no | Super Admin only | §1 enumerates the Admin's capabilities and deletion is not among them; §2 grants deletion to the Super Admin |
| Where the audit write lives | existing infra, unspecified | same transaction as the change | reuse existing audit table | Reuse the existing audit table through a Shared port, adapter placement fixed by the PATTERN-gate ADR, write inside the handler transaction | §6 asks to reuse existing infrastructure; Deptrac forbids `Catalog.Application` from naming Identity and forbids `Shared.Infrastructure` from naming contexts, so placement is a real design decision, not a detail |
| Review queue for pending submissions | Super Admin reads all states | explicit assumption: SA must enumerate pending | implied by Super Admin read | Super Admin list/detail filters by approval status | Without enumeration the Super Admin cannot review, and §2 already grants reading all states |
| Bulk endpoints | enforce if any exist, do not build | same | none exist | No new bulk API; R38 applies to existing alternative paths | The current admin catalog surface has no bulk route, and building one would be scope creep |
| Rejection reason required? | optional | optional | optional | Optional, audited and visible to the owner when present | The prompt says "when provided" and "when applicable" |
| Editing a rejected submission | stays rejected until resubmitted | stays rejected (implied) | stays rejected | Editing alone keeps `REJECTED`; only resubmission returns it to pending | Keeps the audit story honest: the reviewer decided on a specific content version, and only an explicit resubmission asks for a new review |

## Changelog (synthesizer and orchestrator only)

- v1: created from analyst-a (literal), analyst-b (intent) and analyst-c (skeptic); both skeptic BLOCKING questions resolved as Decisions, leaving no blocking question.

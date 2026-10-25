# Spec: Super Admin Management and Approval Workflow for Products and Categories

Version: analyst-c (skeptic stance)

## Goal

A backend approval workflow exists that a reviewer can audit end to end: who submitted what, who decided, why it was rejected, and what is publicly visible at any moment — with every stated restriction provable by a test and no unstated behaviour change hiding in the migration.

## Corrected prompt

Add a backend-only approval workflow for catalog products and categories. Regular Admins create products and categories as submissions; new submissions are `PENDING_APPROVAL`, set by the backend. Regular Admins see the approval status and rejection reason of their own submissions, may edit them while pending or rejected, and may resubmit rejected ones. The Super Admin may create, read, update and delete any product or category, including submissions made by other Admins and records in any approval state, and is the only role that may move a submission to `APPROVED` or `REJECTED`. CRUD never approves anything. Approval and publication are separate: pending or rejected records are never public, an approved record may remain unpublished, approving a category does not publish its products, and a product is public only when it and its category are approved and published. Approval metadata is server-controlled and ignored when supplied by a client. Significant actions are audited with actor, timestamp and rejection reason, reusing existing audit infrastructure. A safe migration preserves existing data and existing public visibility. Backend tests cover every listed scenario, including bypass attempts and regressions, are executed, and their real results are reported.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A regular Admin can create a product. | functional | §1 | must |
| R2 | A regular Admin can create a category. | functional | §1 | must |
| R3 | A product created by a regular Admin is stored as `PENDING_APPROVAL`. | constraint | §1 "New submissions must default to `PENDING_APPROVAL`, enforced by the backend" | must |
| R4 | A category created by a regular Admin is stored as `PENDING_APPROVAL`. | constraint | same | must |
| R5 | A regular Admin can submit an already created product for review. | functional | §1 "Submit newly created products and categories for Super Admin review" | must |
| R6 | A regular Admin can submit an already created category for review. | functional | same | must |
| R7 | A regular Admin can read the approval status of submissions they created, and of no other submissions. | functional | §1 "View the approval status of their submissions" | must |
| R8 | A regular Admin can read the rejection reason of a rejected submission they created. | functional | §1 "View rejection reasons when provided" | must |
| R9 | A regular Admin can edit a submission they created while it is `PENDING_APPROVAL`. | functional | §1 | must |
| R10 | A regular Admin can edit a submission they created while it is `REJECTED`. | functional | §1 | must |
| R11 | A regular Admin can resubmit a rejected submission they created, moving it to `PENDING_APPROVAL`. | functional | §1 | must |
| R12 | Neither creation nor editing by a regular Admin can result in `APPROVED`. | constraint | §1, §3 | must |
| R13 | A regular Admin cannot approve or reject any submission, including their own. | constraint | §1 "must not be able to approve their own submissions" | must |
| R14 | Approval metadata in a request body is rejected or ignored, never persisted. | constraint | §1 "manipulate approval metadata through API payloads"; Strict constraints "Do not trust client-provided approval metadata" | must |
| R15 | The Super Admin can create a product directly. | functional | §2 Products "Create" | must |
| R16 | The Super Admin can create a category directly. | functional | §2 Categories "Create" | must |
| R17 | The Super Admin can read any product, in any approval and publication state. | functional | §2 Products "Read" | must |
| R18 | The Super Admin can read any category, in any approval state. | functional | §2 Categories "Read" | must |
| R19 | The Super Admin can update an Admin-submitted product before approval. | functional | §2 Products "Update" | must |
| R20 | The Super Admin can update an Admin-submitted category before or during approval. | functional | §2 Categories "Update" | must |
| R21 | The Super Admin can delete a submitted product subject to existing business constraints. | functional | §2 Products "Delete" | must |
| R22 | The Super Admin can delete a submitted category subject to existing business constraints and product dependencies. | functional | §2 Categories "Delete" | must |
| R23 | A refused delete leaves the record and its relationships untouched. | constraint | §2 "referential integrity must be preserved" | must |
| R24 | Approval status has exactly three values: `PENDING_APPROVAL`, `APPROVED`, `REJECTED`. | functional | §3 | must |
| R25 | The Super Admin can move `PENDING_APPROVAL` to `APPROVED`. | functional | §3 | must |
| R26 | The Super Admin can move `PENDING_APPROVAL` to `REJECTED`. | functional | §3 | must |
| R27 | Resubmission moves `REJECTED` to `PENDING_APPROVAL`. | functional | §3 | must |
| R28 | A modification that requires reapproval moves `APPROVED` to `PENDING_APPROVAL`. | functional | §3 | must |
| R29 | A transition outside the four transitions above is refused and writes nothing. | constraint | §3 "Supported transitions" | must |
| R30 | No create, read, update or delete operation produces `APPROVED` as a side effect. | constraint | §3 "Performing CRUD operations must not automatically approve" | must |
| R31 | Approval status and publication status are persisted and evaluated separately. | constraint | §4 | must |
| R32 | An approved product can be unpublished and stays unreachable publicly while unpublished. | functional | §4 | must |
| R33 | A product in `PENDING_APPROVAL` or `REJECTED` is not reachable from any public endpoint. | constraint | §4 | must |
| R34 | Approving a category does not change its products' publication state. | constraint | §4 | must |
| R35 | A product is not publicly reachable while its category is unapproved or not publicly visible. | constraint | §4 | must |
| R36 | The public visibility rules hold on every public endpoint: product list, product detail, search, taxonomy, reference data, cart and quote paths. | constraint | §4 "across all relevant public API endpoints" | must |
| R37 | Public endpoints do not disclose approval metadata, reviewer identity or rejection reason. | constraint | §5 "Prevent unauthorized users from accessing administrative data through public endpoints" | must |
| R38 | Only a Super Admin can approve or reject. | constraint | §5 | must |
| R39 | Only a Super Admin can perform privileged CRUD on another Admin's submission. | constraint | §5 | must |
| R40 | A regular Admin cannot write approval status, reviewer identity or approval timestamps. | constraint | §5 | must |
| R41 | Every operation is authorized in the backend independently of the payload or the endpoint used. | constraint | §5 | must |
| R42 | Authorization is applied to every existing path that can reach the same record, including any bulk or alternative operation. | constraint | §5 | must |
| R43 | Submission creation and resubmission are audited. | functional | §6 | must |
| R44 | Super Admin modifications are audited. | functional | §6 | must |
| R45 | Approval and rejection decisions are audited. | functional | §6 | must |
| R46 | Deletions of products and categories are audited. | functional | §6 | must |
| R47 | Audit records carry the acting user and the timestamp. | constraint | §6 | must |
| R48 | The rejection reason is audited when one was given. | functional | §6 | must |
| R49 | Auditing reuses the existing audit infrastructure. | constraint | §6 | must |
| R50 | Only fields the existing schema lacks are added. | constraint | §7 | must |
| R51 | Server-controlled statuses cannot be overwritten by a regular Admin. | constraint | §7 | must |
| R52 | Product-category relationships stay valid: no product keeps a category reference that the workflow made invalid. | constraint | §7 | must |
| R53 | Writes that must stay consistent run in a transaction. | constraint | §7 | must |
| R54 | The migration is safe: existing rows are preserved. | constraint | §7 | must |
| R55 | The migration preserves the current publication behaviour of existing rows. | constraint | §7 | must |
| R56 | Backend tests cover Admin submissions. | quality | §8 | must |
| R57 | Backend tests cover Super Admin CRUD on other Admins' submissions. | quality | §8 | must |
| R58 | Backend tests cover unauthorized CRUD and approval attempts. | quality | §8 | must |
| R59 | Backend tests cover explicit approval and rejection. | quality | §8 | must |
| R60 | Backend tests cover editing before approval. | quality | §8 | must |
| R61 | Backend tests cover reapproval of modified approved records. | quality | §8 | must |
| R62 | Backend tests cover rejection reasons and resubmission. | quality | §8 | must |
| R63 | Backend tests cover deletion constraints and product-category dependencies. | quality | §8 | must |
| R64 | Backend tests cover public API visibility restrictions. | quality | §8 | must |
| R65 | Backend tests cover approval bypass through alternative endpoints. | quality | §8 | must |
| R66 | Backend tests cover existing functionality and regressions. | quality | §8 | must |
| R67 | The relevant tests are executed and the actual results reported. | quality | §8 | must |
| R68 | No frontend code is modified. | constraint | Strict constraints | must |
| R69 | No unrelated refactoring and no unnecessary dependency. | constraint | Strict constraints | must |
| R70 | No duplication of existing CRUD or approval functionality. | constraint | Strict constraints | must |
| R71 | The report names changed files, migrations, test results and remaining limitations. | quality | §9.7 | must |

## Assumptions

| # | Assumption | Risk if wrong |
|---|------------|---------------|
| 1 | The existing catalog has no approval concept at all, so the status field, the submitting user, the reviewer, the decision timestamps and the rejection reason all have to be introduced. | low |
| 2 | Regular Admin means the existing `ROLE_ADMIN`; Super Admin means the existing `ROLE_SUPER_ADMIN`; the existing role hierarchy stays unchanged. | low |
| 3 | A regular Admin can create catalog content today only for categories, so allowing Admin product creation changes an existing authorization rule; that change is intended by §1. | med |
| 4 | The existing category visibility flag is the category publication control, so "unpublished category" means "hidden category". | med |
| 5 | Products currently have no publication flag; their presence in the catalog is their publication, so a new flag is needed and must default to published for legacy rows. | med |
| 6 | Reapproval is triggered by content modification of an `APPROVED` record and results in `PENDING_APPROVAL`, not in a parallel pending-change copy. | med |
| 7 | The audit infrastructure already in place is the existing append-only admin audit table; reusing it means writing through the same table and conventions. | low |
| 8 | Ownership of a submission is the creating user's identity, stored on the record. | med |
| 9 | A Super Admin creating a record directly is subject to the same "no automatic approval" rule, so the record is created `PENDING_APPROVAL` and a separate explicit approval is required. | med |

## Open questions

- [BLOCKING] The public visibility rule "a product must not be public if its category is unapproved or unpublished" does not exist today: storefront product reads do not consider category visibility at all, so products of currently hidden categories are reachable now. Enforcing the rule changes the current behaviour of existing data, which R55 asks to preserve. Which wins? Default if unanswered: the prompt's §4 rule wins going forward; legacy rows are migrated as approved and published, and any product under a currently hidden category becomes publicly unreachable — recorded as an intentional, reported change with a test.
- [BLOCKING] §2 gives the Super Admin "Create: Create a product directly", while §1 and §3 say CRUD must never approve. Does a Super Admin's own creation start approved, or pending like anyone else? Default if unanswered: pending, with a separate explicit approve call, because §3 is stated as absolute.
- [minor] Is a category "published" exactly when it is not hidden, or is a second, separate category publication flag expected? Default: not-hidden means published; no second flag.
- [minor] Can a regular Admin delete their own submission? §1 lists create, submit, view, edit and resubmit but not delete. Default: no; deletion is Super Admin only.
- [minor] Is the rejection reason required when rejecting? Default: optional; stored, shown to the owner and audited when present.
- [minor] Does editing a rejected submission leave it rejected until an explicit resubmission? Default: yes, editing alone keeps it rejected.
- [minor] Can an Admin edit an approved submission? Default: no; only pending and rejected submissions are editable by their owner.
- [minor] Is the submitting Admin allowed to change a slug, and what happens to existing links if the slug changes? Default: slug rules stay as they are today; a slug change on an owned pending submission is allowed and is a normal content update.
- [minor] What approval state does a record get when it is created by a direct database seed or fixture? Default: seeded rows are treated like legacy rows — approved and published.
- [minor] Does a Super Admin's modification of an approved record immediately remove it from the storefront? Default: yes, it becomes pending and therefore public-invisible until approved again.
- [minor] Are there bulk endpoints to authorize? Default: none exist today, so the requirement applies to whatever alternative paths exist (taxonomy, brand, delete-product path, storefront reference data) and no new bulk API is built.
- [minor] Does the storefront taxonomy endpoint's product count include pending products? Default: no; counts must reflect only publicly visible products, otherwise the count itself leaks unreviewed content.

## Out of scope

- Frontend, dashboard UI, storefront client changes.
- Email or notification to the submitting Admin on approval or rejection.
- Any approval state beyond the three named, and any workflow engine or configurable approval chain.
- Multi-reviewer approval, reviewer assignment, approval delegation or approval expiry.
- Approval of banners, brands, promotions, orders, payments or inventory adjustments.
- Backfilling an audit history for actions performed before this feature existed.
- Changing the product/category data model beyond what the workflow needs.
- Reworking pricing, variants or taxonomy semantics.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | When done |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add the approval schema safely and reversibly | R24, R31, R50, R54, R55 | Prompt §3, §4, §7 | Migration adding approval status, submitter, reviewer, decision timestamps, rejection reason and product publication flag, with legacy rows landed as approved and public | `migrations/**` | none | Migration up and down run clean on existing data, legacy rows keep their current visibility, and a second migrate run is a no-op |
| WP2 | Make illegal transitions impossible | R24–R30, R12 | Prompt §3 | Approval status value object with explicit allowed transitions, the reapproval rule, and typed exceptions for refusals | `src/Catalog/Domain/**` | WP1 | Unit tests accept all four transitions and refuse every other pair; refusals write nothing |
| WP3 | Admin submission and ownership rules | R1–R14, R39, R40 | Prompt §1, §5 | Commands, handlers and owner-scoped reads for create, submit, edit, resubmit and status/reason reads | `src/Catalog/Application/Command/**`, `src/Catalog/Application/CommandHandler/**`, `src/Catalog/Application/Query/**` | WP2 | Tests show pending by default, owner-only reads and edits, resubmission to pending, and no path from Admin input to `APPROVED` |
| WP4 | Super Admin CRUD and explicit decisions | R15–R23, R25–R28, R38, R39, R52, R53 | Prompt §2, §3, §5 | Commands and handlers for CRUD in every state, approve and reject with reviewer and timestamp, transaction boundaries | `src/Catalog/Application/Command/**`, `src/Catalog/Application/CommandHandler/**` | WP3 | Tests show CRUD on another Admin's record, approve/reject recording reviewer, refused illegal transitions, refused deletes leaving data intact |
| WP5 | Authorization and metadata defence at the admin edge | R13, R14, R37, R38, R39, R40, R41, R42 | Prompt §1, §5 | Admin routes with role and ownership checks, approval fields stripped from all input, consistent behaviour on every admin route that reaches these records | `src/Catalog/Infrastructure/Http/Admin*.php` | WP4 | Tests show refusal for Admin approve/reject and foreign-record writes, ignored injection of approval fields, and identical rules on alternative admin routes |
| WP6 | Public reachability matches approval and publication | R31–R37, R52 | Prompt §4 | Approval and publication predicates plus category gating in product list, detail, search, taxonomy and reference reads; search index kept in sync; counts restricted | `src/Catalog/Infrastructure/ReadModel/**`, `src/Catalog/Infrastructure/Search/**`, `src/Catalog/Infrastructure/Projection/**`, `src/Shared/Infrastructure/ReadModel/**`, `src/Shared/Infrastructure/Adapter/**` | WP1 | Tests show pending, rejected, unpublished and parent-blocked products unreachable on every public endpoint while approved+published ones are reachable; counts and search agree |
| WP7 | Audit the decisions | R43–R49, R53 | Prompt §6 | Audit writes for submission, resubmission, Super Admin modification, decision and deletion, in the same transaction, on the existing audit table | audit usage in `src/Catalog/Application/CommandHandler/**`; adapter placement decided at the pattern gate | WP4 | Tests show one audit row per action with actor, timestamp and reason, and no row for a refused action |
| WP8 | Prove every claim | R56–R67 | Prompt §8 | Unit, integration, projection and functional tests, executed with quoted output | `tests/**` | WP5, WP6, WP7 | Every R56–R66 item has a passing test or a reported failure; full-suite regression result recorded |
| WP9 | Report and check constraints | R68–R71 | Prompt §9, Strict constraints | Constraint verification and final report | none (verification only) | WP8 | No frontend file changed, no new dependency, no duplicated CRUD/approval path, and the report quotes real command output |

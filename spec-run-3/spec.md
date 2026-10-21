# Spec: Admin dashboard management endpoints — categories, users, banner
Version: 2

## Goal

The separate admin-dashboard frontend can perform every action the prompt lists through this
back end only: create and organise categories (root, sub-category, delete, hide), administer users
(delete, change role between `ROLE_USER` and `ROLE_SUPER_ADMIN`, read a user's orders), and change
the banner image set whose length follows exactly what the admin uploaded. Success = each listed
action is reachable through an authenticated JSON HTTP endpoint on the existing
`/api/v1/admin/...` surface, plus the reads the dashboard needs to drive it, with no UI in this
repository and no regression to the already-implemented product endpoints.

## Corrected prompt

For the admin dashboard, the admin must be able to manage:

- **products** — already implemented, nothing to do;
- **categories** — add a new category, add a sub-category to an existing one, delete a category, hide a category;
- **users** — delete a user, edit a user's role (`ROLE_USER`, `ROLE_SUPER_ADMIN`), see a user's orders;
- **banner images** — change the banner images; the banner should contain a dynamic number of images,
  related to the images uploaded by the admin in the dashboard.

The frontend app is a separate one: I only need to prepare the endpoints for that frontend.
Implement the admin dashboard management CRUD.

*(Turn 1 arrived truncated at `… related to the images upload`; the clause was restored by the
orchestrator from an earlier identical rendering of the same sentence and the user chose to proceed.
The restored wording is therefore reconstructed, not verbatim — see assumption 9.)*

## Requirements

### Categories

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The admin can create a top-level category (required label, optional slug) through `POST /api/v1/admin/catalog/categories`; the created category is returned by the admin category read and the storefront category read, and an empty/invalid label or a duplicate slug is refused without writing a row. | functional | turn 1, bullet 2 "add a new category" | must |
| R2 | The admin can add a sub-category to an existing category as a node in that parent row's `subcategories` JSON tree (representation locked 2026-10-20, see Q1); an unknown parent is refused with not-found, and a label that duplicates an existing sibling is refused without changing the parent. | functional | turn 1, bullet 2 "add a sub category for an existing one" | must |
| R3 | New taxonomy writes obey the limits already enforced in this repo for the JSON tree (`SubcategoryTree`: depth ≤ 4, label ≤ 128 chars) plus `CategoryHierarchyValidator` acyclicity where `parent_slug` rows exist, and `GET /api/v1/storefront/reference` keeps its current `slug`/`label`/`subs` shape so the live frontend contract does not break. | constraint | turn 1 bullet 2 + repo `SubcategoryTree`, `CategoryHierarchyValidator` | must |
| R4 | `DELETE /api/v1/admin/catalog/categories/{slug}` remains the delete-category path: an empty category is removed, deleting it again answers not-found (never `500`), and a category that still has children — counting every node of the parent's `subcategories` JSON tree, per the locked Q1 representation — or products is refused with the counts and nothing written. | functional | turn 1, bullet 2 "delete a category" (already implemented at `AdminCategoryController.php:56`) | must |
| R5 | The admin can hide and un-hide a category through an endpoint that persists a visibility flag on the category (new column, default visible, idempotent) and returns the resulting flag; hiding does not delete the category row, its children or its products. | functional | turn 1, bullet 2 "hide a category" | must |
| R6 | A hidden category **and its whole subtree** is absent from the storefront category/navigation read while it stays visible in the admin category list; the hidden category's **products remain reachable and searchable** (locked 2026-10-20, see Q3), covered by a test asserting both halves. | functional | turn 1, bullet 2 ("hide" is only meaningful if a read honours it) | must |
| R7 | Hiding or deleting a category never mutates `ordering_orders`, `ordering_order_lines` or any historical snapshot, and no old order is recomputed from the current catalog (ECO-006). | constraint | repo NEVERs, bound on turn 1 bullet 2 | must |
| R8 | `GET /api/v1/admin/catalog/categories` returns every category including hidden ones, with slug, label, parent, sub-levels, hidden flag and product count in a stable order. | functional | turn 2 "implement the admin dashboard managment crud" (list implied by "manage") | should |

### Users

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R9 | The existing `DELETE /api/v1/admin/customers/{id}` stays the only delete-user endpoint (delete when the user has no orders, anonymize when the user has orders, refuse when it would remove the last active super admin); no parallel delete path is added and its current contract is verified, not re-invented. | functional | turn 1, bullet 3 "delete a user" | must |
| R10 | The admin can set a user's role through a write endpoint; the value persists, is returned by the user reads, and equals what the target sees on their next authenticated request (`CustomerUser::getRoles()` reports the stored role, nothing silently added or dropped). | functional | turn 1, bullet 3 "edit the role of a user" | must |
| R11 | A role value outside the set named in the prompt (`ROLE_USER`, `ROLE_SUPER_ADMIN`) — including `ROLE_ADMIN`, an empty value or a missing field — is refused and changes nothing. | constraint | turn 1, bullet 3 "(ROLE_USER, ROLE_SUPER_ADMIN)" | must |
| R12 | Editing a role requires `ROLE_SUPER_ADMIN`; a `ROLE_ADMIN` requester gets `403` and no write occurs. | constraint | turn 1 "for the dashboard admin" + existing split in `AdminCustomerController.php:89` | must |
| R13 | No role change may leave the platform with zero active `ROLE_SUPER_ADMIN` accounts; the rule (including self-change and demoting another super admin) is enforced in the handler, not in the controller (SEC-002). | constraint | consequence of turn 1, bullet 3 + repo BRD RG3.6 | must |
| R14 | Every role change appends one `identity_audit_log` row (actor, target, previous/new role, timestamp) that the existing `GET /api/v1/admin/customers/{id}/audit` then returns. | functional | consequence of turn 1, bullet 3 + repo BRD RG3.5 | should |
| R15 | `GET /api/v1/admin/customers` lists users for the dashboard (id, name, email, phone, role, status, order count) and never exposes a password hash, reset token or ban internals. | functional | turn 2 "crud" (Read), not enumerated in turn 1 | should |
| R16 | `GET /api/v1/admin/customers/{id}/orders` returns that user's orders newest-first with order number, status, placed date, integer minor-unit totals plus currency and line items; an unknown user is not-found, a user without orders returns an empty list, and a malformed id is refused. The read is DBAL-only, authorizes the requester inside the handler, never persists/flushes/dispatches (QRY-001), and does not reuse the customer-scoped `ListOrdersForCustomer` query which carries no requester identity. | functional | turn 1, bullet 3 "see the orders of a user" | must |

### Banner

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R17 | The banner is greenfield: nothing named banner/carousel/slider exists in `src`, `config`, `migrations`, `docs` or `tests` (verified by grep), so its storage is created by a PostgreSQL-only Doctrine migration, reversible, applied in dev **and** test (INF-001), with no fixed-size assumption in the schema. | constraint | turn 1, bullet 4 (implies a thing that does not exist yet) | must |
| R18 | The admin can change the banner image set (add, replace, reorder, remove) so the stored and returned image count always equals what the admin selected — 1 image, 3 images and 7 images are all valid states; the cardinality is **1..10** (Q5 default). | functional | turn 1, bullet 4 "change the banner images" + "dynamic number of images" | must |
| R19 | Banner images are references to files already produced by the existing dashboard upload endpoint (`/api/v1/admin/catalog/uploads`, `{path,url}`); no new binary-upload or image-processing path is added, and an empty string, a path outside the uploads area or an arbitrary external URL is refused. | constraint | turn 1, bullet 4 "related to the images uploaded by the admin in the dashboard" | must |
| R20 | Banner image order is explicit and deterministic (stored sort column with a defined tie-break, not an unqualified `SELECT`), and the order written is the order returned by both the admin and the public read. | quality | turn 1, bullet 4 ("the banner" implies a slide sequence) | must |
| R21 | An unauthenticated public read returns the current banner as a variable-length ordered image array, with a defined answer (empty array or documented fallback) when no images remain. | functional | turn 1, bullet 4 + turn 2 "endpoints for the frontend" | should |
| R22 | Removing or replacing a banner image never deletes or moves the underlying file under `public/uploads/`. | constraint | turn 1, bullet 4 (no deletion asked for) | must |

### Cross-cutting

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R23 | Deliverables are backend JSON HTTP endpoints only: JSON in / JSON out (multipart only for the existing upload), error bodies in the existing `{"error": ...}` shape, and no Twig template, admin page, JS or asset added in this repository. | constraint | turn 2 "the frontend app is a separated one i just need to prepare the endpoints for the frontend only" | must |
| R24 | Every new endpoint is either under `/api/v1/admin/...` (inheriting the `^/api/v1/admin → ROLE_ADMIN` rule) or a documented public storefront read; nothing sits outside those two families, and each new route answers `401` to a guest and `403` to a plain `ROLE_USER`. | constraint | turn 1 lead "for the dashboard admin" + `security.yaml:36` | must |
| R25 | All new mutations and reads use **one** path: **CQRS** (locked by the user 2026-10-20) — command DTO + exactly one `command.bus` handler for writes, query + DBAL-only authorized finder returning DTOs for reads. The raw-SQL-in-controller style of `AdminProductController`/`AdminCategoryController` is legacy, gains no new sibling, and is not extended by any controller written under this spec; mixing the two inside this spec is a defect. | constraint | AGENTS.md 2/4/6 vs observed admin controllers; Q2 answer | must |
| R26 | Product management is already served and unchanged: no new or altered product, brand, promo-code or order-status endpoint, and no breaking change to their request/response contracts. | constraint | turn 1, bullet 1 "product(already implemented)" | must |
| R27 | Every new endpoint has a functional test covering the happy path, the negative authz cases (`401` guest, `403` customer) and at least one rejection path; each new handler has unit coverage of its domain rules; the Unit, Integration, Projection, Functional and Contract suites plus phpstan, php-cs-fixer and deptrac stay clean. | quality | repo Definition of Done, bound on turn 2 | must |
| R28 | The three design questions this spec could not answer from the prompt — sub-category representation (Q1), admin write path (Q2) and hide semantics (Q3) — were answered by the user on 2026-10-20 and are recorded in ADRs with their rejected alternatives before implementation starts. | constraint | ADR requirement of AGENTS.md for any new pattern/boundary decision; Q1/Q2/Q3 answers | must |

## Locked decisions (gate, 2026-10-20)

| Q | Decision | Consequence for implementation |
|---|----------|-------------------------------|
| Q1 | Sub-categories are nodes of the parent row's `subcategories` JSON tree. | No hierarchy migration; `SubcategoryTree` limits apply; delete guard counts JSON nodes, not `parent_slug` rows. |
| Q2 | New admin code follows the documented CQRS path; existing raw-SQL admin controllers stay legacy. | Commands + `command.bus` handlers + DBAL-only authorized query finders for every new write/read; deptrac and phpstan enforce it. |
| Q3 | Hiding a category hides it and its subtree from the storefront navigation read only. | One new `hidden` column; products stay reachable and searchable; admin list still shows hidden rows. |
| Q4 | "Delete a user" reuses `DELETE /api/v1/admin/customers/{id}` exactly as it behaves today. | No change to `DeleteCustomerWithAnonymizationHandler`; its PII/orphan/in-flight gaps move to backlog. |

**Environment note (orchestrator, 2026-10-20):** the Docker socket is not accessible to the current
user (`permission denied … /var/run/docker.sock`), so INF-002's `docker compose exec app …` form is
unavailable. Verification runs on host PHP 8.4.26 with PHPUnit 13.4.3 and
`DATABASE_URL="postgresql://app:app@127.0.0.1:5432/app?serverVersion=16&charset=utf8"` — the
published container Postgres, not SQLite (INF-001 still satisfied). The `Unit` suite passes as-is
(40/40). This deviation is recorded, not hidden.

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "The users" are rows of `customer_profile` — the only account table; admins are the same rows with a different scalar `role`, so a delete or role write can hit an admin account and must be governed. | med |
| 2 | "Already implemented" product management means the existing `AdminProductController` (create/update), `AdminCategoryController::deleteProduct`, `AdminUploadController` and `AdminBrandController`; nothing about them is to be rebuilt. | low |
| 3 | "Hide a category" is a reversible storefront-visibility flag, not a soft delete and not a multi-state enum; the column does not exist today so a schema change is expected. | med |
| 4 | Turn 2's "crud"/"manage" implies the matching admin reads (category list, user list, banner read): the enumerated writes in turn 1 are unusable from a list → select → edit dashboard flow. | med |
| 5 | The role enum in the prompt is exhaustive for this feature: `ROLE_ADMIN` stays unassignable through the dashboard even though it guards `/api/v1/admin`, and existing `ROLE_ADMIN` rows have no legal target value (see Q6). | med |
| 6 | "See the orders of a user" means registered-customer orders keyed by `ordering_orders.customer_id`; phone/WhatsApp proxy orders are not in that list unless Q7 says otherwise. | med |
| 7 | "The banner" is one site-level image set (hero/carousel) whose slides are the uploaded images; the prompt says "a dynamic number of images", not "a dynamic number of banners". | med |
| 8 | Banner data lives in an existing context (Catalog) as new tables rather than a 9th bounded context, which would itself need an ADR. | med |
| 9 | The orchestrator's restoration of the truncated banner clause is complete; if the cut-off sentence carried more (captions, links, per-page banners) this spec silently omits it (see Q8). | high |
| 10 | The uncommitted admin files in `git status` (`src/Catalog/Application/Service/SubcategoryTree.php`, `tests/Functional/AdminCatalogTest.php`, the admin controllers) are in-flight work on this same task, so they are inputs and constraints, not competing changes. | high |
| 11 | Money stays integer minor units in every new payload (ECO-001); no new float surfaces appear. | low |
| 12 | Privileges mirror the existing split: content writes and reads under `ROLE_ADMIN`, role change and user delete under `ROLE_SUPER_ADMIN`. | med |

## Open questions

- [ANSWERED 2026-10-20 — see Locked decisions] **Q1 — Sub-category mechanism:** the `subcategories` JSON tree inside the parent row (depth ≤ 4, label ≤ 128). Rejected: `parent_slug` rows, and dual storage.
- [ANSWERED 2026-10-20] **Q2 — Admin write path:** documented CQRS for all new endpoints; the raw-SQL `AdminProductController` style stays as legacy and gains no sibling.
- [ANSWERED 2026-10-20] **Q3 — Hide semantics:** new `hidden` column (default visible); hides the category **and its subtree** from the storefront navigation read only. Products stay reachable and searchable; the admin list still shows hidden rows. Rejected: hiding products too, and single-row non-cascading hide.
- [ANSWERED 2026-10-20] **Q4 — Delete a user:** reuse `DeleteCustomerWithAnonymizationHandler` as-is; its known gaps (order PII scrubbing of `customer_name`/`customer_phone`/`shipping_address`, orphan-row cleanup for `customer_address`/`storefront_cart`/`storefront_wishlist_item`/`customer_password_reset_token`/quote requests, in-flight-order blocking) move to backlog, not delivered here.
- [minor] **Q5 — Banner cardinality and change model:** minimum/maximum number of images, per-image active flag, and is the change per-item CRUD (`POST`/`PATCH/{id}`/`DELETE/{id}` + a reorder call) or a whole-set replacement (`PUT` the ordered list)? Turn 1 says only "change the banner images", turn 2 says "crud". Default if unanswered: 1..10 images, no per-image flag (removal is the mechanism), one replace-all endpoint plus list/read, since replace-all grows, shrinks and reorders in a single call.
- [minor] **Q6 — Is `ROLE_ADMIN` really excluded from the role editor?** The dashboard guard requires it, so with only the two named values no plain operator account can be created and an existing `ROLE_ADMIN` row cannot be edited to a legal value. Default if unanswered: accept only `ROLE_USER` and `ROLE_SUPER_ADMIN` as the prompt states (R11).
- [minor] **Q7 — Do proxy (phone/WhatsApp) orders appear in a user's order read?** Default if unanswered: registered-customer orders only, with the chosen behaviour asserted by a test rather than silently dropped (R16).
- [minor] **Q8 — Does the restored banner clause end where the orchestrator reconstructed it?** If the truncated sentence also mentioned captions, links or per-page banners, those fields are missing from R18–R21. Default if unanswered: image path + order + id only; no captions/links/alt text.
- [minor] **Q9 — Is the public banner read (R21) in scope, or does the separate storefront get the images from the admin app?** Turn 2's "the frontend" is ambiguous about which of the two frontends. Default if unanswered: ship the unauthenticated public read.
- [minor] **Q10 — Does the category editor expose rename and the existing `sort_order` / `quote_eligible` columns?** `slug` is the PK and is referenced by `storefront_product_view.category_slug`. Default if unanswered: label and flags editable, slug frozen after creation, `sort_order` auto-assigned.
- [minor] **Q11 — Pagination/filters for `GET /api/v1/admin/customers`** (search over name/email/phone, role filter, page size). Default if unanswered: `page`/`limit` (max 50), no free-text or role filters.
- [minor] **Q12 — Concurrent edits by two admins on the same category or banner set:** these tables have no `version` column. Default if unanswered: accept lost updates for admin surfaces and record it; no optimistic-locking column added.
- [minor] **Q13 — Cross-origin session for the separate admin SPA:** auth is session-cookie based and CORS comes from `STOREFRONT_CORS_ORIGIN` (today `http://localhost:3000`). Is adding the admin origin in scope of "prepare the endpoints"? Default if unanswered: no config change here; flagged to the frontend team.
- [minor] **Q14 — Is a written endpoint contract/OpenAPI file owed to the frontend team, or are code plus contract tests enough?** Default if unanswered: `tests/Contract/**` only, no documentation file.

## Out of scope

- Any admin UI, page, template, component or JS asset in this repository — the frontend is a separate app (turn 2).
- Product, brand, promo-code, shipping-policy, governorate, FAQ and platform-parameter management, and product multi-image galleries: already implemented or never requested (`storefront_product_view.image_path` being single is unrelated to the banner).
- Order management beyond reading one user's orders: creation, status transitions, proxy orders and order audit already exist in `AdminOrderController`.
- Authentication, login/registration, password reset, impersonation, ban/unban (already implemented), admin account creation/invitation, 2FA, granular per-screen permissions, teams.
- Gaps of the existing delete-user handler unless Q4 is answered "yes": order PII scrubbing, orphan-row cleanup, in-flight-order blocking.
- Media library, cropping, resizing, thumbnails, `srcset`, CDN, storage backend change, orphan-file garbage collection.
- Banner captions/CTA/alt text, scheduling, A/B rotation, per-region targeting, videos, click/impression analytics, multiple simultaneous banners.
- Category merge/move/re-parent tooling, bulk CSV import/export, label translation, SEO metadata, slug rename.
- Meilisearch index rebuild policy for hidden categories beyond whatever Q3 decides for the SQL reads.
- Cart, checkout saga, stock, payment ledger, pricing/promotion recalculation.
- Deployment, FrankenPHP/Caddy, CI, CORS origin values, rate limiting, retention jobs, GDPR export.
- Storage/solve-phase design beyond what R17 and R25 already bind (table layout, aggregate shape, projection wiring) — that belongs to the solve phase and its ADRs.

## Work packages

WP1 fixes the schema and the three ADRs; everything else depends on it and no other package ever
edits `migrations/**` or `docs/adr/**`. WP2–WP5 own disjoint controller/command/read-model globs, so
they run in parallel after WP1. WP6 is read-only on `src/**` and routes defects back to the owning WP.

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Decisions and DDL: answer Q1–Q4 as ADRs and ship all schema for the spec in one reversible migration (category visibility column with visible default and backfilled seed rows; banner tables with explicit sort column; no fixed-size assumption) | R17, R20 (schema), R28, and the schema halves of R5 and R18 | Q1–Q5 answers, `migrations/Version20261017000002.php`, `Version20261017000004.php`, `Version20261018000000.php`, AGENTS.md, `.agents/rules/{commands,queries,events,layering,security}.md`, `docs/BUSINESS-REQUIREMENTS-SPECIFICATION.md` RG1.x/RG3.x/RC4/RC11 | `docs/adr/ADR-000x-category-representation-and-visibility.md`, `docs/adr/ADR-000y-admin-write-path.md`, `docs/adr/ADR-000z-banner-model.md` + one migration applied to dev **and** test with a working `down()` | `docs/adr/**`; `migrations/**` | — (needs the Q1/Q2/Q3/Q4 answers) | each blocking question has one written answer with rejected alternatives; `doctrine:migrations:migrate` clean in both envs on PostgreSQL; hidden defaults to visible so seeded taxonomy stays visible |
| WP2 | Category management: create, add sub-level, hide/unhide, list, and the existing delete guard counted correctly under the chosen representation — plus the storefront navigation read that honours the hidden flag | R1, R2, R3, R4, R5, R6, R7, R8 | WP1 ADRs + column contract, `src/Catalog/Infrastructure/Http/AdminCategoryController.php`, `src/Catalog/Application/Service/SubcategoryTree.php`, `CategoryHierarchyValidator`, `src/Shared/Infrastructure/ReadModel/StorefrontReferenceRepository.php`, WIP `tests/Functional/AdminCatalogTest.php:292-352` | Admin category create / add-sub-level / hide+unhide / list endpoints, hidden-aware storefront reference read, Unit + Integration + Functional tests | `src/Catalog/Infrastructure/Http/AdminCategoryController.php`; new `src/Catalog/Application/Command/Category*`, `src/Catalog/Application/CommandHandler/Category*`, `src/Catalog/Domain/**` (new files only); `src/Shared/Infrastructure/ReadModel/StorefrontReferenceRepository.php`; `tests/Functional/AdminCatalogTest.php`, new `tests/Functional/AdminCategoryManagementTest.php`, `tests/Unit/Catalog/**`, `tests/Integration/Catalog/**` | WP1 | an admin creates a category, adds a sub-level, hides it and sees it in the admin list but not in `GET /api/v1/storefront/reference`; delete still refuses non-empty with correct counts; hidden rows never deleted; Catalog suites + phpstan + php-cs-fixer + deptrac clean |
| WP3 | User administration: role editor with the two-value enum, super-admin-only and last-super-admin guards, audit row, user list; the existing delete endpoint verified untouched | R9, R10, R11, R12, R13, R14, R15 | WP1 write-path ADR, `src/Identity/Infrastructure/Http/AdminCustomerController.php`, `customer_profile` scalar `role`/`status`, `CustomerUser`, `CustomerProfileUserProvider`, `identity_audit_log`, `DeleteCustomerWithAnonymizationHandler` (reference only if Q4 = keep) | `PATCH /api/v1/admin/customers/{id}/role` (guards + audit), `GET /api/v1/admin/customers`, delete-user regression assertion, Unit + Functional tests | `src/Identity/Infrastructure/Http/AdminCustomerController.php`; new `src/Identity/Application/Command/ChangeCustomerRole.php`, `src/Identity/Application/CommandHandler/ChangeCustomerRoleHandler.php`, `src/Identity/Application/Query/AdminCustomers*`, `src/Identity/Infrastructure/ReadModel/AdminCustomerFinder.php`, new `src/Identity/Domain/Model/**` exceptions; `tests/Unit/Identity/**`, `tests/Integration/Identity/**`, `tests/Functional/AdminCustomerManagementTest.php` | WP1 | promoting grants a `ROLE_SUPER_ADMIN` route on the next request, demoting the last active super admin is refused with no write, `ROLE_ADMIN` gets `403`, an out-of-set value changes nothing, the change appears in the user list and in `{id}/audit`, and the delete endpoint's existing contract still passes |
| WP4 | Read one user's orders for the dashboard as an authorized DBAL query read model with no N+1 | R16, R24 (route family) | WP1 write-path ADR, `ordering_orders`/`ordering_order_lines` DDL, `ListOrdersForCustomer` + `CustomerOrderFinder` shapes (must not be edited — new query file instead), `AdminOrderController` conventions | `GET /api/v1/admin/customers/{id}/orders` as its own query + finder + dedicated admin controller, with Functional + Integration tests | new `src/Ordering/Infrastructure/Http/AdminCustomerOrderController.php`, `src/Ordering/Application/Query/ListOrdersForAdminRequestedCustomer.php`, `src/Ordering/Infrastructure/ReadModel/AdminCustomerOrderFinder.php`; `tests/Functional/AdminCustomerOrdersTest.php`, `tests/Integration/Ordering/**`, `tests/Unit/Ordering/**` | WP1 | an admin gets that user's orders newest-first with integer minor-unit totals and lines in one line query, `[]` for a user without orders, not-found for an unknown user, `401`/`403` for guest/customer, no other customer's row ever returned, and the handler provably never persists/flushes/dispatches |
| WP5 | Banner: admin CRUD over the ordered image set plus the public ordered read, sourced only from dashboard-upload output | R18, R19, R20, R21, R22 | WP1 ADR + banner tables, `AdminUploadController` `{path,url}` contract (reference only, never edited), `AdminBrandController` `{items:[…]}` response convention | Admin banner list / replace-images / reorder / delete endpoints, public `GET` banner read, Unit + Functional tests | new `src/Catalog/Infrastructure/Http/AdminBannerController.php`, `src/Catalog/Infrastructure/Http/StorefrontBannerController.php`, `src/Catalog/Application/Command/Banner*`, `src/Catalog/Application/CommandHandler/Banner*`, `src/Catalog/Application/Service/BannerRules.php`, `src/Catalog/Domain/Model/Banner*`, `src/Catalog/Infrastructure/ReadModel/BannerFinder.php`; `tests/Unit/Catalog/Banner*`, `tests/Integration/Catalog/Banner*`, `tests/Functional/AdminBannerTest.php`, `tests/Functional/StorefrontBannerTest.php` | WP1 | the same admin can save 1, 3 and 7 images and read back exactly that count in the sent order from both admin and public endpoints; an unknown/foreign image reference is refused; deleting the last image yields the Q5-declared answer; removing a reference leaves the file on disk |
| WP6 | Surface proof and contract freeze: authz matrix, routability, product non-regression, whole-suite gate | R23, R24, R25, R26, R27 | WP2–WP5 route lists, `config/packages/security.yaml`, `config/routes.yaml`, `config/routes/storefront_api.yaml` (re-imports only Catalog/Cart/Ordering/Identity/Shared `Infrastructure/Http/`), existing product/brand/promo tests | One matrix suite hitting every new route as guest / `ROLE_USER` / `ROLE_ADMIN` / `ROLE_SUPER_ADMIN`, a route-discovery architecture test, locked request/response contracts in `tests/Contract/**`, and a green Definition-of-Done run; failures routed back as defects, no new feature files | `tests/Functional/AdminSurfaceAuthorizationMatrixTest.php`, `tests/Architecture/AdminRouteDiscoveryTest.php`, `tests/Contract/AdminDashboard/**`; read-only on `src/**` | WP2, WP3, WP4, WP5 | every new route is in the matrix and passes; no new controller is silently unrouted; all five suites plus phpstan, php-cs-fixer, deptrac and infection on touched Domain/Application code are clean; product/brand/promo/order-status contracts unchanged |

## Decisions (synthesizer only)

| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Requirement count / granularity | 16 broad items | 40, one per status code | 44, incl. repo-rule derived work | 28 | Prompt faithfulness first: B's per-code enumeration is solve-phase detail folded into R1/R2/R4/R11, C's repo-derived extras moved to Q4/out of scope; near-duplicates merged rather than listed |
| Sub-category representation | default = JSON tree | JSON tree (A18) | BLOCKING, must be ADR'd | Q1 BLOCKING with JSON-tree default | Only the skeptic checked what actually reads/writes each mechanism; the choice moves validation, delete counting and possibly a migration, so a human (or the WIP test) must pin it |
| Hidden category: schema | new column, high risk | persisted boolean, storefront nav excludes it | no column exists (grep zero hits), cascade + product visibility undecided | R5+R6 keep the column and nav exclusion; products/search/cascade → Q3 BLOCKING | All three agree hiding needs new schema state and an observable read effect; where they disagree is exactly the rework-risk boundary |
| Role enum | two named roles only | two named roles, 422 otherwise | ROLE_ADMIN omission flagged as blocking, default = accept all three | R11: exactly the two named values; Q6 minor | The user's words enumerate two roles; the fix cost of being wrong is one enum value, so faithfulness beats speculation (step 2 + 3) |
| Delete a user | already present, verify only | keep existing contract | extend with order-PII scrub, orphan-row cleanup, in-flight block | R9 keep-as-is; gaps are Q4 BLOCKING + out of scope | Step 3: C-only items are grounded in repo rules, not the prompt; touching a working handler is the damaging direction, so it is escalated instead of assumed |
| Role audit + mandatory "reason" field | not raised | must, reason required | must (RG3.5) | R14 should for the audit row; reason field dropped | The audit table already exists and is cheap (two analysts backed it); an invented required field is not in the prompt |
| Reads / list endpoints | R14–R16 `should` | `must` (list → select → edit flow) | R14/R28 `must` | kept, `should` for category/user list, `must` for user orders (explicitly requested) | Turn 1 enumerated writes only; reads are an assumption (A4) so they cannot outrank the prompt's own items |
| Public banner read | minor question, default both | BLOCKING, default ship | R32 must | R21 `should`, kept; Q9 minor | "The banner" is meaningless unreadable and all three lean to including it, but it is not in turn 1's words — low risk, so stated default instead of blocking |
| Banner change model (per-item vs replace-all, cardinality) | unbounded + explicit order | replace-all + min 1..12 | BLOCKING, per-item + sort | merged into R18/R20 + Q5 minor with default | Order is required by all three; the endpoint shape is cheap to extend, so it does not deserve a blocker |
| Delete uploaded files | out of scope | keep files (R19) | keep files (Q13) | R22 keep files | None of the three asked for GC; deleting files was never requested |
| Upload path / folder change for banners | reuse existing upload output | should: banner sub-directory, contract unchanged | reuse existing convention | R19 reuse only, no upload change | The prompt says the images come "from the images uploaded by the admin"; it does not ask to modify uploads |
| CORS / admin SPA origin | not raised | R37 must + WP7 | Q21 minor, default no change | Q13 minor, out of scope of the endpoints | Config change for a consuming app nobody has identified is scope creep; flagged instead |
| Optimistic concurrency on category/banner | not raised | not raised | R43 should (BRD RC4) | Q12 minor, default accept lost updates | Single-analyst item with no prompt grounding and no damage story |
| Meilisearch visibility semantics | not raised | out of scope | inside Q4/Q5 discussion | out of scope, except as folded into Q3 | The prompt says nothing about search; only SQL reads are bound here |
| Proxy orders in a user's order list | excluded (A5) | R34 should assert behaviour | Q19 payload-shape question | R16 silent, Q7 minor with default | All three differ on mechanics; behaviour needs a test either way and the default matches `customer_id` reality |
| Contract deliverable for the frontend team | not raised | no doc | WP9 contract suite | WP6 `tests/Contract/**`, Q14 minor | Code plus locked contract tests is the existing repo convention; a new doc file is unrequested |
| Admin write path (CQRS vs raw SQL) | silent | R38 must = CQRS | R40 must, BLOCKING (repo contradicts itself) | Q2 BLOCKING, default CQRS for new code, legacy untouched | Verified finding: the existing admin controllers bypass CQRS with direct DBAL writes while AGENTS.md forbids it; the cost delta across every WP is too large to guess |
| Work-package shape | 4 parallel WPs | 8 WPs incl. separate storefront-read, CORS and gate WPs | 9 WPs incl. decision and migration WPs | 6 WPs: decisions+DDL, categories, users, user-orders, banner, surface gate | One WP per capability area; B's WP2 (nav read) merged into WP2 to keep the visibility column's single owner, B's CORS WP7 dropped (Q13), A's verify-only WPs folded into their owning area |
| Frontend/UI work | excluded | excluded | excluded | Out of scope (R23) | Unanimous and mandated by turn 2 |

## Changelog (orchestrator only)
- v1: created by synthesizer from analyst-a/b/c
- v2: gate — Q1–Q4 answered by the user (all defaults accepted), Locked decisions table added, R2/R3/R4/R6/R13/R18/R28 reworded to remove question dependencies, Q5 cardinality fixed at 1..10, environment note added

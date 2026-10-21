# Spec: Admin dashboard management endpoints (categories, users, banner)

Version: 1

## Goal

Provide the backend JSON HTTP endpoints the separate admin-dashboard frontend needs to manage categories, users and the site banner — while product management is already served by existing endpoints. Success = every listed admin action is reachable through an authenticated `/api/v1/admin/...` endpoint that returns JSON, with no UI built in this repo.

## Corrected prompt

Turn 1 — "For the dashboard admin, the admin should be able to manage:"
- product — already implemented;
- categories — add a new category, add a sub-category for an existing one, delete a category, hide a category;
- users — delete a user, edit a user's role (ROLE_USER, ROLE_SUPER_ADMIN), see the orders of a user;
- banner images — change the banner images; the banner should contain a dynamic number of images related to the images uploaded by the admin in the dashboard.

Turn 2 — "The frontend app is separate; I only need to prepare the endpoints for the frontend. Implement the admin dashboard management CRUD."

Intent, unchanged: this is a backend-API-only task targeting the admin dashboard surface for categories, users and the banner. Product is called out as done. (The final clause of turn 1 was restored verbatim from an earlier identical rendering, per the orchestrator's truncation note.)

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | Product-management endpoints already exist (create/update at `/api/v1/admin/catalog/products`, delete at `/api/v1/admin/catalog/products/{slug}`, image upload at `/api/v1/admin/catalog/uploads`, brand CRUD) and this effort adds no new product endpoints and does not alter them. | constraint | turn 1, bullet 1 "product(already implemented)" | must |
| R2 | An endpoint lets the admin create a new top-level category (label + slug) that is persisted and returned by category reads. | functional | turn 1, bullet 2 "add a new category" | must |
| R3 | An endpoint lets the admin add a sub-category to an existing category. | functional | turn 1, bullet 2 "add a sub category for an existing one" | must |
| R4 | An endpoint lets the admin delete a category by identifier. (Already present: DELETE `/api/v1/admin/catalog/categories/{slug}` — satisfied by existing behaviour; verify only.) | functional | turn 1, bullet 2 "delete a category" | must |
| R5 | An endpoint lets the admin hide a category (set its hidden state) and that hidden state is readable. | functional | turn 1, bullet 2 "hide a category" | must |
| R6 | An endpoint lets the admin delete a user by id. (Already present: DELETE `/api/v1/admin/customers/{id}`, ROLE_SUPER_ADMIN — verify only.) | functional | turn 1, bullet 3 "delete a user" | must |
| R7 | An endpoint lets the admin change a user's role to one of ROLE_USER or ROLE_SUPER_ADMIN. | functional | turn 1, bullet 3 "edit the role of a user(ROLE_USER, ROLE_SUPER_ADMIN)" | must |
| R8 | An endpoint lets the admin read the list of orders belonging to a specified user. | functional | turn 1, bullet 3 "see the orders of a user" | must |
| R9 | Endpoints let the admin change the banner images: set, replace, or remove the ordered set of images the banner shows. | functional | turn 1, bullet 4 "change the banner images" | must |
| R10 | The banner holds a dynamic (variable) number of images — adding or removing images changes the returned count, with no fixed size. | quality | turn 1, bullet 4 "the banner should contain a dynamic number of images" | must |
| R11 | The banner images are drawn from images uploaded by the admin through the dashboard. | functional | turn 1, bullet 4 "related to the images uploaded by the admin in the dashboard" | must |
| R12 | Deliverables are backend JSON HTTP endpoints only; no admin UI / frontend is produced in this repository. | constraint | turn 2, line 1 "the frontend app is a separated one i just need to prepare the endpoints for the frontend only" | must |
| R13 | All new endpoints sit on the dashboard-admin surface (`/api/v1/admin/...`) and are restricted to authenticated admin users (the global `^/api/v1/admin → ROLE_ADMIN` guard). | constraint | turn 1 lead "for the dashboard admin" + turn 2, line 2 "admin dashboard managment crud" | must |
| R14 | A read/list endpoint returns the categories (with their sub-categories and hidden state) so the dashboard can display them. | functional | turn 2, line 2 "crud" (Read) + turn 1, bullet 2 | should |
| R15 | A read/list endpoint returns the users (with their role) so the dashboard can display them. | functional | turn 2, line 2 "crud" (Read) + turn 1, bullet 3 | should |
| R16 | An endpoint returns the current banner (its ordered images) for consumers to render. | functional | turn 1, bullet 4 "the banner should contain … images" + turn 2, line 1 | should |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| A1 | "Already implemented" product = the existing `AdminProductController` (create/update), `AdminCategoryController::deleteProduct`, `AdminUploadController`, `AdminBrandController`. | low |
| A2 | "Hide a category" is a soft visibility flag that excludes the category from category reads when hidden; no such column exists on `storefront_category` today, so implementing it requires new schema state. | high |
| A3 | Role editing is limited to the two roles named in the prompt (ROLE_USER, ROLE_SUPER_ADMIN); ROLE_ADMIN, though present in the role hierarchy, is not assignable through this feature. | med |
| A4 | Privileged writes (role change, user delete) require ROLE_SUPER_ADMIN, matching the guard already on those endpoints; the rest fall under the inherited ROLE_ADMIN rule. | med |
| A5 | "See the orders of a user" means registered-customer orders keyed by `ordering_orders.customer_id` (reuses `ListOrdersForCustomer`); phone/WhatsApp proxy orders carry a synthetic customer id and are not in that list. | med |
| A6 | Banner images are supplied through the output of the existing admin image-upload endpoint (its `{path,url}` result) rather than a new upload endpoint. | med |
| A7 | Turn 2 "crud" implies the read/list endpoints (R14–R16); turn 1 only enumerated write actions. | med |

## Open questions

- [BLOCKING] "Add a sub-category for an existing one" means which of the two models already in code: (a) a child category via the `parent_slug` adjacency, or (b) a node in the category's `subcategories` JSON tree (`SubcategoryTree`)? Default if unanswered: (b) add to the named category's `subcategories` JSON tree.
- [BLOCKING] Which roles are assignable via the edit-role endpoint — only ROLE_USER and ROLE_SUPER_ADMIN as listed, or also ROLE_ADMIN? Default if unanswered: restrict to the two named roles.
- [minor] Effect of "hide": should hidden categories be removed from storefront/admin category reads and be un-hidable? Default if unanswered: hide = soft flag excluded from reads, toggle both directions.
- [minor] Is a public storefront banner-read endpoint in scope, or only admin management? Default if unanswered: provide both the admin change endpoints (R9) and a read (R16).
- [minor] "Dynamic number of images": is there a maximum count or a required ordering? Default if unanswered: unbounded count with an explicit sort order.
- [minor] Delete-category currently refuses non-empty categories (those with children or products). Keep that guard or add force/cascade? Default if unanswered: keep the existing non-empty guard unchanged (R1/R4 preserve current behaviour).
- [minor] Which list/read endpoints the dashboard actually needs (list categories, list users) is not enumerated in turn 1. Default if unanswered: one list endpoint per managed resource (R14, R15).

## Out of scope

- The admin dashboard UI / frontend application (it is a separate app — turn 2).
- Any new or altered product endpoints or product schema (turn 1, bullet 1 "already implemented").
- Authentication, login, registration and password-reset flows.
- Creating, editing or deleting orders (checkout, status transitions, proxy orders) beyond reading a user's orders.
- Promotions, shipping, inventory, brands, quotes, contact and newsletter management (not requested).
- Deleting or garbage-collecting uploaded image files from disk.
- Storage/solution design (tables, aggregates, commands, projections) — that belongs to the solve phase, not this spec.

## Work packages

All four packages own disjoint files (routes are auto-discovered via `#[Route]` attributes and the admin guard is already global, so no shared file is edited) and can run in parallel.

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 Categories (Catalog) | Admin endpoints to create a category, add a sub-category, hide a category, and list categories; keep delete-category intact | R2, R3, R5, R14, R13; verify R4 | Existing `AdminCategoryController`, `CategoryHierarchyValidator`, `SubcategoryTree`, `storefront_category`, ROLE_ADMIN guard | Admin create/add-subcategory/hide(+unhide)/list endpoints + a category-hidden schema column + Catalog tests | `src/Catalog/Infrastructure/Http/AdminCategoryController.php`; new `src/Catalog/Application/**` + `src/Catalog/Domain/**` files; new `migrations/Version<…>_category_hidden.php`; `tests/**/Catalog/**` | — | Endpoints return JSON, admin-guarded; hidden flag persisted and honoured by reads; delete-category still guarded; Catalog tests + phpstan + php-cs-fixer + deptrac clean |
| WP2 Users (Identity) | Admin endpoint to change a user's role (ROLE_USER/ROLE_SUPER_ADMIN) and to list users; verify delete-user | R7, R15, R13; verify R6 | `customer_profile.role`, `AdminCustomerController`, `CustomerUser`, existing delete handler | ChangeUserRole command+handler (command.bus), PATCH/PUT role endpoint, admin list-users read endpoint, Identity tests (no migration — column exists) | `src/Identity/Infrastructure/Http/AdminCustomerController.php`; new `src/Identity/Application/Command/ChangeUserRole*.php`, CommandHandler, query/read files; `tests/**/Identity/**` | — | Role set to allowed values persists and is reflected in `getRoles`; list-users returns JSON; super-admin guard honoured; Identity tests green |
| WP3 Orders-of-a-user (Ordering) | Admin read endpoint returning the orders for a given user id | R8, R13 | `ListOrdersForCustomer` + `CustomerOrderFinder` (DBAL `WHERE customer_id = :customer`), `ordering_orders.customer_id` | Admin GET endpoint under `/api/v1/admin/...` returning a user's order DTOs (DBAL-only, authorized), in a dedicated admin controller that does not touch WP1/WP2 files | New `src/Ordering/Infrastructure/Http/**` admin controller + needed `src/Ordering/Application/Query/**` / `ReadModel/**` files; `tests/**/Ordering/**` | — | Given a user id, returns only that user's orders as DTOs, DBAL-only, admin-guarded; Ordering tests green |
| WP4 Banner (greenfield) | Admin endpoints to change the banner image set (ordered, dynamic count, sourced from admin uploads) and a read of the current banner | R9, R10, R11, R16, R13 | Existing `AdminUploadController` `{path,url}` output for image provenance; no banner storage exists yet | Banner table via new migration, admin change endpoints (add/replace/remove/order), banner read endpoint, dedicated tests | New `migrations/Version<…>_banner.php`; new banner controller + read-model files under one area no other WP touches; dedicated `tests/**` banner subtree | — | Admin can set/replace/remove an arbitrary number of images and read them back with order preserved; R16 read works; tests + static analysis clean |

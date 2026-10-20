# Spec: Admin CRUD for home page banners and products
Version: 1

## Goal

Enable the admin to perform full CRUD on banners associated with the home page and full CRUD on products.

## Corrected prompt

- Admin can CRUD banners that are on the home page.
- Admin can CRUD products.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The admin can **create** a banner. | Functional | « admin now can crud banners » | Must |
| R2 | The admin can **read** (retrieve / list) banner(s). | Functional | « admin now can crud banners » | Must |
| R3 | The admin can **update** a banner. | Functional | « admin now can crud banners » | Must |
| R4 | The admin can **delete** a banner. | Functional | « admin now can crud banners » | Must |
| R5 | Banner CRUD applies to banners **that are on the home page**. | Functional | « banners that is in the home page » | Must |
| R6 | The admin can **create** a product. | Functional | « admin can crud product » | Must |
| R7 | The admin can **read** (retrieve / list) product(s). | Functional | « admin can crud product » | Must |
| R8 | The admin can **update** a product. | Functional | « admin can crud product » | Must |
| R9 | The admin can **delete** a product. | Functional | « admin can crud product » | Must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| A1 | « CRUD » means Create, Read, Update, and Delete for both banners and products. | low |
| A2 | « Admin » denotes an authenticated administrative actor (role / interface), not storefront customers. | med |
| A3 | Home-page banners are the same banners shown on the storefront home page (not a separate catalog). | med |
| A4 | Product CRUD extends admin product capabilities beyond whatever exists today (context: admin DELETE only today). | med |
| A5 | Banner and product CRUD are delivered via the existing Symfony CQRS e-commerce backend and related admin / storefront apps unless the prompt implies otherwise. | med |

## Open questions

1. Which **attributes** must a banner have (image, title, link, order, active flag, schedule, etc.)? The prompt does not specify.
2. Must the **storefront home page** load banners from the backend after admin CRUD, or is admin CRUD alone sufficient? The prompt ties banners to the home page but does not say « replace hardcoded carousel ».
3. Is **product CRUD** limited to the Catalog context and existing product model, or does it include related entities (variants, media, categories)? The prompt says only « product ».
4. Where does the admin perform CRUD (**dedicated admin UI**, API only, both)? The prompt says « admin can » but not the channel.
5. Singular « product » in the prompt: one product type / aggregate only, or all products in catalog? Literal reading: products generically.

## Out of scope

- Any capability not stated in the prompt (e.g. customer-facing banner CRUD, banner CRUD on non–home-page placements, bulk import, analytics, A/B testing).
- Decisions and changelog (explicitly skipped per analyst instructions).
- Non-admin actors performing banner or product CRUD (prompt specifies admin only).

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP-BE-BANNER | Backend banner aggregate and admin CRUD operations for home-page banners. | R1–R5 (persistence + admin API / commands) | Symfony CQRS Catalog (or agreed) context; no Banner entity today | Banner domain model, commands/queries, handlers, persistence, admin endpoints exposing create/read/update/delete | New backend files under a dedicated Banner module / namespace (no overlap with Product command handlers) | — | Automated or manual checks: admin can create, read, update, and delete a banner; stored banners are identifiable as home-page banners per R5 |
| WP-BE-PRODUCT | Backend admin product CRUD (create, read, update, delete). | R6–R9 | Existing Catalog product model; context: admin DELETE exists today | Admin commands/queries/handlers and endpoints for product create, read, update, delete | Existing Product admin command/query files and related API routes only (not Banner module files) | — | Admin can create, read, update, and delete a product via backend admin surface |
| WP-FE-STORE-HOME | Storefront home page uses admin-managed home-page banners instead of static slides. | R5 (consumer side of home-page banners) | WP-BE-BANNER read API contract; context: hardcoded carousel in `Zidane_front_web/src/routes/index.tsx` | Home route fetches and renders banners from backend | `Zidane_front_web` home route and banner-specific components only | WP-BE-BANNER (read contract stable) | Home page carousel content reflects banners returned by backend; no hardcoded slide list required for production behavior |
| WP-ADMIN-UI-BANNER | Admin UI for banner CRUD. | R1–R5 | WP-BE-BANNER API | Screens/forms/actions for list, create, edit, delete banner | Admin app banner pages and banner API client modules only | WP-BE-BANNER | Admin user can complete all four CRUD operations on home-page banners through the admin UI |
| WP-ADMIN-UI-PRODUCT | Admin UI for product CRUD. | R6–R9 | WP-BE-PRODUCT API | Screens/forms/actions for list, create, edit, delete product | Admin app product pages and product API client modules only (separate from banner UI files) | WP-BE-PRODUCT | Admin user can complete all four CRUD operations on products through the admin UI |

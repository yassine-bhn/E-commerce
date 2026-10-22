# F-list-filters-search-pagination

Workflow: feature
Requirement: Make every collection read endpoint bounded, filterable and searchable, with Meilisearch
as the primary path for storefront catalog browse/search and a documented read pattern per endpoint
class (Meilisearch offset, SQL keyset/cursor, SQL offset).

## Spec traceability
- Spec: .trae/specs/implement-list-filters-search-pagination/spec.md (version 1)
- Tasks: .trae/specs/implement-list-filters-search-pagination/tasks.md
- Checklist: .trae/specs/implement-list-filters-search-pagination/checklist.md
- Requirements: R1..R12 (spec "ADDED Requirements" + MODIFIED/REMOVED)

## Gate log
- GATE: SPEC ok — 2026-10-22T00:00:00Z — orchestrator (spec authored before this session; Tasks 1, 2, 12 already complete)
- GATE: DONE — 2026-10-22T17:02:17+01:00 — orchestrator (430 tests / 2672 assertions, phpstan + cs-fixer + deptrac clean, checklist fully ticked, 16/16 rows walked live)
- Baseline: Unit+Projection 172 tests green; Functional/Integration/Contract 168 tests, 2 pre-existing
  failures in StorefrontApiTest (fake customer not present in app_test → 401), unrelated to this spec.

## Progress
- Task 1 (shared primitives): done — src/Shared/Application/Pagination/**, ListQueryParams, tests/Unit/Shared/Pagination.
- Task 2 (ADR-0009 + ADR-0005): done — docs/adr/ADR-0009-list-read-patterns.md.
- Task 12 (indexes migration): done — migrations/Version20261022000000.php.
- Task 3 (Meilisearch indexer): done — `src/Catalog/Infrastructure/Search/StorefrontProductSearchIndexer.php`, projection, console
  command, admin write hooks; `Meilisearch\Client` wired in services.yaml; `nyholm/psr7` added (Meilisearch never worked at
  runtime before). Projection suite green; live index rebuilt (13 docs, settings + maxTotalHits verified).
- Task 4 (catalog listing/search): done — `StorefrontProductFilters`, `StorefrontProductSearchQuery`, `StorefrontProductPage`,
  Meilisearch-first `StorefrontProductRepository` with SQL fallback, controller envelope. Unit/Projection/Integration green
  (223 tests); Functional 120 + catalog browse 9 green.
- Task 5 (reviews cursor): done — `ListProductReviews` (limit/cursor/rating), `ListProductReviewsHandler` (2 statements:
  aggregate + keyset), `ProductReviewsPage`, controller `{slug,count,averageRating,items,meta}` + 400 Invalid cursor.
  Functional 7/7, Integration budget 2/2 green.
- Task 6 (customer orders cursor): done — `ListOrdersForCustomer` (limit/cursor/status/dateFrom/dateTo/q), `CustomerOrderFinder`
  keyset on `placed_at DESC, id DESC` with the lines read by ONE `IN` statement (N+1 removed), `TimestampCursor`,
  `ListQueryParams::dateOrNull`, `StorefrontOrderStatus::valuesFor`. Functional 8/8, Integration budget 2/2 green.
- Task 7 (quote requests cursor): done — `ListQuoteRequestsForUser` (limit/cursor/status/dateFrom/dateTo/q),
  `QuoteRequestFinder` single-keyed keyset statement scoped to `user_id`, controller envelope + 400 Invalid cursor.
  Functional 8/8 green.
- Task 8 (wishlist + addresses offset): done — `ListWishlistItems` (page/size 24/100, q, sort whitelist
  recent|name|price_asc|price_desc), `ListCustomerAddresses` (page/size 20/100, q), both finders filtered `COUNT` +
  `LIMIT/OFFSET` and owner-scoped, controllers answer the unified offset envelope.
  Functional 8/8 (47 assertions), full Functional suite 160/160 green.
- Task 9 (admin customers offset): done — `ListAdminCustomers` now carries a `PageRequest` (size capped 50) plus
  `q`/`role`/`status`/`sort` (name|createdAt|orderCount), `AdminCustomerFinder` applies the same predicates to the
  filtered `COUNT` and the `LIMIT/OFFSET` rows while keeping the SQL-side requester guard, `AdminCustomersPageView`
  removed in favour of the unified `Page` envelope (`AdminCustomerView` is JsonSerializable).
  Spec gap: `sort=createdAt` needed an account creation column that did not exist → new reversible migration
  `migrations/Version20261022000001.php` adds `customer_profile.created_at` + `idx_customer_profile_created_at`
  (applied to dev and test). Unit 5/5, Integration 7/7, Functional 12/12 green.
- Task 10 (admin customer orders cursor): done — `ListOrdersForAdminRequestedCustomer` carries a `CursorRequest`
  (20/100) plus `status` (storefront vocabulary), `dateFrom`/`dateTo` and `q`; `AdminCustomerOrderFinder` keeps the
  requester/existence authorization, keysets on `(placed_at, id)` with `LIMIT + 1`, still reads every line of the
  page in ONE `IN` statement and returns `CursorPage` of `AdminCustomerOrderView` (now JsonSerializable so the items
  keep their pre-pagination shape); `AdminCustomerOrderController::list` answers `{items, meta}` and maps
  `InvalidCursorException` to `400 Invalid cursor` (a non-admin is still refused first, 403).
  Unit 8/8 (33 assertions), Integration 4/4 (35), Functional 13/13 (59); full Unit 177/177, Integration 46/46,
  Functional 165/165 green.
- Task 11 (admin categories, brands, promo codes offset): done — `ListCategories` carries a `PageRequest` (50/200) plus
  `q` (slug/label), `hidden`, `parent` and `sort` (sortOrder|label|productCount); `CategoryFinder` applies the same
  predicates and the SQL-side visibility guard to the filtered `COUNT` and the `LIMIT/OFFSET` rows and still selects the
  `product_count` sub-select; `CategoryView` is JsonSerializable so the items keep their pre-pagination shape.
  New `ListAdminBrands` + `AdminBrandFinder` (q on name, name|productCount sorts, COUNT from `storefront_brand`, product
  aggregate in SQL) and `ListAdminPromoCodes` + `AdminPromoCodeFinder` (q on code, `active`, fixed code order), both on
  `query.bus`. The three controllers answer `{items, meta}`; `AdminCategoryController::respond()` re-reads the written row
  through the same bounded query (q = slug) so writes still return the dashboard row.
  Unit 3/3, Integration 4/4 (103 assertions), Functional 23/23 (446); full Unit+Integration+Functional 397/397 green.
  Frontend `ListResponse<T>` consumers keep working (they read `items`; `meta` is additive).
- Task 13 (full verification gate): done.
  - Suites Unit+Integration+Projection+Functional+Contract+Architecture: `OK (430 tests, 2672 assertions)`.
  - phpstan `[OK] No errors`; php-cs-fixer `Found 0 of 343 files`; deptrac `Errors 0` (3051 overlapping-layer
    warnings are the pre-existing deptrac pattern, no new violation).
  - Live walk of all 16 classification rows against `http://localhost:8081` (`var/walk/walk.zsh` → `var/walk/results.tsv`):
    56/56 checks PASS, every endpoint answering its documented envelope; non-admin hits 403, missing `q` and a
    malformed cursor answer 400. Meilisearch-outage run: `200` with `facets=null` and the same `meta`; zero-hit
    search run: `200` with `items=[]` and `meta.total=0`.
  - MIG-001: `Version20261022000000` + `Version20261022000001` rolled back and re-applied in `dev` and `test`
    (indexes 14→0→14, `customer_profile.created_at` 1→0→1); both migrations only create/drop schema (MIG-002).
  - Gate addition: two deterministic tests in `tests/Integration/Catalog/StorefrontProductSqlFallbackTest.php`
    for the cold/zero-hit index guard (query-less browse falls back to SQL; `q` search with 0 hits stays empty).
  - `checklist.md` fully ticked with evidence; `tasks.md` Task 13 + 13.1–13.4 `[x]`.
  - Infection (DoD mutation gate) is NOT runnable in this container: PHPUnit 13.4.3 dropped phpdbg line
    coverage and the image ships neither pcov nor xdebug (`No code coverage driver available`).
    `var/infection.log` records the attempt; MSI ≥ 85 stays open for an environment with a coverage driver.

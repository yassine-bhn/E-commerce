# Context for the spec pipeline (not the user prompt)

Repository: `/home/yassine/Desktop/freelance/Zidaine-groupe/E-commerce`
Frontend: `/home/yassine/Desktop/freelance/Zidaine-groupe/Zidane_front_web`

The user asked to run the repo's `/spec` workflow and then fix the **HIGH-severity problems**
already identified in the preceding code audit. The audit was evidence-based: relevant tests were
run and passed, but the architectural findings are code-level facts, not test failures.

## What "the high problems" means

Only these confirmed HIGH findings are in scope. Medium/low findings are out of scope unless a high
fix cannot be made correct without touching them.

### H1 — Approval state has no write-side owner

- `CatalogSubmissionKind::table()` maps approval to `storefront_product_view` and
  `storefront_category`: `src/Catalog/Domain/Model/CatalogSubmissionKind.php:21-27`.
- `DecideCatalogSubmissionHandler` updates those tables directly, with no write model and no outbox:
  `src/Catalog/Application/CommandHandler/DecideCatalogSubmissionHandler.php:42-64`.
- `AdminProductController` also inserts approval columns directly into
  `storefront_product_view`: `src/Catalog/Infrastructure/Http/AdminProductController.php:78-93`.
- `storefront_product_view` is described as a replayable read model by
  `docs/system-design/DATABASE-ARCHITECTURE.md:8,113`, but no replay command exists.
- Consequence: approval state can be lost by a rebuild/reset, and cannot be reconstructed from an
  authoritative write model.

### H2 — Super-admin CRUD silently approves, and the audit mislabels it

- `ApprovalStatus::afterReviewerModification()` returns `APPROVED` unconditionally:
  `src/Catalog/Domain/Model/ApprovalStatus.php:72-75`.
- `AdminProductController::update()` applies it for a super admin:
  `src/Catalog/Infrastructure/Http/AdminProductController.php:149-168`.
- Category handlers do the same:
  `src/Catalog/Application/CommandHandler/ReplaceCategoryTreeHandler.php:71-87`,
  `src/Catalog/Application/CommandHandler/AddCategorySubLevelHandler.php:56-68`.
- The functional test explicitly asserts that a super-admin correction approves the record:
  `tests/Functional/AdminCatalogApprovalWorkflowTest.php:268`.
- The audit row for that transition is `CATALOG_PRODUCT_UPDATED`, not an approval action:
  `tests/Functional/AdminCatalogApprovalWorkflowTest.php:281-288`.
- `DecideCatalogSubmissionHandler` claims in its docblock that the explicit decision is the only
  place approval becomes `APPROVED` (`:19-21`), which is false.

### H3 — Stock has no Inventory-owned balance

- The only stock balance is `storefront_product_view.stock_quantity`.
- Reserve and release mutate it directly:
  `src/Inventory/Infrastructure/Persistence/DbalStockReservationAdapter.php:44-49,84-89`.
- `inventory_reservation` records order lines, but it is not a stock ledger and has no on-hand /
  reserved distinction.
- The project's own state file records the replay hazard:
  `.agents/state/F-inventory-settings.md:275`.

### H4 — Mixed dinar / millime monetary units

- Product fixtures and all product/order/cart data use whole dinars: e.g. prices 129–3450 in
  `fixtures/zidane-storefront-catalog.json`.
- Shipping seed stores `delivery_fee_minor = 15`, `free_delivery_threshold_minor = 1000`:
  `migrations/Version20261017000002.php:54`.
- Shipping API calls these "minor units":
  `src/Shared/Infrastructure/Http/AdminSettingsController.php:106-123`.
- Frontend converts shipping DT ↔ millimes (`×1000` / `÷1000`) but `formatDT` rounds whole dinars:
  `Zidane_front_web/src/components/admin/SettingsPanel.tsx:23-31`,
  `Zidane_front_web/src/data/catalog.ts:47-48`.
- `Money` is an integer with no currency scale:
  `src/Shared/Domain/Money.php:9-16`; `Currency` has no fractional-digit metadata:
  `src/Shared/Domain/Currency.php:7-12`.
- Checkout adds shipping directly to product prices:
  `src/Shared/Application/Service/StorefrontQuoteService.php:76-79`.

### H5 — Cart price is stale while checkout silently reprices

- Cart reads the stored `unit_price_minor` snapshot:
  `src/Cart/Infrastructure/ReadModel/StorefrontCartFinder.php:36-60`.
- Checkout recalculates from the current product price:
  `src/Shared/Application/Service/StorefrontQuoteService.php:41-68,91-103`.
- There is no price-change warning or reconfirmation. BRS RC3 requires one:
  `docs/BUSINESS-REQUIREMENTS-SPECIFICATION.md:172`.

### H6 — No `projection:replay` command; MIG-002 is unenforceable

- `bin/console list --raw` contains no projection/replay command.
- `.agents/rules/migrations-performance.md:17` requires
  `bin/console projection:replay <name>` for read-model data changes.
- `storefront_product_view` has no event history; only the separate minimal
  `catalog_product_view` has a projector (`src/Catalog/Infrastructure/Projection/ProjectCatalogProductView.php:27-41`).
- `docs/system-design/DATABASE-ARCHITECTURE.md:8,113` claims `storefront_product_view` is rebuilt by
  replay; that claim is currently false.

## Constraints from the repo

- Read `AGENTS.md`, `docs/PROJECT-START-SPEC.md`, `.agents/orchestration.md`.
- Preserve the DDD/CQRS/outbox architecture. No event sourcing.
- PostgreSQL 16 only; Docker Compose only; no SQLite.
- TDD is mandatory: RED before production code, then GREEN, then REFACTOR.
- Money must remain integer-based (`Money`), never float.
- Migrations must not rewrite read-model data; data rebuilds must use a replay command.
- Existing behavior that is already correct should not be regressed: super-admin HTTP guards,
  atomic stock decrement, idempotent release, order-line snapshots.
- The frontend is in `Zidane_front_web`; backend changes should keep its API contract coherent or
  update the frontend in the same work package.

## Scope boundaries

- In scope: H1–H6 above.
- Out of scope unless required by a high fix: payment-failure compensation, delete-user PII/orphan
  gaps, review verification policy, `parent_slug` legacy cleanup, slug immutability enforcement.
- The user did not ask for a full rewrite. Prefer the smallest coherent design that makes approval
  and stock authoritative on the write side, makes money units consistent, warns on price changes,
  and makes the replay rule enforceable.

## Key tension the spec must settle

H4 can be fixed two ways:

1. **Millimes end-to-end** (ECO-001 literal): migrate all product/cart/order monetary data ×1000,
   change admin/storefront APIs to 3-decimal TND, update `Money`/`Currency` with scale metadata.
2. **Whole dinars end-to-end** (current de facto data): treat TND as 0-decimal for this business,
   remove the shipping ×1000 conversions, keep existing product/order data unchanged.

The spec must pick one, state the migration/data impact, and keep old orders readable. Do not leave
this decision to workers.

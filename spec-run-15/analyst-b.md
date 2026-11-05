# Spec: Fix the HIGH-severity audit findings (H1–H6)

Version: 1

## Goal

Make approval and stock authoritative on the write side, give the read models a real replay path, correct the mislabelled super-admin approval audit, make TND whole-dinar end-to-end, and make checkout stop and reconfirm a changed price — without rewriting the catalogue or migrating existing monetary data.

## Corrected prompt

Use the same `/spec` command defined under `.agents`, then fix the HIGH-severity problems (H1–H6) found in the preceding audit.

## Requirements

The user's prompt delegates the definition of “the high problems” to the preceding audit. `context.md` records the confirmed HIGH findings H1–H6, and every requirement below cites the finding it resolves. Details that the prompt and context leave open are labelled as assumptions, not requirements.

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | `catalog_submission` exists with primary key `(kind, slug)`, `approval_status VARCHAR(20) NOT NULL DEFAULT 'PENDING_APPROVAL'`, nullable `submitted_by`, `submitted_at`, `reviewed_by`, `reviewed_at`, `rejection_reason`, and `updated_at NOT NULL`; CHECK constraints restrict `kind` to the two submission kinds and `approval_status` to the three approval values; timestamps are `timestamptz`. | constraint | context H1 | must |
| R2 | `catalog_submission` is the authority for approval state on every write path: create, submit, decide, owner-edit, reviewer-edit and authorization read it, not the approval columns of `storefront_product_view` or `storefront_category`. | functional | context H1 | must |
| R3 | One Catalog-owned approval store or port is the only code that inserts, updates or deletes `catalog_submission`, and the only code that writes approval columns to the two view tables apart from the replay projector; controllers and command handlers call that store instead of issuing approval SQL themselves. | constraint | context H1 | must |
| R4 | Each approval write updates `catalog_submission`, appends one outbox event describing the transition, and writes its `catalog_audit_log` entry in the same database transaction; if any step fails, none of the three is persisted. | functional | context H1 | must |
| R5 | After each approval write the store synchronously projects the six approval columns into the matching view row in the same transaction, so a read after the HTTP response sees the new state; the projection never inserts a missing view row. | functional | context H1 | must |
| R6 | Creating a product or category inserts a `PENDING_APPROVAL` submission owned by the creator, projects it, records `CATALOG_SUBMISSION_CREATED`, and appends the create event in the same transaction. | functional | context H1 | must |
| R7 | Submitting or resubmitting moves the submission to `PENDING_APPROVAL`, sets `submitted_by` and `submitted_at`, clears `reviewed_by`, `reviewed_at` and `rejection_reason`, projects it, records `CATALOG_SUBMISSION_SUBMITTED` or `CATALOG_SUBMISSION_RESUBMITTED`, and appends the event. | functional | context H1 | must |
| R8 | An owner edit of an `APPROVED` submission moves it to `PENDING_APPROVAL`, clears reviewer fields and the rejection reason, projects it, and appends a reopened event; it records no approval audit. | functional | context H1 | must |
| R9 | Deleting a product or category deletes its `catalog_submission` row in the same transaction as the view delete; the catalog audit trail is not modified. | functional | context H1 | must |
| R10 | `app:catalog:backfill-approval` seeds a `catalog_submission` row for every existing product or category view row that lacks one, using the view's approval metadata; it remains dry-run by default, `--apply` writes, the seed uses `ON CONFLICT DO NOTHING`, and a second run seeds nothing. | functional | context H1 | must |
| R11 | The backfill still approves only legacy rows that have no submitted or reviewed metadata and are `PENDING_APPROVAL`; it moves them to `APPROVED` through the store, publishes products, projects the state, audits `CATALOG_SUBMISSION_APPROVED`, appends the event, and never changes an already-approved row or a row carrying workflow metadata. | functional | context H1 | must |
| R12 | `projection:replay catalog-submission` restores the six approval columns in both view tables from `catalog_submission`, is idempotent, leaves `published` and `hidden` untouched, and reports submissions with no view row as orphans. | functional | context H1 and H6 | must |
| R13 | A view row without a `catalog_submission` row is reported by the backfill and replay; a decision or submit on it fails with the existing not-found or refused behaviour, and the write path never lazily creates a submission from the view. | functional | context H1 | must |
| R14 | The approval migration is schema-only, reversible, and contains no INSERT, UPDATE, DELETE or TRUNCATE against any `*_view` table. | constraint | context H1 and MIG-002 | must |
| R15 | `CatalogSubmissionKind` maps each kind to one stable `catalog_submission.kind` value and one projection target table; existing route and API values do not change. | constraint | context H1 | must |
| R16 | A Super Admin product edit from `PENDING_APPROVAL` or `REJECTED` still ends `APPROVED`, sets `reviewed_by` and `reviewed_at`, clears `rejection_reason`, and never creates a new submission for the reviewer. | functional | context H2 | must |
| R17 | That edit records exactly one `CATALOG_SUBMISSION_APPROVED` audit entry in addition to the existing `CATALOG_PRODUCT_UPDATED`; an edit that finds the record already `APPROVED` records no approval entry. | functional | context H2 | must |
| R18 | Reviewer metadata changes only on a real transition to `APPROVED`; an already-approved edit preserves the original reviewer and timestamp. | functional | context H2 | must |
| R19 | `ReplaceCategoryTreeHandler` and `AddCategorySubLevelHandler` follow the same approval-audit rule for categories (`CATALOG_SUBMISSION_APPROVED` plus `CATALOG_CATEGORY_UPDATED`), and a category edit that does not transition records no approval entry. | functional | context H2 | must |
| R20 | The explicit decision path still accepts only `PENDING_APPROVAL`, moves to `APPROVED` or `REJECTED`, records `CATALOG_SUBMISSION_APPROVED` or `CATALOG_SUBMISSION_REJECTED`, and never publishes a product. | functional | context H2 | must |
| R21 | The false claim in `DecideCatalogSubmissionHandler`'s docblock is corrected; `AdminCatalogApprovalWorkflowTest` and `CatalogApprovalDesignTest` are updated deliberately for the new audit and the single writer, without removing coverage of the workflow. | quality | context H2 | must |
| R22 | `inventory_stock` has `product_slug` as primary key, `on_hand INT NOT NULL`, `reserved INT NOT NULL`, and `updated_at`; CHECK constraints enforce `on_hand >= 0`, `reserved >= 0` and `reserved <= on_hand`; it is the authoritative stock balance. | constraint | context H3 | must |
| R23 | Reserve sums quantities per slug, inserts `inventory_reservation` with `ON CONFLICT DO NOTHING`, and atomically increments `reserved` only when `on_hand - reserved >= quantity`; it never reads then writes `storefront_product_view.stock_quantity`. | functional | context H3 and ECO-004 | must |
| R24 | Release marks active reservations released and atomically decrements `reserved` by exactly the released quantity; a second release of the same order changes no balance. | functional | context H3 | must |
| R25 | `storefront_product_view.stock_quantity` is a derived cache equal to `on_hand - reserved` for tracked products and is refreshed in the same transaction after every reserve, release and available-stock change. | functional | context H3 | must |
| R26 | Admin product create and update write stock through a Shared Inventory port; `AdminProductController` no longer writes `stock_quantity` directly. | constraint | context H3 | must |
| R27 | No stock write may leave `on_hand < reserved` or a negative available quantity; such a request is refused with HTTP 409 and changes neither column. | functional | context H3 | must |
| R28 | A product whose view has a non-null `stock_quantity` but no `inventory_stock` row fails reservation closed; there is no fallback that treats the view as the balance. | functional | context H3 | must |
| R29 | Products with `stock_quantity IS NULL` remain untracked: no `inventory_stock` row is required, reservations do not change stock, and the stock label stays authoritative. | functional | context H3 | must |
| R30 | `app:inventory:backfill-stock` creates missing rows with `reserved = SUM(active inventory_reservation.quantity)` and `on_hand = current stock_quantity + reserved`, preserving available stock; it is dry-run by default, idempotent, and reports tracked rows it cannot initialise safely. | functional | context H3 | must |
| R31 | `projection:replay inventory-stock` rewrites `storefront_product_view.stock_quantity` as `on_hand - reserved`, leaves untracked rows untouched, is idempotent, and reports stock rows with no view row as orphans. | functional | context H3 and H6 | must |
| R32 | Deleting a product removes its `inventory_stock` row in the same transaction; order-line snapshots and released reservation history are untouched. | functional | context H3 | must |
| R33 | Reservation stays keyed by `(order_id, product_slug)`; a repeated reserve for the same order does not increment `reserved` twice. | functional | context H3 | must |
| R34 | `Currency` exposes `fractionalDigits()`: `TND` is 0 and `EUR`, `USD`, `GBP` are 2; `Money` remains an integer smallest-unit value object and never uses float. | constraint | context H4 | must |
| R35 | For TND, one stored unit is one dinar: existing product, cart, order and shipping values are neither multiplied nor divided, so `15` means 15 DT and `1000` means 1000 DT. | functional | context H4 | must |
| R36 | The admin shipping endpoint accepts and returns whole-dinar integers; its validation message and docblock call them dinars, not minor units or millimes. | functional | context H4 | must |
| R37 | `SettingsPanel` removes the `×1000` and `÷1000` shipping conversions, accepts only non-negative whole dinars in both fields, sends the parsed integer unchanged, and displays the stored integer unchanged. | functional | context H4 | must |
| R38 | Storefront and admin displays treat TND values as whole dinars; the shipping hint shows 15 DT and 1 000 DT, never 0.015 DT or 15 000 DT. | quality | context H4 | must |
| R39 | No product, cart, order or shipping data migration is added; old orders and carts stay readable with their existing integer amounts and line snapshots. | constraint | context H4 | must |
| R40 | TND comments, API messages and documentation that call the unit millimes or minor are corrected to whole dinars; `_minor` column names stay for compatibility and are documented as smallest-unit values. | quality | context H4 | must |
| R41 | ADR-0016 records the whole-dinar TND decision, the rejected millime migration, and the zero data impact. | quality | context H4 | must |
| R42 | `PlaceOrder` and the checkout body accept `confirmPriceChanges` as a boolean defaulting to false; `OrderInput` exposes the optional field. | functional | context H5 | must |
| R43 | A non-boolean `confirmPriceChanges` is rejected with HTTP 422 before any order work; absent or false means not confirmed. | functional | context H5 | must |
| R44 | Before consuming the idempotency key, reserving stock, creating the order or payment, or writing audit or outbox rows, checkout computes the current quote and compares each requested line's unit price with the customer's stored cart snapshot for `(slug, variant)`. | functional | context H5 | must |
| R45 | Without confirmation, any mismatch returns HTTP 409 with `error: price-changed`, a message, and `changes[]` entries containing `slug`, `variant`, `previousUnitPrice` and `currentUnitPrice`; no order, reservation, idempotency or stock write occurs. | functional | context H5 | must |
| R46 | With `confirmPriceChanges: true`, checkout proceeds once at the current quote and writes the current unit prices into the order-line snapshots; it does not ask again. | functional | context H5 | must |
| R47 | A 409 does not consume the `Idempotency-Key`; retrying the same key with confirmation creates exactly one order. | functional | context H5 | must |
| R48 | Checkout reads cart snapshots through a Cart-owned anti-corruption port; Ordering contains no SQL against `storefront_cart_item`. | constraint | context H5 | must |
| R49 | Snapshot matching is exact on slug and normalised variant; a requested line with no cart snapshot is priced from the current catalogue and does not trigger the price-change error. | functional | context H5 | must |
| R50 | Checkout shows an explicit price-change warning listing changed lines and old and new unit prices, keeps the cart, and requires a separate user action to resubmit with `confirmPriceChanges: true`; it never auto-retries. | functional | context H5 | must |
| R51 | The checkout keeps one idempotency key for the attempt across the 409, confirmation and network retries, and clears it only after a successful order. | functional | context H5 | must |
| R52 | `bin/console projection:replay <name>` exists, applies by default, accepts `--dry-run`, supports `catalog-product-view`, `catalog-submission` and `inventory-stock`, and on an unknown name exits non-zero while listing the supported names. | functional | context H6 | must |
| R53 | `catalog-product-view` reconstructs `catalog_product_view` from `catalog_products` and restores a corrupted or missing row without creating outbox events. | functional | context H6 | must |
| R54 | Every replay is idempotent, runs in one transaction, writes neither `outbox` nor `processed_events`, and reports rows written and orphans. | constraint | context H6 | must |
| R55 | A test scans `migrations/**` for INSERT, UPDATE, DELETE or TRUNCATE against tables ending `_view`, fails on any occurrence outside the frozen allowlist of `Version20261017000002.php` and `Version20261018000000.php`, and fails if an allowlisted file disappears. | constraint | context H6 and MIG-002 | must |
| R56 | `DATABASE-ARCHITECTURE.md` no longer claims `storefront_product_view` is fully rebuilt by replay; it documents `catalog_submission`, `inventory_stock`, the replayable columns, and whole-dinar TND. | quality | context H6 and H4 | must |
| R57 | The deployment runbook and `deploy/release.sh` run the stock backfill after migrations and before the new app starts, keep the approval backfill, and document dry-run and apply usage plus the three replay commands. | functional | context H6, H1 and H3 | must |

## Assumptions

| # | Assumption | Risk if wrong |
|---|------------|---------------|
| 1 | “The high problems” means the six confirmed HIGH findings H1–H6 in `spec-run-15/context.md`; medium and low findings stay out of scope. | low |
| 2 | The existing product, cart, order and shipping values are whole dinars; there is no hidden millime data that would justify a ×1000 migration. | med |
| 3 | The Super Admin “reviewer edit approves” behaviour is intentional and protected by an existing functional test; only its missing approval audit is a defect. | med |
| 4 | Approval and publication remain separate: `published` on products and `hidden` on categories are not changed by the new approval projection. | low |
| 5 | A product with `stock_quantity IS NULL` is intentionally untracked and may keep selling without an `inventory_stock` row. | med |
| 6 | The release can run operator backfills between migrations and serving traffic, as the current release script already does for approval. | high |
| 7 | `storefront_product_view` content remains maintained by catalogue commands; H6 requires truthful replay of its approval and stock columns, not a new event history for every product field. | med |
| 8 | The shipping seed values `15` and `1000` mean 15 DT and 1000 DT and must not be converted. | low |
| 9 | No external client depends on the old checkout response shape or on millime shipping input; the in-repo storefront is updated in the same work package. | med |
| 10 | `catalog_submission` stores approval metadata only; product and category content stays in the existing view tables. | med |
| 11 | Backfills and replays are operator-run, idempotent commands; no migration performs a data backfill. | med |
| 12 | Tests may be updated where this spec intentionally changes behaviour, especially the H2 audit list, H4 shipping units and H5 checkout flow, but coverage must not be weakened. | low |

## Open questions

- [minor] Should the cart screen also display the current price before checkout? Default if unanswered: no; the required control is the checkout 409 warning, and changing the cart read is outside H5.
- [minor] Should a promo-code change also trigger the price-change confirmation? Default if unanswered: no; H5 concerns stale unit prices, and the quote already shows the current promotion.
- [minor] Should the stock backfill run automatically from the migration? Default if unanswered: no; the migration stays schema-only and the release script runs the backfill.
- [minor] Which HTTP status should a stock write use when it would violate `on_hand >= reserved`? Default if unanswered: 409 Conflict.
- [minor] Should the `_minor` column and API names be renamed? Default if unanswered: no; keep them for compatibility and document that they mean the currency's smallest unit.

## Out of scope

- Migrating all product, cart, order and shipping amounts to millimes, or adding three-decimal TND support.
- Event sourcing, a full event history for `storefront_product_view`, or rebuilding product content from an event stream.
- Payment-failure compensation, delete-user PII and orphan gaps, review-verification policy, `parent_slug` legacy cleanup, and slug immutability enforcement.
- Renaming `_minor` columns or the `price_minor` API fields.
- Proactive cart repricing or a cart-page price-change banner; the H5 control is the checkout warning.
- Promotional-campaign changes triggering a separate confirmation.
- Admin edit concurrency control (RC4), category cycle detection (RC5), and other medium findings.
- Rebuilding the Meilisearch index as part of replay; the existing search indexer remains a separate command.
- Backfilling historical `inventory_reservation` rows or changing their retention policy.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Introduce the replay command and make `catalog_product_view` reproducible. | R52 (command), R53, R54 | context H6; existing `catalog_products` and `catalog_product_view`; `ProjectCatalogProductView` | `projection:replay <name>` with a tagged replayer registry, `catalog-product-view`, and tests | New `src/Shared/Application/Projection/**`, `src/Shared/Infrastructure/Console/ProjectionReplayCommand.php`, `src/Catalog/Infrastructure/Projection/ReplayCatalogProductView.php`, the shared upsert in `ProjectCatalogProductView.php`, `config/services.yaml`, `tests/Projection/Catalog/ReplayCatalogProductViewTest.php`, `tests/Functional/ProjectionReplayCommandTest.php` | none | Command lists and runs `catalog-product-view`; a corrupted row is restored; unknown names fail with the list; no outbox or processed-events rows are written. |
| WP2 | Make approval authoritative, replayable and correctly audited. | R1–R21, R52 (`catalog-submission`), R54 | WP1; context H1 and H2; existing approval handlers, controllers, audit port and backfill | `catalog_submission` schema and store, synchronous projector, refactored writers, backfill seed, `catalog-submission` replay, ADR-0014, tests | New approval migration; `src/Catalog/Domain/Model/CatalogSubmissionKind.php`; new approval port/store/events; `src/Catalog/Application/Service/SubmissionAccessPolicy.php`; `CreateCategoryHandler`, `SubmitCatalogSubmissionHandler`, `DecideCatalogSubmissionHandler`, `ReplaceCategoryTreeHandler`, `AddCategorySubLevelHandler`; `AdminProductController`; `AdminCategoryController::deleteProduct` (approval cleanup only); `src/Catalog/Infrastructure/Console/BackfillCatalogApprovalCommand.php`; new approval projector/replayer; `tests/Functional/AdminCatalogApprovalWorkflowTest.php`; `tests/Architecture/CatalogApprovalDesignTest.php`; `docs/adr/ADR-0014-*` | WP1 | Schema and constraints exist; all approval writes go through the store; view state matches the write model after each HTTP response; legacy backfill and second run are idempotent; replay restores approval columns; the super-admin first edit audits approval and the second does not; architecture test names only the approved writers. |
| WP3 | Make Inventory the stock authority and keep the view as a cache. | R22–R33, R52 (`inventory-stock`), R54 | WP1 and WP2; context H3; existing stock adapter, admin product writes and reservation table | `inventory_stock` schema, atomic adapters, admin stock port, stock backfill, `inventory-stock` replay, ADR-0015, tests | New stock migration; new `src/Shared/Application/Port/InventoryStockPort.php`; `src/Inventory/Infrastructure/Persistence/DbalStockReservationAdapter.php`; new Inventory stock adapter; `AdminProductController` (stock writes only, after WP2); `AdminCategoryController::deleteProduct` (stock cleanup only, after WP2); new `app:inventory:backfill-stock` command; new stock replayer; `tests/Functional/CheckoutStockReservationTest.php`, `tests/Functional/AdminProxyOrderStockTest.php`, new concurrency and projection tests; `docs/adr/ADR-0015-*` | WP1, WP2 | Two concurrent reservations cannot oversell; release is idempotent; the view cache equals `on_hand - reserved`; admin set-available preserves the entered value; backfill preserves current availability; replay restores the cache; tracked rows without a balance fail closed; untracked rows stay untracked. |
| WP4 | Make TND whole-dinar end-to-end. | R34–R41 | context H4; existing `Currency`, `Money`, shipping API, settings panel and shipping tests | Currency scale metadata, corrected shipping units and messages, frontend whole-dinar form, ADR-0016, tests | `src/Shared/Domain/Currency.php`, `src/Shared/Domain/Money.php` (documentation and tests only), `src/Shared/Infrastructure/Http/AdminSettingsController.php`, `Zidane_front_web/src/components/admin/SettingsPanel.tsx`, `Zidane_front_web/src/lib/api/admin.ts` (comment only), `tests/Unit/Shared/MoneyTest.php`, `tests/Functional/AdminShippingPolicyTest.php`, `docs/adr/ADR-0016-*` | none | `TND` reports zero fractional digits; shipping 15 and 1000 round-trip unchanged; the settings form rejects decimals and shows 15 DT, not 0.015 DT; existing cart and order totals are unchanged; no data migration; typecheck and lint pass. |
| WP5 | Stop checkout on a stale cart price and require explicit reconfirmation. | R42–R51 | context H5 and BRS RC3; existing checkout controller, `PlaceOrderHandler`, cart tables, checkout page and API types | Price-change exception and 409 contract, pre-write comparison, cart snapshot port, frontend warning and confirm flow, tests | `src/Ordering/Application/Command/PlaceOrder.php`, `src/Ordering/Application/CommandHandler/PlaceOrderHandler.php`, `src/Ordering/Infrastructure/Http/CheckoutOrderController.php`, new `PriceChangedException`, new `src/Shared/Application/Port/CartSnapshotPort.php`, new Cart adapter, `Zidane_front_web/src/lib/api/types.ts`, `Zidane_front_web/src/lib/api/storefront.ts`, `Zidane_front_web/src/routes/commande.tsx`, new functional and unit tests | none | Stale price returns 409 with the specified changes and writes nothing; the same key with confirmation creates one order at the current price; variants and absent lines behave as specified; the UI warns, keeps the cart and never auto-retries; typecheck and lint pass. |
| WP6 | Enforce MIG-002 and make the documentation and release order truthful. | R55–R57; final check of R52–R54 | WP1–WP5; context H1, H3 and H6; existing architecture docs and deployment runbook | Migration guard test, corrected architecture documentation, updated release script and runbook | `tests/Architecture/MigrationReadModelGuardTest.php`, `docs/system-design/DATABASE-ARCHITECTURE.md`, `docs/runbook-deployment.md`, `deploy/release.sh`, `.agents/rules/migrations-performance.md` (enforcement line) | WP1, WP2, WP3, WP4, WP5 | A synthetic new `_view` DML fails the guard and the two historical files pass; docs match the actual replay surface; the release runs the stock backfill after migrations and before app start; all suites and static checks pass. |

### WP1 — Replay foundation

Add a small `ProjectionReplayer` contract with a stable `name()` and a replay method, tag implementations with `app.projection_replayer`, and inject the tagged iterator into one `projection:replay` command. The command takes the projection name, applies by default, supports `--dry-run`, and prints the rows written plus any orphans. The first implementation rebuilds `catalog_product_view` from `catalog_products`; it must reuse the same upsert used by the event projector rather than duplicate SQL.

The command is a state-based rebuild, not an event replay: it must not read `outbox`, must not insert into `processed_events`, and must be safe to run twice. Replay runs inside a database transaction. A corrupted `catalog_product_view` row is the acceptance test: change its name, run replay, and see the write-model name return.

### WP2 — Approval write model and correct audit

Create the `catalog_submission` write table with the columns and checks from R1, then introduce one Catalog application port and DBAL adapter that owns every approval mutation. The store reads the current row inside the transaction, applies the legal transition, writes the approval metadata, appends the outbox event, writes the audit entry and synchronously projects the six approval columns into the matching view row. The existing `CatalogAuditPort` remains the audit mechanism; content-update audit entries stay where they are.

Move these paths onto the store:

- product and category creation;
- submit and resubmit;
- explicit approve and reject decisions;
- owner edits that reopen an approved record;
- reviewer edits that approve a pending or rejected record;
- product and category deletion.

`SubmissionAccessPolicy` becomes a read guard over `catalog_submission`, still enforcing ownership and the “owners may edit only pending or rejected” rule. The handlers no longer compute reviewer metadata or write approval columns themselves. The product controller and category handlers write content only; the store writes approval state.

Preserve the existing super-admin behaviour exactly (R16–R19). The only visible audit change is the added `CATALOG_SUBMISSION_APPROVED` entry on a real transition. Update the functional test expectations deliberately, and update `CatalogApprovalDesignTest` so its allowlist names the store and projector and still fails if a new direct approval writer appears. Add the `catalog_submission` table to the design test's schema checks.

Extend `app:catalog:backfill-approval` rather than adding a second backfill command. In dry-run it reports how many rows would be seeded and how many legacy rows would be approved. With `--apply`, it seeds missing submissions from the views, then approves only the legacy pending rows through the store, setting `published = true` for products in the same transaction. A second run seeds and approves zero. The command must not re-audit or re-publish a row that already carries workflow metadata.

Add `projection:replay catalog-submission` using the WP1 contract. It updates the approval columns in both views and reports submissions whose view row no longer exists. It never touches `published` or `hidden`. ADR-0014 records the write-model choice, the synchronous projection for read-your-writes, and the alternatives rejected (writing directly to views, event sourcing the approval history).

WP2 touches `AdminProductController` and `AdminCategoryController::deleteProduct` for approval and cleanup only. WP3 owns the later stock changes in the same files and therefore depends on WP2.

### WP3 — Inventory stock balance

Create `inventory_stock` with the checks from R22. The balance is authoritative; `storefront_product_view.stock_quantity` is only the display cache. Implement reserve and release in the existing `StockReservationPort` adapter:

- Reserve groups the requested lines by slug, inserts the `(order_id, product_slug)` reservation idempotently, and increments `reserved` with a single conditional `UPDATE` that requires `on_hand - reserved >= quantity`. On failure, the transaction rolls back and the caller gets the existing insufficient-stock response.
- Release updates the active reservation rows to `released` with `RETURNING`, then decrements `reserved` by the returned quantities. The status predicate makes a second release a no-op.
- Both paths refresh `storefront_product_view.stock_quantity` from the balance in the same transaction.

Add a Shared Inventory port for admin stock writes. Its operation sets the available quantity, not the physical on-hand: inside one statement it keeps `reserved` unchanged and sets `on_hand = requestedAvailable + reserved`, so the display cache equals what the admin entered. It refuses negative input and any state that would violate `reserved <= on_hand`. `AdminProductController` calls this port for `stockQuantity`; it no longer includes `stock_quantity` in its insert or update SQL.

Tracked products are those with a non-null view `stock_quantity`. After the migration they need an `inventory_stock` row; until the backfill runs, reservation fails closed rather than falling back to the view. Untracked products keep their current label-only behaviour.

`app:inventory:backfill-stock` initialises missing rows from the view and the active reservations: `reserved = SUM(quantity)` for reservations with status `reserved`, `on_hand = stock_quantity + reserved`. That preserves the currently available quantity exactly. It is dry-run by default, uses `ON CONFLICT DO NOTHING`, and reports any tracked row it cannot initialise. A second run writes nothing.

Add `projection:replay inventory-stock` using the WP1 contract. It writes `stock_quantity = on_hand - reserved` for every balance row, leaves view rows without a balance row untouched, and reports balance rows whose product view is gone. Product deletion removes the balance row in the same transaction as the view deletion. ADR-0015 records the balance-plus-cache design and why the view is not the authority.

WP3 depends on WP2 because both edit `AdminProductController` and the product-deletion path. WP3 runs after WP2 and changes only the stock portions of those files.

### WP4 — Whole-dinar TND

Choose whole dinars for TND. `Currency::fractionalDigits()` returns 0 for TND and 2 for the other currencies. `Money` keeps its integer `minorUnits` representation; for TND that integer is the number of dinars. No existing amount is scaled.

Update the admin shipping endpoint's docblock and validation message to say “whole dinars”, keeping the integer validation and the existing field names. Update `SettingsPanel` so both shipping fields parse a non-negative whole-dinar integer and send it unchanged; remove `dtToMillimes`, `millimesToDt`, and every `×1000` or `÷1000` used for shipping. The hint beside each field uses the parsed integer directly, so a stored 15 displays as 15 DT, not 0.015 DT.

Do not add a data migration. The seed values 15 and 1000 stay exactly as they are, and existing order and cart snapshots keep their current integers. Update comments in the touched code that still describe TND as millimes. ADR-0016 records the decision, the rejected millime migration, and the zero data impact. The existing `StorefrontApiTest` cart totals and `AdminShippingPolicyTest` round-trip are the regression anchors.

### WP5 — Checkout price reconfirmation

Add `confirmPriceChanges` to the `PlaceOrder` command with a default of false and to the checkout HTTP body. Reject a non-boolean value with 422 before dispatching. In the handler, compute the quote first, then compare each requested line's current unit price with the customer's stored cart snapshot. Use a Cart-owned adapter behind a Shared port; Ordering must not read `storefront_cart_item` directly.

The comparison key is `(slug, normalised variant)`. A line with no matching cart snapshot is priced from the current catalogue and does not produce a warning. A line whose snapshot price differs produces a change entry. If there are changes and confirmation is false, throw a domain exception carrying the changes; the controller maps it to the 409 contract. The check happens before the `processed_commands` insert, the stock reservation, the order and payment writes, and any audit or outbox write. A 409 therefore leaves the idempotency key unused and the stock untouched.

When confirmation is true, continue with the current quote; the order-line snapshots use the current unit prices. A retry of the same idempotency key after a 409 must create exactly one order.

In the storefront, catch the 409 `price-changed` payload, keep the cart, and render a warning that lists each changed line with its previous and current unit price. Do not resubmit automatically. The user must take a separate “confirm the new price” action; that action calls `createOrder` with `confirmPriceChanges: true`. Keep one idempotency key for the whole attempt, including the 409 and the confirmation, and clear it only after success. Add the field to `OrderInput` and pass it through `createOrder`.

The cart read model keeps its snapshot display; this work package fixes the checkout decision, not the cart UI. BRS RC3 is the behavioural source for the warning.

### WP6 — Replay guard, documentation and release order

Add an architecture test that scans every file under `migrations/` for DML against a table whose name ends in `_view`. The scan recognises INSERT, UPDATE, DELETE and TRUNCATE, including statements split across lines. Any hit outside the frozen allowlist fails with the offending file. The allowlist is exactly `Version20261017000002.php` (historical fixture seed) and `Version20261018000000.php` (historical category backfill); both files are frozen, and the test fails if either disappears. Include a small positive-control test that feeds a synthetic migration string through the scanner and confirms it is rejected.

Correct `DATABASE-ARCHITECTURE.md`:

- money is stored in integer smallest units; TND uses zero decimals, while EUR, USD and GBP use two;
- `catalog_submission` is the approval write model and `inventory_stock` is the stock write model;
- `catalog_product_view` is fully replayable from `catalog_products`;
- `storefront_product_view` is a denormalised read model whose content is maintained by catalogue commands; its approval columns are replayable from `catalog_submission` and its stock cache from `inventory_stock`;
- remove the claim that `storefront_product_view` is fully rebuilt by replay;
- document that `_minor` names mean the currency's smallest unit and are not always thousandths.

Update `deploy/release.sh` to run `app:inventory:backfill-stock --apply` after the approval backfill and before the search reindex and app start. Keep the approval backfill step. Update `docs/runbook-deployment.md` to describe the dry-run and `--apply` sequence, the fact that both backfills are idempotent, and the three replay commands with when to use them. Note that a rollback of the new schema is a forward-fix or backup restore, not a data migration.

## Verification

- Run the Unit, Integration, Projection, Functional, Contract and Architecture suites in Docker. Run `phpstan`, `php-cs-fixer --dry-run --diff`, `deptrac` and `bin/harness-check`.
- Run the frontend `npm run typecheck` and `npm run lint` for WP4 and WP5.
- Run migrations up and down on PostgreSQL 16, then run `app:catalog:backfill-approval` and `app:inventory:backfill-stock` twice without `--apply` and twice with `--apply`; the second apply must write nothing.
- Run each `projection:replay` name twice; the second run must be a no-op in effect and must not add outbox or processed-events rows.
- Check the H2 audit list in `AdminCatalogApprovalWorkflowTest`: the first super-admin edit adds `CATALOG_SUBMISSION_APPROVED`, the second does not, and a rejected record edited by the reviewer adds the approval entry.
- Check the H5 acceptance flow with a stale cart price: 409 first, no stock or idempotency write, then one order after explicit confirmation with the same key.

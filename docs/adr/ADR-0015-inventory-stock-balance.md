# ADR-0015 — Inventory stock balance

Status: accepted. Relies on: AGENTS.md 2/4/9, ECO-004, LAY-003/LAY-004, MIG-002, spec R14–R19/R31 (`spec-run-15/spec.md`), H3 (`spec-run-15/context.md`), ADR-0017.

## Context

Stock lived only in `storefront_product_view.stock_quantity`, a denormalized read-model column. The checkout adapter updated that column directly:

- the reserve path read the current value and wrote `stock_quantity - quantity`, so the guard against overselling was the view, not a write model;
- `inventory_reservation` recorded what an order held, but nothing authoritative said how many units existed, so the two could disagree after a repair or a partial failure;
- no Inventory-owned balance could be backfilled, replayed or inspected, and an admin save wrote the same read-model column as checkout;
- a product with a tracked quantity but no reservation ledger row silently reserved from the view.

R14 introduces `inventory_stock` as the authority and makes the view a synchronous display cache. R15–R17 require one conditional UPDATE for reserve, an idempotent `RETURNING` release, a synchronous cache refresh, and admin writes through a Shared port. R18/R19 add the operator backfill and the `inventory-stock` replay. Deleting a product removes its balance in the same transaction as the view row.

## Decision

**`inventory_stock(product_slug, on_hand, reserved)` is the Inventory-owned balance; `storefront_product_view.stock_quantity` is only its synchronous cache.**

- **Table.** `product_slug` is the primary key; `on_hand` and `reserved` are `INT NOT NULL`, `updated_at` defaults to `NOW()`. CHECK constraints keep `on_hand >= 0`, `reserved >= 0` and `reserved <= on_hand`. The migration is schema-only (MIG-002); legacy balances are initialised by `app:inventory:backfill-stock`.
- **Model.** `StockLevel` is a small readonly value object (`onHand`, `reserved`, `available()`) that refuses negative values and `reserved > on_hand`. It is used by the backfill to validate a computed row before inserting it; there is no ORM aggregate, because the balance is one row and every mutation is one atomic statement.
- **Port.** `App\Shared\Application\Port\InventoryStockPort` is the Catalog → Inventory anti-corruption port with exactly `setAvailable(string $slug, int $available)` and `remove(string $slug)`. `DbalInventoryStockAdapter` is its only implementation. `setAvailable` upserts `(on_hand, reserved) = (available + existing reserved, existing reserved)` with `ON CONFLICT`, so an admin save preserves units already held by orders, then refreshes the cache in the same transaction. A negative quantity is an `InvalidArgumentException`; a missing view row is a programming error and never creates one.
- **Reserve.** `DbalStockReservationAdapter::reserve` groups lines by slug and sums them. Per slug it inserts `(order_id, product_slug, quantity)` with `ON CONFLICT DO NOTHING`; zero inserted means a retry and reserves nothing again. It then runs one conditional UPDATE: `SET reserved = reserved + :qty WHERE product_slug = :slug AND on_hand - reserved >= :qty`. Zero affected rows are classified: an existing balance row means insufficient stock; a missing balance row with a non-null view `stock_quantity` fails closed (R15); a null view quantity is the untracked legacy product, whose provisional reservation row is deleted; a missing view row also fails closed. The caller owns the outer transaction, and the conditional UPDATE is the only concurrency guard.
- **Release.** `release` flips only `status = 'reserved'` rows to `released` with `RETURNING product_slug, quantity`, then decrements `reserved` by exactly those quantities and refreshes the cache. A second or concurrent release sees no `reserved` row and changes nothing. A reservation for an untracked product has no balance row, so its release changes no balance.
- **Cache.** After every successful reserve, release and admin change, `storefront_product_view.stock_quantity` is set to `on_hand - reserved` for that slug. Reserve/release/replay only touch rows whose `stock_quantity IS NOT NULL`, so an untracked product stays untracked; the code never reads the cache to compute a balance.
- **Admin paths.** `AdminProductController` no longer writes `stock_quantity`: create inserts the content row first, then calls `setAvailable` when `stockQuantity` is not null; update calls the same port. `AdminCategoryController::deleteProduct` calls `remove` and deletes the view row in the existing transaction, keeping the order-history guard and audit row. Null `stockQuantity` keeps its old meaning: the balance and cache are not touched.
- **Backfill.** `app:inventory:backfill-stock` is dry-run by default and writes only with `--apply`, in one transaction. For every tracked view row without a balance it computes `reserved = SUM(active reservation quantities)` and `on_hand = stock_quantity + reserved`, so availability is preserved. It skips untracked rows, reports rows whose computed values would violate the constraints or the `INT` range instead of inserting them, and `ON CONFLICT DO NOTHING` makes a second run write zero.
- **Replay.** `ReplayInventoryStock` implements the WP1 `ProjectionReplayer` contract as `inventory-stock` from `inventory_stock`. It updates the cache with `IS DISTINCT FROM`, so a second run writes zero, never inserts a view row, never touches a null `stock_quantity`, and reports `orphans` (balance row whose view row is gone) and `missingView` (tracked view row with no balance). One transaction, no `outbox` or `processed_events` write.

## Rejected alternatives

- **A movement ledger instead of a balance.** Useful for auditing, but out of scope and not required to close H3. `inventory_stock` plus `inventory_reservation` is the record the spec asks for.
- **Keeping `stock_quantity` as the authority and adding constraints in the view.** Rejected by R14: a read model cannot be the write-side owner, and a projection rebuild would lose the balance. It also leaves the read-then-write race ECO-004 forbids.
- **A Doctrine ORM entity.** Rejected because the concurrent reservation must be one conditional UPDATE and the admin/delete paths already run raw SQL in a controller transaction. An entity would add identity-map concerns without changing the atomicity.
- **Starting a transaction inside reserve/release.** Rejected because the caller (`PlaceOrderHandler`) owns the transaction boundary. A nested transaction would hide the reservation row from the caller's rollback and break saga compensation.
- **Falling back to the view when the balance row is missing.** Rejected by R15: the view would keep being the de facto authority, and a tracked product with no balance could oversell after a failed backfill. Failing closed is safe because the release runs the backfill before traffic.
- **Deriving `reserved` from the reservation ledger on every read.** Rejected because reserve/release must be one atomic statement on a row; a SUM over the ledger is a read-then-write and cannot be the concurrency guard.
- **Creating a view row during admin `setAvailable` or replay.** Rejected: the view row carries catalog content the Inventory context does not own. A missing row is a bug or an orphan to report, not something to fabricate.
- **A data-writing migration for the legacy balances.** Rejected by MIG-002: a rollback and re-run would re-apply an operator step and could resurrect a balance somebody deliberately changed.

## Accepted trade-offs (non-properties)

- **`setAvailable` makes the addressed view row tracked even when it was null before.** The brief says the cache refresh runs only where `stock_quantity IS NOT NULL`, but an admin save is the explicit declaration that a product is tracked: keeping the null would make a newly created product with `stockQuantity = 5` unsellable-by-cache while its balance existed. The operation therefore refreshes the addressed row; reserve, release and replay still never turn an untracked row into a tracked one. This is the one deliberate deviation from the literal brief wording, recorded here instead of silently diverging.
- **Reserve inserts the reservation row before the balance update.** Inside the caller's transaction this is invisible; outside one, a refused reservation leaves its row until the caller rolls back. The trading path always supplies the transaction.
- **The cache can lag only within a transaction.** It is refreshed by the same statement sequence, but a direct SQL client can still desynchronize it; `projection:replay inventory-stock` is the repair, and `missingView` reports the unsafe direction.
- **No database-enforced link to the catalog.** `product_slug` is a soft reference (like the reservation log), so deleting a catalog row without the controller ordering leaves an orphan balance; the replay reports it rather than deleting it.
- **`reserved` is not a movement history.** It is the current hold; auditability comes from `inventory_reservation` and the order trail.
- **The backfill cannot repair a negative cache.** The view already forbids a negative `stock_quantity`; an unsafe computed balance is reported, not inserted.

## Consequences and follow-ups

- **Positive:** H3 has one authoritative balance; reserve and release are single-statement and idempotent; the cache agrees with the balance after every mutation; admin saves preserve existing holds; deletion cannot leave a live balance; the backfill and replay make the cutover and repair explicit.
- **Negative:** two representations of stock now exist, so any future writer that bypasses the port or the adapter desynchronizes them; the sync-cache queries must stay aligned with `on_hand - reserved`; operators must run the backfill before the new code serves traffic (documented by WP7).
- **If** a future requirement adds a stock movement ledger, append to it in the same transaction as the balance update rather than replacing the balance; readers already depend on the current pair.
- **If** admin stock ever means `on_hand` rather than available, change the port method name or add a separate command; reinterpreting `setAvailable` would silently double-count reservations.

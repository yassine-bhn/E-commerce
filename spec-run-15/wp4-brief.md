# WP4 brief — Inventory stock authority, cache, backfill and replay

Authority: `spec-run-15/spec.md` v2, requirements R14–R19, R31 and the WP4 row.
This brief is the implementation contract. If it conflicts with the spec, the spec wins.

## Scope

Make Inventory the stock authority and keep `storefront_product_view.stock_quantity` as a
synchronous display cache. Do not touch approval columns or approval handlers — that is WP3.
Do not change money parsing, cart/checkout quote logic or frontend.

Owned paths:

- `migrations/Version20261029000002.php`
- `src/Inventory/Domain/Model/StockLevel.php`
- `src/Shared/Application/Port/InventoryStockPort.php`
- `src/Inventory/Infrastructure/Persistence/DbalInventoryStockAdapter.php`
- `src/Inventory/Infrastructure/Persistence/DbalStockReservationAdapter.php`
- `src/Inventory/Infrastructure/Console/BackfillInventoryStockCommand.php`
- `src/Inventory/Infrastructure/Projection/ReplayInventoryStock.php`
- stock portions only of `src/Catalog/Infrastructure/Http/AdminProductController.php`
- product-deletion stock cleanup only of `src/Catalog/Infrastructure/Http/AdminCategoryController.php`
- `docs/adr/ADR-0015-inventory-stock-balance.md`
- `config/services.yaml` only if the replayer tag is not automatic
- `tests/Integration/Inventory/StockReservationConcurrencyTest.php`
- `tests/Integration/Inventory/StockReservationReleaseTest.php` (rename/extend the existing
  `StockReleaseIdempotencyTest.php` if a rename is cleaner)
- `tests/Integration/Inventory/BackfillInventoryStockCommandTest.php`
- `tests/Projection/Inventory/ReplayInventoryStockTest.php`
- stock seed updates in existing functional/integration tests (listed at the end)
- `tests/Functional/AdminProductStockTest.php` (new, admin set available through the port)

Never edit: `spec-run-15/**`, `.agents/state/**`, `.agents/audit/**`, approval handlers/read
models, the WP2 files, cart/checkout quote logic, payment, frontend, or the money migration.

## Migration

`Version20261029000002` executes the approved SQL from `spec-run-15/migrations-preview.sql`:

```sql
CREATE TABLE inventory_stock (
    product_slug VARCHAR(128) NOT NULL,
    on_hand INT NOT NULL,
    reserved INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
    PRIMARY KEY (product_slug),
    CONSTRAINT chk_inventory_stock_on_hand CHECK (on_hand >= 0),
    CONSTRAINT chk_inventory_stock_reserved CHECK (reserved >= 0),
    CONSTRAINT chk_inventory_stock_available CHECK (reserved <= on_hand)
);
```

`down()` drops the table. Schema-only. Apply to dev and test with `doctrine:migrations:migrate -n`
and `--env=test`. Report the output.

## Model and port

`StockLevel` is a small value object with non-negative `onHand`, `reserved`, and
`available() = onHand - reserved`; it refuses `reserved > onHand`. Use it where it makes the
adapter/backfill clearer; do not build an ORM aggregate.

`App\Shared\Application\Port\InventoryStockPort` is the Catalog → Inventory anti-corruption
port. Exact methods (WP3 will preserve these calls):

```php
public function setAvailable(string $productSlug, int $available): void;
public function remove(string $productSlug): void;
```

`DbalInventoryStockAdapter` implements it:

- `setAvailable(slug, available)` with `available >= 0`:
  - in one transaction upsert the balance:
    `INSERT ... VALUES (slug, available, 0) ON CONFLICT (slug) DO UPDATE SET on_hand = EXCLUDED.on_hand + inventory_stock.reserved, updated_at = now()`.
    The entered quantity is **available**, so an existing reservation is preserved
    (`on_hand = available + reserved`).
  - refresh `storefront_product_view.stock_quantity = on_hand - reserved` for that slug, but only
    where `stock_quantity IS NOT NULL`. A product with no view row is a programming error; do not
    insert a view row.
  - A negative available is an `InvalidArgumentException`.
- `remove(slug)` deletes the `inventory_stock` row in one transaction. It does not touch the view
  row (the caller deletes it in the same transaction).

## Atomic reserve and release

Rewrite `DbalStockReservationAdapter` against `inventory_stock`:

- `reserve(orderId, lines)` groups lines by slug, sums quantities, rejects `< 1`.
- For each slug, first insert `(order_id, product_slug, quantity)` into `inventory_reservation`
  with `ON CONFLICT DO NOTHING`. If 0 inserted, the reservation already exists: continue
  (idempotent retry), do not reserve twice.
- Then one conditional UPDATE on the balance:
  `UPDATE inventory_stock SET reserved = reserved + :qty, updated_at = now() WHERE product_slug = :slug AND on_hand - reserved >= :qty`.
  Zero affected rows:
  - if a row exists for the slug, throw `InsufficientStockException($slug)`;
  - if no balance row exists **and** the view row has `stock_quantity IS NOT NULL`, throw
    `InsufficientStockException($slug)` (fail closed, R15);
  - if the view row exists with `stock_quantity IS NULL`, the product is untracked: delete the
    reservation row just inserted for this order/slug and continue (legacy behaviour).
  - If the view row itself is gone, throw `InsufficientStockException($slug)`.
- After a successful conditional update, refresh the cache in the same statement/transaction:
  `UPDATE storefront_product_view SET stock_quantity = (SELECT on_hand - reserved FROM inventory_stock WHERE product_slug = :slug) WHERE slug = :slug AND stock_quantity IS NOT NULL`.
- `release(orderId)` flips active reservations to `released` with `RETURNING product_slug, quantity`,
  then for each returned row decrements `reserved` by that quantity and refreshes the cache.
  Repeated/concurrent release changes nothing because the `RETURNING` only sees `status = 'reserved'`.
- Never read `stock_quantity` to compute the new balance; never write `stock_quantity` except as
  the `on_hand - reserved` cache.

The caller (`PlaceOrderHandler`) owns the outer transaction; do not start a new one inside
`reserve`/`release` that would hide partial work. The conditional UPDATE is the concurrency guard.

## Admin stock paths

`AdminProductController`:

- Remove `stock_quantity` from the INSERT/UPDATE SQL, from `parameters()` and from `types()`.
- Inject `InventoryStockPort`. After the content INSERT/UPDATE in the same controller transaction,
  call `setAvailable($slug, $product['stockQuantity'])` when `stockQuantity` is not null. A null
  value keeps today's meaning: do not touch the balance/cache.
- Keep every approval line, price line and audit line untouched; WP3 will remove the approval
  columns. Do not reorder or reformat unrelated code.
- Product creation must write the view row first, then set the balance, so the cache refresh finds
  the row.

`AdminCategoryController::deleteProduct()`:

- Inject `InventoryStockPort`. Inside the existing transaction, call `remove($slug)` and delete the
  view row together. Keep the existing order-history guard and audit row.

## Backfill

`app:inventory:backfill-stock` (`BackfillInventoryStockCommand`):

- Dry-run by default, `--apply` writes; report counts.
- For every `storefront_product_view` row with `stock_quantity IS NOT NULL` and no
  `inventory_stock` row:
  - `reserved = COALESCE(SUM(quantity) of active inventory_reservation rows for the slug, 0)`;
  - `on_hand = stock_quantity + reserved`;
  - insert with `ON CONFLICT (product_slug) DO NOTHING`.
- Preserve the currently available quantity: after the insert, `available = on_hand - reserved =
  stock_quantity`.
- Skip untracked rows (`stock_quantity IS NULL`) entirely.
- Report tracked rows it cannot initialise safely: negative `stock_quantity`, or a computed
  `on_hand` that violates the checks. Do not insert a bad row.
- Idempotent: a second `--apply` writes nothing.
- One transaction for `--apply`; dry-run only reads.

Apply it to dev and test after the migration and report exactly how many rows were created on each.

## Replay

`ReplayInventoryStock implements ProjectionReplayer`:

- `name(): 'inventory-stock'`, `source(): 'inventory_stock'`.
- One transaction, no outbox/processed_events writes.
- For every balance row, `UPDATE storefront_product_view SET stock_quantity = on_hand - reserved`
  where the view row exists and the value differs (`IS DISTINCT FROM`), so a second run writes 0.
- Never inserts a view row; never touches a view row with `stock_quantity IS NULL` (untracked).
- Reports `orphans` = balance rows whose view row is gone, and `missingView` = tracked view rows
  (`stock_quantity IS NOT NULL`) with no balance row. Keep the `written`/`orphans` keys; the WP1
  command already tolerates an optional `missingView`.

## Tests

TDD mandatory: one failing test at a time, capture the real RED output, then implement. Minimum
RED records:

1. Reserve on a tracked product with a balance row decrements `reserved`, updates the cache, and
   a second reserve of the same order is idempotent.
2. A tracked product without a balance row is refused (`InsufficientStockException`), an untracked
   product (null `stock_quantity`) behaves as before.
3. Release twice restores `reserved`/cache once.
4. 20-way concurrency: stock 5, twenty contenders using twenty independent DBAL connections and a
   server `lock_timeout` (the existing 2-way test's pattern). While the first winner holds the row
   lock the other nineteen time out; after commit, retry them. Assert exactly five reservations
   survive, the view cache is 0, `reserved = 5`, `on_hand = 5`, and no value is negative. No
   `pcntl` in this container; use DBAL connections, not forked processes.
5. Admin `setAvailable(5)` with an existing reservation of 3 yields `on_hand = 8`, `reserved = 3`,
   cache 5; `remove()` deletes the balance row.
6. Backfill preserves availability for a view row (stock 3) with an active reservation of 2:
   `on_hand = 5`, `reserved = 2`, available 3; second `--apply` writes 0; dry-run writes 0.
7. Replay restores a corrupted cache, writes 0 on the second run, dry-run writes 0, leaves
   untracked rows alone, reports a balance row whose view row is gone.

Update every existing test that seeds `storefront_product_view` with a non-null `stock_quantity`
and then reserves/releases, because R15 now fails closed without an `inventory_stock` row. At
minimum: `CheckoutStockReservationTest`, `CheckoutPaymentTest`, `CheckoutPaymentMethodTest`,
`ConfirmOfflinePaymentTest`, `CsrfOriginGuardTest`, `CustomerCancelOrderTest`,
`AutoCancelOrdersTest`, `AdminPaymentReadTest`, `AdminProxyOrderStockTest`,
`AdminProxyOrderPaymentTest`, `RefundPaymentTest`, `DuplicateSlugReservationTest`,
`AutoCancelIdempotencyTest`, `CheckoutStockIdempotencyTest`, plus the two Inventory tests. Insert
the balance row in the same seed helper immediately after the view row, with `on_hand` equal to
the current available quantity and `reserved = 0`. Do not weaken assertions; keep the expected
view quantities.

The three known unrelated failures are `AdminCategoryManagementTest` (409),
`AdminProductListTest:142` (Meilisearch) and `CatalogVisibilitySingleSourceTest` (WP3-owned).

## Verification

Run and report:

- migration on dev and test, then `app:inventory:backfill-stock --apply` on both
- `docker compose exec app vendor/bin/phpunit tests/Integration/Inventory`
- `docker compose exec app vendor/bin/phpunit tests/Projection/Inventory`
- `docker compose exec app vendor/bin/phpunit tests/Functional/AdminProductStockTest.php`
- `docker compose exec app vendor/bin/phpunit --testsuite=Integration`
- `docker compose exec app vendor/bin/phpunit --testsuite=Projection`
- `docker compose exec app vendor/bin/phpunit --testsuite=Functional`
- `docker compose exec app vendor/bin/phpstan analyse src/Inventory src/Catalog src/Shared`
- `docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff` on touched files
- `docker compose exec app php bin/console list --raw | grep projection`

Do not commit. Final report at most 16 lines: status; files; RED commands + failure lines;
migration/backfill output; test counts; each WP4 Done-when result; remaining failures; deviations.

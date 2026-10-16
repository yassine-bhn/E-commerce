---
id: ecommerce
paths: ["src/**", "tests/**"]
---
# E-commerce rules

### ECO-001 — Money is integer minor units + currency; floats forbidden
Scope: `src/**`
Rule: Monetary values MUST be `Shared\Domain\Money` (int minor units + Currency VO); the `float` type is forbidden in Domain and Application code.
Rationale: `0.1 + 0.2 !== 0.3` — floats mischarge customers.
BAD:  `public function setPrice(float $price): void` / `$total = 19.99 * 3;`
GOOD: `Money::ofMinor(1999, Currency::EUR)`; DB columns `INT` + `CHAR(3)`.
Enforced by: custom PHPStan rule `NoFloatInDomainRule` (§6) + `guard-cqrs.sh` hook grep + unit tests.

### ECO-002 — Money arithmetic only through the Money VO
Scope: `src/**`
Rule: All money math MUST use `Money::add/subtract/multiply/percentage`; manual integer math on minor units outside the VO is forbidden; rounding is half-up at defined boundaries only.
Rationale: Centralizing rounding prevents off-by-cent totals between order lines and payment.
BAD:  `$lineTotal = (int) round($unit->cents() * $qty);`
GOOD: `$lineTotal = $unitPrice->multiply($qty);`
Enforced by: unit tests on Money + code-reviewer grep on `cents()` usage outside VO.

### ECO-003 — Checkout is a process manager with compensations
Scope: `src/Ordering/Application/Saga/**`
Rule: Checkout MUST be implemented as `CheckoutSaga`: SYNC `PlaceOrder` (creates order, reserves stock via ECO-004, same TX) → ASYNC `AuthorizePayment` → ASYNC `ConfirmOrder`; on `PaymentAuthorizationFailed` dispatch `ReleaseStock`; on post-confirmation failure dispatch `VoidPayment`.
Rationale: Distributed steps without compensations leak reserved stock and captured money.
BAD:  one handler doing reserve+charge+confirm inline with try/catch spaghetti
GOOD: saga state machine reacting to `StockReserved`, `PaymentAuthorized`, `*Failed` events with explicit compensating commands; sync/async split as above.
Enforced by: architecture test (saga handles every failure event) + integration saga tests + ADR-0004.

### ECO-004 — Stock reservation is atomic; no read-then-write
Scope: `src/Inventory/**`
Rule: Reservation MUST be one conditional statement `UPDATE stock SET reserved = reserved + :qty WHERE product_id = :id AND available - reserved >= :qty` (affected-rows = success) or a Doctrine optimistic-lock retry; SELECT-then-UPDATE in handlers is forbidden.
Rationale: Two concurrent checkouts read the same availability and oversell.
BAD:  `$s = find($id); if ($s->available() >= $q) { $s->reserve($q); save($s); }` without locking
GOOD: atomic UPDATE; 0 rows → throw `InsufficientStock`; concurrency test hammers 20 parallel reservations.
Enforced by: `guard-cqrs.sh` hook heuristic + integration concurrency test (mandatory per Inventory change).

### ECO-005 — Retried commands, webhooks and subscribers are idempotent
Scope: `src/**`
Rule: Retryable commands carry an idempotency key (CMD-006); webhook handlers dedupe on provider event id; event subscribers dedupe via `processed_events` (EVT-003).
Rationale: Stripe retries webhooks; Messenger retries consumers; users double-click. Exactly-once is built, not hoped for.
BAD:  webhook handler authorizes capture on every call
GOOD: `INSERT INTO processed_webhooks(provider, event_id) ... ON CONFLICT DO NOTHING` first.
Enforced by: contract test (same webhook ×2 → single effect) + integration tests.

### ECO-006 — Order lines are purchase-time snapshots
Scope: `src/Ordering/**`
Rule: `OrderLine` MUST store product name, SKU, unit price and tax rate copied at purchase time; Ordering MUST NOT read the current Catalog for order data.
Rationale: Catalog prices change; customers must be billed and shown what they agreed to.
BAD:  order history query joining `catalog_product` for the name/price
GOOD: `order_line(unit_price_cents, tax_rate_bp, product_name, sku)` filled by the Catalog ACL at checkout.
Enforced by: Deptrac (Ordering ⇏ Catalog) + unit test (changing catalog price does not change placed order).

### ECO-007 — UX consistency rules are explicit
Scope: `src/*/Infrastructure/Http/**`
Rule: After a successful command the UI MAY read the write tables via a dedicated DBAL query (order confirmation page: `GetOrderConfirmation` on write tables); lists, dashboards and history MUST read `*_view` tables and tolerate staleness with an "updating" affordance.
Rationale: Reading the write model everywhere kills CQRS; pretending the read model is instant confuses users.
BAD:  confirmation page polls `order_history_view` and shows "order not found" for 2s
GOOD: confirmation reads write tables (strong); account history reads view (eventual, labeled).
Enforced by: ADR-0003 + architecture-reviewer.

### ECO-008 — Storefront search uses Meilisearch; SQL search is admin-only
Scope: `src/Catalog/Infrastructure/**`
Rule: Storefront catalog search MUST query the Meilisearch index fed by a `ProductPublished` projection; SQL LIKE search is allowed only in back-office filters.
Rationale: SQL LIKE gives no typo tolerance, ranking or facets; Meilisearch adds one stateless projection target and stays out of the write path.
BAD:  `WHERE name LIKE :q` on the storefront listing
GOOD: `SearchProducts` query → Meilisearch; `ProductIndexerOnProductPublished` keeps the index in sync, replayable like any projection.
Enforced by: ADR-0005 + architecture-reviewer + projection test for the indexer.
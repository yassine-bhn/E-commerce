# Backend missing-feature gap analysis

Date: 2026-10-22 · Scope: `E-commerce/` backend working tree · Version: 1
Evidence detail: `spec-run-5/sections/baseline-method.md`, `catalog-promotions.md`, `order-lifecycle.md`, `identity-platform.md`.

## 1. Answer in brief

The backend implements the catalog basics, the order state machine and the identity controls, but four structural areas are missing:

1. **Inventory has no quantity at all.** Stock is a four-value string (`in`, `order`, `pickup`, `out`) on `storefront_product_view`; `src/Inventory` is empty. Nothing reserves, decrements or releases stock (RC1, RC2, RC9).
2. **Payment does not exist as a domain.** `src/Payment` is empty, no `payment_*` table exists, and `ordering_orders.payment_method` is free text. There is no payment lifecycle, gateway, callback, idempotency, refund or ledger (RC7, RC8, RP1.1–RP1.7).
3. **Shipping has no record.** `src/Shipping` is empty and `ordering_orders` has no carrier or tracking columns; "shipped" and "refunded" are status flags with no evidence behind them.
4. **Platform administration is incomplete.** Administrators cannot be created or deactivated (`ROLE_ADMIN` is not assignable), platform settings (contact channels, fees, timeouts) have no storage or endpoint, and administrators cannot delete users or manage promotions despite the permission matrix.

**Counts (41 unique gaps, duplicates merged):** 27 must, 13 should, 1 could. By category: 24 absent, 12 partial, 5 contradictory. In addition, 13 baseline rows are fully implemented and are listed in §6.

**Highest-risk contradictions** (the system actively behaves against the BRS): RC11 deletion during in-flight orders (I-06 / O-17), the permission matrix for catalog, promotions and user deletion (C-06, C-14, I-02), the 100 % discount allowed by promo-code basis points (C-08), and anonymous order tracking through a public, unthrottled endpoint (I-21).

## 2. Baseline, categories and method

**Baseline.** `docs/BUSINESS-REQUIREMENTS-SPECIFICATION.md` v1.0 — the permission matrix, concepts, rules RG1.1–RG4.1, race conditions RC1–RC13, concurrency rules RP1.1–RP1.7, and the legal order/payment state table. `AGENTS.md` and `docs/adr/**` are a separate conformance source (§7), not a second requirements baseline.

**In scope.** `src/**`, `config/**`, `migrations/**`, registered routes, and tests only where they evidence behavior. **Out of scope.** `Zidane_front_web`, effort estimates, implementation.

**Categories.** absent = no implementation; partial = some code exists but the rule cannot be satisfied end to end; contradictory = code enforces different behavior, permissions or limits than the baseline; intentionally deferred = no implementation and repository evidence of a deliberate deferral; extra = implemented outside the BRS, listed once in §8.

**Method.** Whole-tree search across `src/`, `config/`, `migrations/`; route table read from the running stack (`docker compose exec app php bin/console debug:router`, 2026-10-22); schema taken from `migrations/*.php`; at least one path/symbol, route or table cited per finding. A feature is never declared absent from a single directory listing.

**Deferred items.** None found with explicit deferral evidence. The empty `src/Payment`, `src/Inventory` and `src/Shipping` contexts are treated as unfinished scope; see assumption A2 in §10.

## 3. Coverage — permission matrix

| Matrix row | Status | Finding |
|------------|--------|---------|
| Browse categories, products, promotions | implemented | storefront catalog routes |
| Search products/promotions | implemented | `StorefrontProductController::search()` (Meilisearch + SQL) |
| Create/edit products and categories | partial | C-14 (products super-admin only) |
| Product publish/unpublish, stock quantity, visibility | partial | C-04 (no product visibility), O-01 (no quantity) |
| Delete product/category under constraints | implemented | C-05, `ProductDeletionGuard` |
| Browse promotion rules and price comparison | partial | C-10 (static `old_price_minor`) |
| Create/adjust/terminate promotions | contradictory/absent | C-06, C-07 |
| Cart add/edit/remove | implemented | Cart context, RG4.1 (I-09) |
| Online submit and self-payment | partial | order submit yes; O-05/O-06 absent |
| View own orders and logistics | partial | order list yes; O-11 no tracking |
| Anonymous order tracking (matrix: ✖) | contradictory | I-21 |
| Obtain the shop's phone/WhatsApp | absent | I-15 |
| Proxy order entry and stock lock | partial | proxy entry yes; O-03 no lock |
| Register / login / logout | implemented | `AuthController`, `CustomerProfileUserProvider` |
| Maintain profile and address book | partial | I-13 avatar, I-14 default address |
| Ban/unban regular users | implemented | I-01, I-03 |
| Delete regular user accounts | contradictory | I-02 (super-admin only), I-06 (RC11) |
| Create/deactivate/delete administrator accounts | absent | I-10 |
| Configure global platform parameters | absent | I-16 |
| Customer chat permission (reserved) | deferred by the BRS itself | no surface expected this phase |

## 4. Coverage — business rules

**Catalog and promotions**

| Rule | Status | Finding |
|------|--------|---------|
| RG1.1 one category per product | implemented | C-01 |
| RG1.2 no category cycle | implemented | C-02 |
| RG1.3 unique sibling names | implemented | C-03 |
| RG1.4 sold products deactivated, not deleted | partial | C-04 |
| RG1.5 non-empty category cannot be deleted | implemented | C-05 |
| RG2.1 administrators and super admins manage promotions | contradictory | C-06 |
| RG2.2 promotion window and lifecycle | absent | C-07 |
| RG2.3 `0 < sale price < original price` | partial | C-08 |
| RG2.4 best applicable discount, no stacking | absent | C-09 |
| RG2.5 show original and active sale price | partial | C-10 |

**Users and checkout**

| Rule | Status | Finding |
|------|--------|---------|
| RG3.1 administrators act only on regular users | implemented | I-01 |
| RG3.2 ban requires a reason, is reversible | implemented | I-03 |
| RG3.3 banned users cannot log in, order or check out | implemented | I-04 |
| RG3.4 deletion anonymizes while preserving orders | implemented | I-05 (eligibility defect in I-06) |
| RG3.5 audit every ban/unban/delete | implemented | I-07 |
| RG3.6 at least one active super admin | partial | I-08 |
| RG4.1 logged-in non-banned users only | implemented | I-09 |

**Race conditions**

| Rule | Status | Finding |
|------|--------|---------|
| RC1 atomic anti-oversell | absent | O-02 |
| RC2 unified online/proxy arbitration | absent | O-03 |
| RC3 price-change notice before submit | partial | O-15 |
| RC4 catalog lost update | absent | C-11 |
| RC5 category cycle on move | implemented | C-12 |
| RC6 block checkout when product is off sale | partial | C-13 |
| RC7 payment idempotency | absent | O-06 |
| RC8 callback beats cancellation | absent | O-06 |
| RC9 timeout releases stock | absent | O-04, O-09 |
| RC10 ban during payment | partial | O-16 |
| RC11 delete with in-flight orders | contradictory | I-06 / O-17 |
| RC12 ban/delete mutual exclusion | absent | O-18 |
| RC13 admin deactivation revokes access | absent | I-11 |

**Order/payment concurrency and state table**

| Rule | Status | Finding |
|------|--------|---------|
| RP1.1 version on order and payment | partial | O-14 (order yes, payment absent) |
| RP1.2 submit against base version | partial | O-14 |
| RP1.3 no illegal jumps | implemented (order) | `OrderStatus::allowedTransitions()` |
| RP1.4 no silent retry on conflict | implemented (order) | HTTP 409 on `OptimisticLockException` |
| RP1.5 amount frozen after creation | implemented (order) | totals set only at creation |
| RP1.6 audit all changes | partial | O-12 |
| RP1.7 daily provider reconciliation | absent | O-20 |
| Legal transitions PENDING/PAID/FAILED/SHIPPED/CANCELLED/REFUNDED | implemented (status only) | `OrderStatus`; effects missing (O-07, O-08, O-11) |

## 5. Findings by bounded context

Status, impact and priority per finding. Duplicates merged: I-17 = C-06/C-14; O-17 = I-06. Full evidence in the section files.

### 5.1 Catalog (6)

| ID | Missing or defective capability | Baseline | Status | Impact | Priority | Evidence |
|----|--------------------------------|----------|--------|--------|----------|----------|
| C-04 | Product-level deactivation/visibility; sold products cannot be removed from sale | RG1.4 | partial | A product with orders can only be hidden by hiding its whole category | must | `storefront_product_view` has no status/visible column; only `PATCH .../categories/{slug}/visibility` |
| C-10 | Sale price derived from an active promotion | RG2.5 | partial | Crossed-out price can be fiction; active sales may not show | should | `old_price_minor` static; `AdminProductController::validate()` only checks `oldPrice > price` |
| C-11 | Optimistic versioning on catalog writes | RC4 | absent | Last save silently overwrites another administrator | should | `AdminProductController::update()` runs `UPDATE ... WHERE slug`; no version column |
| C-13 | Block checkout when the product is hidden/off sale | RC6 | partial | A hidden product stays purchasable by slug | must | `StorefrontQuoteService::quote()` only rejects `stock === 'out'`; `StorefrontProductRepository::bySlug()` has no visibility predicate |
| C-14 | Administrator manages products, brands and media | matrix | contradictory | Operators need super-admin credentials for daily catalog work | must | `#[IsGranted('ROLE_SUPER_ADMIN')]` on `AdminProductController`, `AdminBrandController`, `AdminUploadController` |
| C-15 | Admin product list | §五.3 | absent | Dashboard must reuse the public list, which lacks admin fields and filters | should | No `GET /api/v1/admin/catalog/products` in the registered routes |

### 5.2 Promotions (4)

| ID | Missing or defective capability | Baseline | Status | Impact | Priority | Evidence |
|----|--------------------------------|----------|--------|--------|----------|----------|
| C-06 | Administrators manage promotions | RG2.1 | contradictory | Every campaign needs the highest privilege | must | `AdminPromoCodeController` `#[IsGranted('ROLE_SUPER_ADMIN')]` |
| C-07 | Promotion time window and lifecycle (draft/scheduled/active/ended/terminated) | RG2.2 | absent | No scheduling or automatic expiry; manual toggling only | must | `storefront_promo_code(code, discount_basis_points, active)` — `Version20261017000002` |
| C-08 | Price validity `0 < sale price < original price` | RG2.3 | partial | A 100 % promo code is accepted; no per-product sale price exists | must | `basisPoints()` accepts `1..10000`; 10000 = 100 % |
| C-09 | Best (lowest) applicable discount, no stacking | RG2.4 | absent | Required multi-promotion arbitration not implemented | must | Single optional promo code in `StorefrontQuoteService`; no overlapping sales |

### 5.3 Cart and Ordering (8)

| ID | Missing or defective capability | Baseline | Status | Impact | Priority | Evidence |
|----|--------------------------------|----------|--------|--------|----------|----------|
| O-09 | Auto-cancel unpaid orders after the protection window | RC9 | absent | PENDING orders never close; would lock stock forever | must | No console command/schedule; no `auto_cancel_minutes` setting |
| O-10 | Customer cancels an unpaid order | state table | absent | The "customer cancels" path is impossible | must | `CheckoutOrderController` has create/list/show/track only |
| O-12 | Initial audit row for online orders | RP1.6 | partial | Online order trail starts at the first manual transition | should | `PlaceOrderHandler` appends the outbox but no audit row; proxy handler does |
| O-13 | Admin order queue | §五.3 | absent | Operators cannot find orders except through a customer | should | No `GET /api/v1/admin/orders` (route table) |
| O-14 | Payment-side versioning, base-version submit, amount freeze | RP1.1–RP1.5 | partial | Order side sound; payment half absent | must | `Order::$version`, `Order::transitionTo()`; no payment model |
| O-15 | Price-change notice between quote and submit | RC3 | partial | Customer can submit a silently higher total | should | `OrderLine::snapshot()` protects placed orders; no revalidation notice |
| O-16 | Allow an in-flight payment to finish after a ban | RC10 | partial | Block side works; the allowed completion path cannot exist without payments | should | `CustomerProfileUserProvider` blocks login; no payment path |
| O-18 | Ban/delete mutual exclusion | RC12 | absent | The loser of a race can resurrect or corrupt the account state | should | `BanCustomerHandler` and `DeleteCustomerWithAnonymizationHandler` update independently |

### 5.4 Payment (6)

| ID | Missing or defective capability | Baseline | Status | Impact | Priority | Evidence |
|----|--------------------------------|----------|--------|--------|----------|----------|
| O-05 | Payment aggregate, independent lifecycle, append-only ledger | §二.4, RP1.1–RP1.6 | absent | Payment truth, retries, refunds and audit cannot be represented | must | `src/Payment` empty; no `payment*` table; only `ordering_orders.payment_method` |
| O-06 | Gateway, callbacks, idempotency (RC7/RC8) | RC7, RC8 | absent | Double charge on retry; callback cannot be reconciled against a cancelled order | must | No payment controller, webhook route, provider port or transaction reference |
| O-07 | Offline receipt confirmation record (bank transfer/COD) | §三.1.7, §三.2.4 | partial | Only a status flag; finance has no receivable or evidence | must | `PATCH /api/v1/admin/orders/{id}/status` with reason only |
| O-08 | Refund execution and reconciliation | state table | partial | Order can be marked refunded with no proof money moved | must | `OrderStatus` transition + audit only; no refund record |
| O-19 | Payment-method allow-list and configuration | — | absent | Typos/unsupported methods enter orders | could | `CheckoutOrderController` accepts any non-empty string |
| O-20 | Daily provider reconciliation | RP1.7 | absent | Missed callbacks stay undetected | should | No scheduled command or statement import |

### 5.5 Inventory (4)

| ID | Missing or defective capability | Baseline | Status | Impact | Priority | Evidence |
|----|--------------------------------|----------|--------|--------|----------|----------|
| O-01 | Stock quantities, per variant, maintained by operators | §五.3, RC1/RC2 | absent | Stock cannot be counted, adjusted or audited | must | Stock is a four-value string (`AdminProductController::STOCKS`); `src/Inventory` empty |
| O-02 | Atomic reservation/decrement at checkout | RC1 | absent | Concurrent checkouts oversell the last unit | must | Quote rejects only `stock === 'out'`; `PlaceOrderHandler` never reserves |
| O-03 | Unified arbitration for online and proxy orders | RC2 | absent | Phone and online orders can be promised the same unit | must | No shared reservation service in either path |
| O-04 | Release on cancellation/timeout | RC9 | absent | Reserved units would never return to the pool | must | No release command or scheduler |

### 5.6 Shipping (1)

| ID | Missing or defective capability | Baseline | Status | Impact | Priority | Evidence |
|----|--------------------------------|----------|--------|--------|----------|----------|
| O-11 | Shipment record, carrier, tracking number and customer tracking | state table, §五.2 | absent | "Shipped" captures no waybill; customers cannot track | must | `src/Shipping` empty; no carrier/tracking columns; `GetStorefrontOrderHandler` returns status and lines only |

### 5.7 Identity, access control and platform configuration (11)

| ID | Missing or defective capability | Baseline | Status | Impact | Priority | Evidence |
|----|--------------------------------|----------|--------|--------|----------|----------|
| I-02 | Administrator deletes regular user accounts | matrix | contradictory | Requires super-admin credentials | must | `AdminCustomerController::delete()` `#[IsGranted('ROLE_SUPER_ADMIN')]` |
| I-06 | Block deletion while orders are in flight (RC11) | RC11 | contradictory | Account anonymized mid-fulfilment; delivery contact destroyed | must | Delete handler counts orders only, never reads `status` (also O-17); tests encode it (`tests/Unit/Identity/UserManagementRulesTest.php:97-108`) |
| I-08 | Last active super admin, including deactivation, atomically | RG3.6 | partial | Holds normally; concurrent demotions can pass; deactivation unguarded | should | `LastSuperAdminCannotBeRemoved/Demoted`; count and write are separate statements |
| I-10 | Create, deactivate and delete administrator accounts | §五.4, matrix | absent | No plain administrator can exist; staff cannot be suspended | must | `RegisterCustomerHandler` hardcodes `ROLE_USER`; `AssignableCustomerRole::NAMES = [ROLE_USER, ROLE_SUPER_ADMIN]`; ban refuses admins |
| I-11 | Deactivation revokes live credentials and in-flight writes | RC13 | absent | Departing administrators cannot be cut off | must | No deactivation transition exists |
| I-12 | Authenticated password change | §二.2 | absent | Compromised password requires the email reset flow | must | Only `forgot-password` and `reset-password` routes; no `ChangePassword` command |
| I-13 | Avatar maintenance | §二.2 | absent | Account cannot be personalised as specified | should | `customer_profile` has no avatar column; no customer upload route |
| I-14 | Default delivery address | §二.2 | partial | Checkout cannot preselect an address | should | `customer_address` has no `is_default`; POST upserts, DELETE removes |
| I-15 | Official phone/WhatsApp exposed to visitors | matrix, §二.1 | absent | No server-provided contact channel | must | `StorefrontReferenceRepository` returns fees, categories, brands, governorates only |
| I-16 | Global platform parameters (contact, fees, payment methods, timeout) | matrix, §五.4 | absent | Changes require SQL; RC9 blocked | must | No settings table; `storefront_shipping_policy` is seeded and read-only |
| I-18 | Global audit review | §五.4 (implied) | absent | Incident investigation needs database access | should | Audit readable only per customer or per order |
| I-21 | Anonymous order tracking | matrix (anonymous ✖); SEC-002/QRY-004 | contradictory | Anyone can enumerate `ZG-` + six-digit numbers and read totals, payment method, promo code and line items | must | `security.yaml` line 42 makes `^/api/v1/storefront/orders/track` public; `CheckoutOrderController::track()` dispatches `GetStorefrontOrder($orderNumber, null)`; `GetStorefrontOrderHandler` adds the customer predicate only when the id is non-null; only `create()` is rate-limited. Covered by `tests/Functional/StorefrontApiTest.php:255`, so it is intentional but still contradicts the matrix |

### 5.8 Cart (0 gaps)

The Cart context satisfies its baseline rule: cart and checkout require `ROLE_USER` (RG4.1, I-09), and add/update/remove plus quote are implemented (`storefront_cart`, `storefront_cart_item`, `StorefrontQuoteService`). Its only dependency on missing work is stock reservation at checkout (O-02/O-03) and the price-change notice (O-15), both counted under Inventory and Ordering.

## 6. Baseline items already implemented

Do not treat the backend as empty: 13 assessed rows are complete.

- **Catalog:** RG1.1 one category (C-01), RG1.2 no cycles (C-02), RG1.3 sibling uniqueness (C-03), RG1.5 non-empty category guard (C-05), RC5 move cycle guard (C-12).
- **Ordering:** legal state machine with `expectedVersion`, HTTP 409 on conflict, HTTP 422 on illegal transition, immutable totals, idempotent `PlaceOrder` via `processed_commands`, outbox in the same transaction, order snapshots per line.
- **Identity:** RG3.1 admin isolation (I-01), RG3.2 reasoned and reversible ban (I-03), RG3.3 banned restrictions (I-04), RG3.4 anonymization mechanics (I-05), RG3.5 audit writes (I-07), RG4.1 cart access (I-09), admin customer list (I-19), profile maintenance (I-20).
- **Platform:** search (Meilisearch with SQL fallback), list filters/pagination, transactional outbox and relay, health endpoints.

## 7. Architecture conformance (`AGENTS.md`, ADRs)

Reported separately from BRS gaps.

| Claim | Source | Conforms | Evidence |
|-------|--------|----------|----------|
| 8 bounded contexts, including Payment, Inventory, Shipping | AGENTS.md §1, ADR-0001 | no | Directories exist but are empty; no aggregates, commands or events |
| Checkout saga: sync stock reservation → async payment → compensations ReleaseStock/VoidPayment | AGENTS.md §9, ADR-0004 | no | `CheckoutSaga` exists but only dispatches `PlaceOrder`; no reservation, payment step or compensation exists |
| StockReserved/StockReservationFailed, PaymentAuthorized/PaymentAuthorizationFailed events | ADR-0001 | no | Events are not defined; contexts have no code |
| Append-only `payment_ledger` | AGENTS.md §8, ADR-0001 | no | No ledger table, migration or writer |
| Outbox in the same transaction, relay publishes to `event.bus` | AGENTS.md §5 | yes | `outbox` table, `DoctrineOutbox`, `OutboxRelay`, `OutboxRelayCommand` |
| Meilisearch projection for storefront search | AGENTS.md §7, ADR-0005 | yes | `StorefrontProductRepository` Meilisearch-first with SQL fallback |
| Raw SQL in `AdminProduct/Brand/Category/PromoCodeController` | ADR-0007 | accepted exception | ADR-0007 declares these controllers frozen legacy |
| Deptrac and PHPStan enforce layering | AGENTS.md §10, ADR-0007 | not verified | `deptrac.yaml` and `phpstan.neon` exist; no build was run in this analysis |

## 8. Extras (outside the BRS, not gaps)

Wishlist, product reviews, newsletter subscription, quote requests, contact messages, FAQ entries, banner management, Google OAuth, token-based password reset, admin customer search/filters, rate limiting, health/readiness endpoints. Password reset is an extra delivery mechanism and does **not** replace the missing authenticated password change (I-12).

## 9. Suggested implementation sequence

Ordered by dependency and business risk; no effort estimates.

1. **Inventory truth** — quantity, atomic reservation, release (O-01 → O-04). Nothing else can be correct while stock is a label.
2. **Platform settings and order timeout** — settings storage/endpoint, contact channels, fee and timeout configuration (I-16, I-15), then auto-cancel (O-09) and customer cancel (O-10). This closes the PENDING lifecycle and unblocks RC9.
3. **Payment** — aggregate, ledger, gateway, callbacks with idempotency, offline receipt record, refunds (O-05 → O-08, O-19), then reconciliation (O-20). Depends on 1 and 2.
4. **Shipping** — shipment record, carrier, tracking, customer tracking (O-11). Depends on 3.
5. **Promotions** — windows and lifecycle, per-product sale price with the 100 % guard, best-discount arbitration (C-07 → C-09), and derive displayed prices from the active sale (C-10). Can start after 2.
6. **Catalog correctness and permissions** — product visibility, checkout block, optimistic versioning, admin list, role fixes (C-04, C-13, C-11, C-15, C-14, C-06). Mostly independent; C-13 depends on product visibility.
7. **Identity** — admin lifecycle and live revocation (I-10, I-11, I-02), RC11/RC12 delete guards (I-06, I-18/O-18), atomic last-super-admin guard (I-08), password change (I-12), default address (I-14), avatar (I-13).
8. **Operations** — initial audit row, admin order queue, global audit feed (O-12, O-13, I-18), after the lifecycle exists.

## 10. Unverified areas, assumptions and limits

**Assumptions**
- A1 (low): the BRS in `docs/` is the authoritative baseline.
- A2 (med): the empty Payment, Inventory and Shipping contexts are unfinished scope, not deliberate deferrals. If they are deliberate, O-01–O-11 change from gaps to roadmap.
- A3 (low): the deliverable is this report; implementing the gaps is separate work.
- A4 (low): the working tree represents current backend behavior.
- A5 (med): a partial implementation counts as a gap when the rule cannot be satisfied end to end.
- A6 (low): `AGENTS.md` and ADRs are internal expectations, checked separately.

**Not verified**
- No end-to-end payment, concurrency or load test was executed; runtime claims are limited to route registration and source behavior.
- The route table was read on 2026-10-22 from the dev container; production may differ.
- Seed and fixture data were not audited beyond the shipping policy and catalog fixtures.
- `Zidane_front_web` and its expectations were not assessed.
- Deptrac, PHPStan, mutation and test suites were not run.
- The analysis reflects the working tree, not a released tag.

## 11. Finding index

C-04, C-06–C-11, C-13–C-15 · O-01–O-20 · I-02, I-06, I-08, I-10–I-16, I-18, I-21. Merged duplicates: I-17 = C-06/C-14; O-17 = I-06.

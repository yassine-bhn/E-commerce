# WP3 — Ordering, payment, inventory and shipping findings

## Findings
| ID | Baseline item | Status | Evidence | Impact | Priority |
|----|---------------|--------|----------|--------|----------|
| O-01 | Inventory: stock quantities and maintenance (§五.3; RC1/RC2) | absent | `src/Inventory/**` contains empty directories only. Stock is a four-value string (`in`, `order`, `pickup`, `out`) on `storefront_product_view`, validated by `AdminProductController::STOCKS`; no quantity column, no per-variant stock, no stock table. | Stock cannot be counted, adjusted or audited; "last item" scenarios cannot be detected. | must |
| O-02 | Atomic stock reservation/decrement at checkout (RC1) | absent | `StorefrontQuoteService::quote()` only rejects `stock === 'out'`; `PlaceOrderHandler` and `CreateAdminProxyOrderHandler` never reserve or decrement anything. | Concurrent checkouts oversell; nothing prevents the same last unit from being sold twice. | must |
| O-03 | Unified stock arbitration for online and proxy orders (RC2) | absent | Both checkout paths call the same quote service but neither locks stock; no shared reservation service exists. | Phone/WhatsApp orders and online orders can be promised the same unit. | must |
| O-04 | Stock release on cancellation/timeout (RC9) | absent | No inventory context, no release command, no scheduler. `grep` for cancel/expire/release in `src/` returns only password-reset and outbox code. | Reserved units would never return to the sellable pool; current code avoids the symptom only because it never reserves. | must |
| O-05 | Payment aggregate, independent payment lifecycle and append-only ledger (BRS §二.4; RP1.1–RP1.6) | absent | `src/Payment/**` is empty; no `payment*` table exists in `migrations/`; `ordering_orders` stores only a free-text `payment_method`. | Payment truth, retries, refunds and audit cannot be represented independently from the order. | must |
| O-06 | Payment gateway, callbacks and idempotency (RC7, RC8) | absent | No payment controller, webhook route, provider port or transaction-reference table; registered routes contain no payment endpoint. | A single order can be charged twice on retry, and a successful callback cannot be reconciled against a cancelled order. | must |
| O-07 | Offline receipt confirmation for bank transfer/COD (§三.1.7, §三.2.4) | partial | Admin can `PATCH /api/v1/admin/orders/{id}/status` with a reason (`AdminOrderController::transitionStatus`), but no payment record, amount, payer reference or collector is stored. | The only "confirmation" is a status flag; finance has no receivable record and no evidence trail. | must |
| O-08 | Refund execution (legal state table, PAID/SHIPPED → REFUNDED) | partial | `OrderStatus` allows the transition and `TransitionOrderStatusHandler` audits it; no refund amount, provider call, ledger entry or reconciliation exists. | An order can be marked refunded without any proof that money moved. | must |
| O-09 | Auto-cancel unpaid orders after the protection window (RC9) | absent | No console command or Messenger schedule; `OrderStatus::PENDING` can remain forever; no `auto_cancel_minutes` setting exists. | Unpaid orders are never closed and, once stock exists, would lock inventory indefinitely. | must |
| O-10 | Customer-initiated cancellation of an unpaid order (state table) | absent | `CheckoutOrderController` exposes create/list/show/track only; no cancel route or command exists. | Customers must contact an operator to abandon an order; the state table's "customer cancels" path is impossible. | must |
| O-11 | Shipment record, carrier and tracking number; customer tracking (state table; §五.2) | absent | `src/Shipping/**` is empty; `ordering_orders` has no carrier/tracking columns; `GetStorefrontOrderHandler` returns status and lines but no shipment data. | "Shipped" cannot capture the waybill, and customers cannot track a parcel. | must |
| O-12 | Order status audit trail (RP1.6) | partial | `TransitionOrderStatusHandler` and `CreateAdminProxyOrderHandler` insert into `ordering_order_audit_trail`; `PlaceOrderHandler` records the order but no initial audit row. | Online orders have no "created" audit entry, so the trail starts at the first manual transition. | should |
| O-13 | Admin order queue (implied by §五.3 fulfillment duties) | absent | Registered admin routes: `POST /admin/orders/proxy`, `PATCH /admin/orders/{id}/status`, `GET /admin/orders/{id}/audit`, `GET /admin/customers/{id}/orders`. No `GET /api/v1/admin/orders`. | Operators can only find orders through a customer; there is no global queue to pick, ship or reconcile. | should |
| O-14 | Payment/order versioning and legal transitions (RP1.1–RP1.5) | partial | Order side implemented: `Order::$version`, `Order::transitionTo()` with `expectedVersion`, 409 on `OptimisticLockException`, 422 on illegal transitions, immutable totals after creation. Payment side absent with O-05. | Order concurrency is sound; the payment half of the rule set has no implementation. | must |
| O-15 | Price snapshot at order time (RC3) | partial | `PlaceOrderHandler` quotes at placement and stores `OrderLine::snapshot()` prices; no "price changed since quote" notice exists between cart quote and submit. | Placed orders are protected, but a customer can submit a silently higher total without an explicit re-confirmation. | should |
| O-16 | Banned user during payment (RC10) | partial | Banned users cannot log in (`CustomerProfileUserProvider` returns null) or use cart/checkout (`ROLE_USER` rules). No payment completion path exists to honour "allow the in-flight payment to finish". | The block side works; the "payment already in flight" side cannot be implemented before payments exist. | should |
| O-17 | User deletion blocked while orders are in flight (RC11) | contradictory | `DeleteCustomerWithAnonymizationHandler` anonymizes any customer who has **any** order, regardless of status; it only counts orders, it never checks PENDING/PAID/SHIPPED. | Accounts with undelivered orders can be anonymized, destroying the delivery contact the warehouse still needs. | must |
| O-18 | Ban/delete mutual exclusion (RC12) | absent | `BanCustomerHandler` and `DeleteCustomerWithAnonymizationHandler` update `customer_profile` independently; no version/state predicate arbitrates concurrent calls. | Ban and delete can race; the loser's write can resurrect or corrupt the intended state. | should |
| O-19 | Payment method validation and configuration | absent | `CheckoutOrderController` accepts any non-empty `paymentMethod` string; no allowed list or platform configuration. | Typos and unsupported methods enter orders and cannot be reconciled against a channel. | could |
| O-20 | Daily provider reconciliation (RP1.7) | absent | No scheduled command, provider statement import or compensation flow. | Missed callbacks ("paid at the provider, pending in the order") stay undetected. | should |

## Rule coverage — order state table
| Transition | Implemented | Evidence |
|------------|-------------|----------|
| PENDING → PAID / CANCELLED / FAILED | yes | `OrderStatus::allowedTransitions()` |
| PAID → SHIPPED / REFUNDED | yes (status only) | same; no shipment/refund record |
| FAILED → PENDING / CANCELLED | yes | same |
| SHIPPED → REFUNDED | yes (status only) | same |
| CANCELLED, REFUNDED terminal | yes | same |
| Illegal transitions blocked | yes | `IllegalOrderStateTransitionException`, HTTP 422 |

## Rule coverage — race conditions and concurrency
| Rule | Status | Note |
|------|--------|------|
| RC1 atomic anti-oversell | absent | no stock |
| RC2 unified arbitration | absent | no stock |
| RC3 price snapshot | partial | snapshot yes, change notice no |
| RC4 catalog lost update | absent | see C-11 |
| RC5 category cycle | implemented | see C-12 |
| RC6 off-sale product at checkout | partial | see C-13 |
| RC7 payment idempotency | absent | no payment |
| RC8 callback beats cancellation | absent | no payment |
| RC9 timeout release | absent | no timeout job |
| RC10 ban during payment | partial | block side only |
| RC11 delete with in-flight orders | contradictory | O-17 |
| RC12 ban/delete race | absent | O-18 |
| RC13 admin deactivation revokes access | absent | no admin lifecycle (see I-03) |
| RP1.1 version on order and payment | partial | order yes, payment absent |
| RP1.2 submit against base version | partial | order yes, payment absent |
| RP1.3 no illegal jumps | implemented (order) | `OrderStatus` |
| RP1.4 no silent retry on conflict | implemented (order) | 409, no auto-retry |
| RP1.5 amount frozen after creation | implemented (order) | totals set at creation only |
| RP1.6 audit all changes | partial | O-12 |
| RP1.7 daily reconciliation | absent | O-20 |

# ADR-0001 — Bounded context split
Status: accepted. Relies on: LAY-004, ECO-006.

## Decision
8 contexts, none merged: Catalog, Cart, Ordering, Payment, Inventory, Shipping, Identity, Promotions.
- Cart stays separate from Ordering: carts are anonymous, expirable, high-churn, eventually
  consistent; orders are immutable financial records. Different consistency boundaries.
- Promotions stays separate: pricing rules change daily and are owned by marketing; a rule
  engine must not destabilize Ordering. Prices enter Ordering as snapshots (ECO-006).
- Back-office is NOT a context: it is a UI over read models of all contexts.

## Events crossing context boundaries
| Event | From → To |
|---|---|
| CartCheckedOut | Cart → Ordering |
| OrderPlaced | Ordering → Payment, Inventory, Promotions |
| StockReserved / StockReservationFailed | Inventory → Ordering (saga) |
| PaymentAuthorized / PaymentAuthorizationFailed | Payment → Ordering (saga) |
| OrderConfirmed | Ordering → Shipping, Cart (close cart) |
| ShipmentDispatched | Shipping → Ordering, Identity (notification) |
| CustomerRegistered / CustomerAnonymized | Identity → all read models holding PII |
| CouponRedeemed | Promotions → Ordering |

## Rejected alternatives
- Cart merged into Ordering (rejected: couples anonymous traffic to financial invariants).
- One shared "Shop" context (rejected: god context, Deptrac unenforceable).
- Event sourcing for Payment (rejected: audit need is met by append-only payment_ledger).

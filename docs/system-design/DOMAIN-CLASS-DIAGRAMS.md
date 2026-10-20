# Domain class diagrams

Notation: Mermaid `classDiagram`. Relations use UML stereotypes where helpful.

**Relation types used**

| Symbol / keyword | Meaning |
|------------------|---------|
| `<\|--` | Generalization (inheritance) |
| `*--` | Composition (child lifecycle bound to parent) |
| `o--` | Aggregation (shared reference, weaker ownership) |
| `-->` | Association / dependency (uses, references by ID) |
| `..>` | Dependency (e.g. handler → aggregate) |

**Cross-context rule:** associations between aggregates in **different** contexts are **by identifier only** (`ProductId`, `CustomerId`, `OrderId`)—never object references. Integration is via events or ACL ports ([CROSS-CONTEXT-INTEGRATION.md](./CROSS-CONTEXT-INTEGRATION.md)).

---

## Shared kernel

```mermaid
classDiagram
  class AggregateRoot {
    <<abstract>>
    #record(event)
    +releaseEvents() list~DomainEvent~
  }

  class Money {
    <<value object>>
    -minorUnits int
    -currency Currency
    +ofMinor() Money
    +add(Money) Money
  }

  class Currency {
    <<enumeration>>
    TND
  }

  class OutboxPort {
    <<interface>>
    +append(events) void
  }

  Money --> Currency : uses
  AggregateRoot ..> DomainEvent : records
```

| From | To | Type | Multiplicity | Notes |
|------|-----|------|--------------|-------|
| `Money` | `Currency` | association | 1 → 1 | ISO 4217 code |
| `AggregateRoot` | domain events | dependency | 1 → 0..* | Released to outbox in infra |
| Command handlers | `OutboxPort` | dependency | — | Same transaction as persist |

---

## Catalog

**BRD:** category tree (RG1.x), single category per product (RG1.1), product reviews on storefront.

```mermaid
classDiagram
  class Category {
    <<aggregate root>>
    -id CategoryId
    -parentId CategoryId?
    -name LocalizedName
    -version int
    +moveUnder(CategoryId) void
    +rename(string) void
  }

  class Product {
    <<aggregate root>>
    -id ProductId
    -categoryId CategoryId
    -name string
    -price Money
    -status ProductStatus
    +changePrice(Money) void
    +assignCategory(CategoryId) void
  }

  class ProductReview {
    <<entity>>
    -id ReviewId
    -productId ProductId
    -customerId CustomerId
    -rating int
    -comment string
  }

  class ProductId {
    <<value object>>
    +toString() string
  }

  class CategoryId {
    <<value object>>
  }

  class ProductRegistered {
    <<domain event>>
  }

  class ProductPriceChanged {
    <<domain event>>
  }

  AggregateRoot <|-- Product
  AggregateRoot <|-- Category
  Product *-- ProductId : id
  Category *-- CategoryId : id
  Category --> CategoryId : parent 0..1
  Product --> CategoryId : belongs to 1
  Product ..> ProductRegistered : emits
  Product ..> ProductPriceChanged : emits
  ProductReview --> ProductId : about 1
  ProductReview --> CustomerId : author 1
```

| From | To | Type | Multiplicity | Rule / note |
|------|-----|------|--------------|-------------|
| `Category` | `Category` (parent) | association | 0..1 parent, 0..* children | Tree, no cycles (RG1.2) |
| `Product` | `Category` | association | * → 1 | Single category (RG1.1) |
| `Product` | `ProductReview` | composition | 1 → 0..* | Reviews stored per product slug in DB today |
| `Product` | `AggregateRoot` | inheritance | — | **Implemented** for `Product` only |
| `Category` | — | — | — | **Target** aggregate; hierarchy validation exists in application service |

**Read side (not domain, for orientation):** `StorefrontProductView`, `catalog_product_view` — denormalized projection; **Implemented**.

---

## Cart

**BRD RG4.1:** cart for logged-in customers only; high churn, separate from orders (ADR-0001).

```mermaid
classDiagram
  class Cart {
    <<aggregate root>>
    -id CartId
    -customerId CustomerId
    -lines CartLine[]
    -expiresAt DateTimeImmutable
    +addLine(productRef, qty) void
    +updateQuantity(lineId, qty) void
    +removeLine(lineId) void
    +checkout() CartCheckedOut
  }

  class CartLine {
    <<entity>>
    -id CartLineId
    -productSlug string
    -productId ProductId
    -variantLabel string?
    -quantity int
    -unitPriceSnapshot Money
  }

  class CartCheckedOut {
    <<domain event>>
    +cartId
    +customerId
    +lines snapshot
  }

  AggregateRoot <|-- Cart
  Cart *-- CartLine : lines 1..*
  CartLine --> ProductId : references
  Cart ..> CartCheckedOut : emits
```

| From | To | Type | Multiplicity | Note |
|------|-----|------|--------------|------|
| `Cart` | `CartLine` | composition | 1 → 0..* | Lines die with cart |
| `Cart` | `CustomerId` | association | * → 1 | Identity context ID only |
| `CartLine` | `Product` | dependency | — | ID + price snapshot at add time |
| `Cart` | `Ordering` | event | 1 checkout → 1 order | `CartCheckedOut` |

**Status:** **Target** aggregate; **Implemented** read path: `QuoteCart`, `CartQuote`, storefront quote via ports.

---

## Ordering

**BRD:** online orders + phone/WhatsApp proxy orders; immutable line snapshots (ECO-006); optimistic version (RP1.x).

```mermaid
classDiagram
  class Order {
    <<aggregate root>>
    -id OrderId
    -customerId CustomerId
    -orderNumber string
    -status OrderStatus
    -source OrderSource
    -version int
    -totals OrderTotals
    -delivery DeliveryInfo
    -paymentMethod string
    -promoCode string?
    -placedAt DateTimeImmutable
    +transitionTo(OrderStatus) void
    +attachLines(OrderLine[]) void
  }

  class OrderLine {
    <<entity immutable>>
    -id OrderLineId
    -productSlug string
    -productName string
    -variantLabel string?
    -unitPriceMinor int
    -currency Currency
    -quantity int
  }

  class OrderTotals {
    <<value object>>
    -subtotal Money
    -discount Money
    -delivery Money
    -total Money
  }

  class OrderStatus {
    <<enumeration>>
    PENDING
    PAID
    FAILED
    CANCELLED
    SHIPPED
    REFUNDED
  }

  class OrderSource {
    <<enumeration>>
    ONLINE
    PHONE_OR_WHATSAPP
    ADMIN_PROXY
  }

  class OrderPlaced {
    <<domain event>>
  }

  class OrderConfirmed {
    <<domain event>>
  }

  class CheckoutSaga {
    <<process manager>>
    +onCartCheckedOut()
    +onStockReserved()
    +onPaymentAuthorized()
    +compensate()
  }

  AggregateRoot <|-- Order
  Order *-- OrderLine : lines 1..*
  Order *-- OrderTotals : totals 1
  Order --> OrderStatus : status 1
  Order --> OrderSource : source 1
  Order ..> OrderPlaced : emits
  Order ..> OrderConfirmed : emits
  CheckoutSaga ..> Order : commands
  CheckoutSaga ..> PlaceOrder : dispatches
```

| From | To | Type | Multiplicity | Note |
|------|-----|------|--------------|------|
| `Order` | `OrderLine` | composition | 1 → 1..* | Snapshot at place time (ECO-006) |
| `Order` | `CustomerId` | association | * → 1 | May be anonymized later (RG3.4) |
| `Order` | `Product` | — | — | **No link**; only line snapshots |
| `OrderStatus` | state machine | — | — | **Implemented** enum + transitions |
| `Order` | ORM/table | — | — | **Partial** `ordering_orders`, `ordering_order_lines` |
| `CheckoutSaga` | buses | dependency | — | **Target** (ADR-0004) |

**Application commands (relation to domain):**

```mermaid
classDiagram
  class PlaceOrder {
    <<command>>
  }
  class CreateAdminProxyOrder {
    <<command>>
  }
  class TransitionOrderStatus {
    <<command>>
  }
  class PlaceOrderHandler {
    <<handler>>
  }

  PlaceOrderHandler ..> Order : creates/loads
  PlaceOrderHandler ..> PlaceOrder : handles
  CreateAdminProxyOrder ..> Order : phone/WhatsApp flow
  TransitionOrderStatus ..> Order : admin transitions
```

---

## Inventory

**BRD RC1–RC2, ECO-004:** atomic stock decrement; reservations for pending orders.

```mermaid
classDiagram
  class StockItem {
    <<aggregate root>>
    -productId ProductId
    -onHand int
    -reserved int
    +reserve(qty) StockReservation
    +release(reservationId) void
    +commit(reservationId) void
  }

  class StockReservation {
    <<entity>>
    -id ReservationId
    -orderId OrderId
    -quantity int
    -expiresAt DateTimeImmutable
    -status ReservationStatus
  }

  class StockReserved {
    <<domain event>>
  }

  class StockReservationFailed {
    <<domain event>>
  }

  AggregateRoot <|-- StockItem
  StockItem *-- StockReservation : reservations 0..*
  StockReservation --> OrderId : for order 1
  StockItem --> ProductId : per SKU 1
  StockItem ..> StockReserved : emits
  StockItem ..> StockReservationFailed : emits
```

| From | To | Type | Multiplicity | Note |
|------|-----|------|--------------|------|
| `StockItem` | `ProductId` | association | 1 → 1 | Cross-context ID |
| `StockReservation` | `OrderId` | association | * → 1 | Tied to checkout saga |
| `StockItem` | `StockReservation` | composition | 1 → 0..* | Released on timeout (RC9) |

**Status:** **Target**; storefront shows stock label on `storefront_product_view` (**Implemented** read-only).

---

## Payment

**BRD RP1.x, SEC-001:** payment decoupled from order; append-only ledger; provider tokens only.

```mermaid
classDiagram
  class Payment {
    <<aggregate root>>
    -id PaymentId
    -orderId OrderId
    -amount Money
    -status PaymentStatus
    -version int
    -idempotencyKey string
    -providerToken string
    +authorize() void
    +capture() void
    +void() void
  }

  class PaymentLedgerEntry {
    <<entity append-only>>
    -id EntryId
    -paymentId PaymentId
    -type LedgerEntryType
    -amount Money
    -occurredAt DateTimeImmutable
    -payload json
  }

  class PaymentAuthorized {
    <<domain event>>
  }

  class PaymentAuthorizationFailed {
    <<domain event>>
  }

  AggregateRoot <|-- Payment
  Payment *-- PaymentLedgerEntry : ledger 1..*
  Payment --> OrderId : pays for 1
  Payment ..> PaymentAuthorized : emits
  Payment ..> PaymentAuthorizationFailed : emits
```

| From | To | Type | Multiplicity | Note |
|------|-----|------|--------------|------|
| `Payment` | `Order` | association | * → 1 | By `OrderId` only |
| `Payment` | `PaymentLedgerEntry` | composition | 1 → 1..* | Audit trail, no update/delete |
| `Payment` | card PAN/CVV | — | — | **Forbidden** (SEC-001) |

**Status:** **Target**; human approval required for `src/Payment/**` changes.

---

## Shipping

```mermaid
classDiagram
  class Shipment {
    <<aggregate root>>
    -id ShipmentId
    -orderId OrderId
    -carrier string
    -trackingNumber string?
    -status ShipmentStatus
    -address SnapshotAddress
    +dispatch(tracking) void
  }

  class SnapshotAddress {
    <<value object>>
    recipientName
    phone
    governorate
    street
  }

  class ShipmentDispatched {
    <<domain event>>
  }

  AggregateRoot <|-- Shipment
  Shipment *-- SnapshotAddress : deliver to 1
  Shipment --> OrderId : fulfills 1
  Shipment ..> ShipmentDispatched : emits
```

| From | To | Type | Multiplicity | Note |
|------|-----|------|--------------|------|
| `Shipment` | `Order` | association | 0..1 → 1 | Created after `OrderConfirmed` |
| `SnapshotAddress` | `CustomerAddress` | — | — | Copied at ship time, not live reference |

**Status:** **Target**; reference data `storefront_shipping_policy` exists (**Implemented**).

---

## Identity

**BRD RG3.x:** customers, admins, super-admin, addresses, wishlist, audit.

```mermaid
classDiagram
  class Customer {
    <<aggregate root>>
    -id CustomerId
    -email Email
    -passwordHash string
    -displayName string
    -role CustomerRole
    -ban BanRecord?
    -version int
    +register()
    +ban(reason)
    +unban()
    +anonymize()
  }

  class AdminUser {
    <<aggregate root>>
    -id AdminId
    -email Email
    -role AdminRole
    +deactivate()
  }

  class CustomerAddress {
    <<entity>>
    -id AddressId
    -label string
    -isDefault bool
    -governorateId GovernorateId
    -street string
  }

  class WishlistItem {
    <<entity>>
    -productId ProductId
    -addedAt DateTimeImmutable
  }

  class BanRecord {
    <<value object>>
    reason
    bannedAt
    bannedBy AdminId
  }

  class CustomerRegistered {
    <<domain event>>
  }

  class CustomerAnonymized {
    <<domain event>>
  }

  class IdentityAuditLog {
    <<entity>>
    action
    actorId
    targetId
    occurredAt
  }

  AggregateRoot <|-- Customer
  AggregateRoot <|-- AdminUser
  Customer *-- CustomerAddress : addresses 0..*
  Customer o-- WishlistItem : wishlist 0..*
  Customer *-- BanRecord : optional 0..1
  Customer ..> CustomerRegistered : emits
  Customer ..> CustomerAnonymized : emits
  IdentityAuditLog ..> Customer : records changes
  IdentityAuditLog ..> AdminUser : records changes
```

| From | To | Type | Multiplicity | Note |
|------|-----|------|--------------|------|
| `Customer` | `CustomerAddress` | composition | 1 → 0..* | **Implemented** tables `customer_profile`, `customer_address` |
| `Customer` | `WishlistItem` | aggregation | 1 → 0..* | **Implemented** `storefront_wishlist_item` |
| `AdminUser` | `Customer` | — | — | Admin actions via commands, not inheritance |
| `Customer` | `Order` | — | — | Linked by `CustomerId` in Ordering |

**Infrastructure security (separate from domain):** `CustomerUser` implements Symfony `UserInterface` — adapter, not core domain model.

---

## Promotions

**BRD RG2.x:** time windows, best discount wins, promo codes.

```mermaid
classDiagram
  class Promotion {
    <<aggregate root>>
    -id PromotionId
    -name string
    -window EffectiveWindow
    -discount DiscountRule
    -scope PromotionScope
    -status PromotionLifecycle
    +terminate() void
  }

  class DiscountRule {
    <<value object>>
    <<enumeration type>>
    PERCENT
    FIXED_AMOUNT
  }

  class PromotionScope {
    <<value object>>
    productIds ProductId[]
    categoryIds CategoryId[]
  }

  class PromoCode {
    <<entity>>
    -code string
    -promotionId PromotionId
    -maxRedemptions int?
  }

  class CouponRedeemed {
    <<domain event>>
  }

  AggregateRoot <|-- Promotion
  Promotion *-- DiscountRule : rule 1
  Promotion *-- PromotionScope : applies to 1
  Promotion o-- PromoCode : codes 0..*
  Promotion ..> CouponRedeemed : emits
```

| From | To | Type | Multiplicity | Note |
|------|-----|------|--------------|------|
| `Promotion` | `Product` / `Category` | association | — | By ID lists only |
| `PromoCode` | `Order` | event | — | `CouponRedeemed` on successful checkout |
| Storefront pricing | `Promotion` | ACL | — | **Implemented** `ValidatePromoCode`, `storefront_promo_code` read model |

---

## Application ports (anti-corruption at quote time)

Used by Cart/Ordering to read Catalog + Promotions without importing their aggregates:

```mermaid
classDiagram
  class StorefrontCatalogPort {
    <<interface>>
    +getProductSnapshot(slug) StorefrontProductSnapshot
  }

  class StorefrontPricingPort {
    <<interface>>
    +resolvePrice(productId) Money
  }

  class StorefrontCategoryPort {
    <<interface>>
  }

  class StorefrontProductSnapshot {
    <<DTO>>
    slug
    name
    priceMinor
  }

  StorefrontCatalogPort ..> StorefrontProductSnapshot : returns
  QuoteCartHandler ..> StorefrontCatalogPort
  QuoteCartHandler ..> StorefrontPricingPort
  DbalStorefrontCatalogPort ..|> StorefrontCatalogPort
  DbalStorefrontPricingPort ..|> StorefrontPricingPort
```

| From | To | Type | Note |
|------|-----|------|------|
| `Cart` application | `StorefrontCatalogPort` | dependency | ACL in Shared/Application |
| Infra adapters | Catalog read tables | dependency | DBAL, not domain import |

---

## Global relation summary (aggregates only)

```mermaid
classDiagram
  direction LR
  Category "1" <-- "1" Product : classified in
  Customer "1" <-- "0..*" Cart : owns
  Customer "1" <-- "0..*" Order : places
  Cart "1" --> "0..1" Order : checkout creates
  Order "1" <-- "0..*" Payment : paid by
  Order "1" <-- "0..1" Shipment : fulfilled by
  Product "1" <-- "1" StockItem : stocked as
  Promotion "1" ..> Order : price via snapshot
```

All links above are **logical** (IDs + events), not PHP object graphs across `src/<Context>` namespaces.

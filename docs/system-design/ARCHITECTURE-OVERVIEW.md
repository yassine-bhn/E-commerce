# Architecture overview

## Context map

Eight bounded contexts plus **Shared**. Cross-context integration uses **domain events** (async via outbox) or **application ports** (anti-corruption at checkout quote time)—never direct aggregate-to-aggregate calls across context folders (Deptrac).

```mermaid
flowchart TB
  subgraph storefront["Storefront / Admin UI"]
    UI[HTTP controllers]
  end

  subgraph contexts["Bounded contexts"]
    ID[Identity]
    CAT[Catalog]
    CART[Cart]
    ORD[Ordering]
    INV[Inventory]
    PAY[Payment]
    SHP[Shipping]
    PRO[Promotions]
  end

  SHR[Shared kernel]

  UI --> ID & CAT & CART & ORD & PRO

  CART -->|"CartCheckedOut (event)"| ORD
  ORD -->|"OrderPlaced (event)"| PAY & INV & PRO
  INV -->|"StockReserved / Failed (event)"| ORD
  PAY -->|"PaymentAuthorized / Failed (event)"| ORD
  ORD -->|"OrderConfirmed (event)"| SHP & CART
  SHP -->|"ShipmentDispatched (event)"| ORD & ID
  ID -->|"CustomerRegistered / Anonymized (event)"| CAT & ORD
  PRO -->|"CouponRedeemed (event)"| ORD

  CAT & ORD & ID & PAY --> SHR
```

## CQRS layering (per context)

Each context follows the same dependency direction:

```mermaid
flowchart BT
  INF[Infrastructure\nPersistence, ReadModel, Projection, Http, MessageHandler]
  APP[Application\nCommand, CommandHandler, Query, Saga]
  DOM[Domain\nModel, Event, Repository, Service]

  INF --> APP
  APP --> DOM
```

| Layer | Responsibility | Typical types |
|-------|----------------|---------------|
| **Domain** | Invariants, aggregates, domain events, repository **interfaces** | `Product`, `Order`, `OrderStatus` |
| **Application** | Use cases: commands/queries (DTOs), handlers, sagas | `PlaceOrder`, `PlaceOrderHandler`, `CheckoutSaga` |
| **Infrastructure** | ORM entities mapping, DBAL read models, HTTP, outbox relay | `Product` (ORM), `*Finder`, controllers |

**Write path:** HTTP → `command.bus` → command handler (transaction boundary) → aggregate → outbox (same TX) → relay → `event.bus`.

**Read path:** HTTP → `query.bus` → query handler (DBAL only, QRY-001) → DTO / `*_view` row.

## Checkout saga (process manager)

Entry: `Ordering\Application\Saga\CheckoutSaga` (**Target**; ADR-0004).

```mermaid
sequenceDiagram
  participant C as Cart
  participant O as Ordering
  participant I as Inventory
  participant P as Payment

  C->>O: CartCheckedOut → PlaceOrder (sync)
  O->>I: ReserveStock (sync)
  alt reservation OK
    I-->>O: StockReserved
    O->>P: AuthorizePayment (async)
    P-->>O: PaymentAuthorized
    O->>O: Confirm order
    O-->>C: OrderConfirmed (close cart)
  else reservation failed
    I-->>O: StockReservationFailed
    O->>O: Fail / cancel checkout
  else payment failed
    P-->>O: PaymentAuthorizationFailed
    O->>I: ReleaseStock (compensation)
    O->>P: VoidPayment (compensation)
  end
```

## Shared kernel (cross-cutting domain)

| Class | Role | Status |
|-------|------|--------|
| `AggregateRoot` | Records domain events on aggregates | **Implemented** |
| `Money`, `Currency` | ECO-001 money type | **Implemented** |
| `OutboxPort` | Append events in same transaction as write | **Implemented** (port + infra) |

See [DOMAIN-CLASS-DIAGRAMS.md](./DOMAIN-CLASS-DIAGRAMS.md#shared-kernel).

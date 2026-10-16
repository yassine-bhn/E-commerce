---
id: events
paths: ["src/*/Domain/Event/**", "src/*/Infrastructure/Projection/**", "src/*/Infrastructure/MessageHandler/**"]
---
# Event rules

### EVT-001 — Domain events are past-tense, immutable, minimal
Scope: `src/*/Domain/Event/**`
Rule: Events MUST be named past tense (`OrderPlaced`), be `final readonly`, and carry only IDs, scalars and arrays — no entities, no services, plus `eventId` and `occurredOn`.
Rationale: Events cross context and process boundaries (async); fat payloads break replay and leak the write model.
BAD:  `class OrderPlaced { public Order $order; }` (entity + lazy proxies on the bus)
GOOD: `final readonly class OrderPlaced { public function __construct(public string $eventId, public OrderId $orderId, public CustomerId $customerId, public \DateTimeImmutable $occurredOn) {} }`
Enforced by: architecture test (reflection: no object props except VOs/IDs) + code-reviewer.

### EVT-002 — All domain events go through the outbox
Scope: `src/**`
Rule: Command handlers and sagas MUST NOT inject `EventBusInterface`; aggregates record events, the handler appends them to the `outbox` table in the same transaction, and a relay worker publishes them to `event.bus`.
Rationale: Commit-then-dispatch loses events on crash; dispatch-then-commit publishes phantom events. The outbox makes it atomic.
BAD:  `$this->eventBus->dispatch(new OrderPlaced(...));` inside `PlaceOrderHandler`
GOOD: `$this->outbox->append($order->releaseEvents());` inside the handler's transaction; `messenger:consume outbox_relay` publishes.
Enforced by: custom PHPStan rule `NoEventBusInCommandHandlerRule` (§6) + integration test (event row exists in outbox after handler, none on rollback).

### EVT-003 — Every event subscriber is idempotent
Scope: `src/*/Infrastructure/Projection/**`, `src/*/Infrastructure/MessageHandler/**`
Rule: A subscriber's first statement MUST claim the event: `INSERT INTO processed_events(consumer, event_id) VALUES (?, ?) ON CONFLICT DO NOTHING`; on duplicate it returns without side effects.
Rationale: The outbox relay delivers at-least-once; non-idempotent consumers double-project and double-email.
BAD:  projection updates `order_history_view` directly; relay retry duplicates lines
GOOD: claim-first, then `INSERT ... ON CONFLICT (order_id, line_sku) DO UPDATE` projection writes.
Enforced by: integration/projection tests dispatching the same event twice + test data asserting single effect.
---
id: aggregates
paths: ["src/*/Domain/**"]
---
# Aggregate rules

### AGG-001 — Invariants are enforced inside the aggregate
Scope: `src/*/Domain/Model/**`
Rule: State changes MUST go through intention-revealing methods that guard invariants; public setters and public writable properties are forbidden.
Rationale: Invariants scattered across handlers are bypassed by the next handler; inside the aggregate they cannot be skipped.
BAD:  `$order->status = 'confirmed';` from the handler
GOOD: `$order->confirm();` which throws `OrderNotConfirmable` unless payment is authorized.
Enforced by: unit tests per invariant + code-reviewer (grep for public setters in Domain).

### AGG-002 — One aggregate per transaction; reference others by ID
Scope: `src/*/Domain/Model/**`, `src/*/Application/CommandHandler/**`
Rule: A handler MUST load and save exactly one aggregate; other aggregates are referenced by ID and coordinated via events or the saga.
Rationale: Multi-aggregate transactions create lock contention and hide consistency boundaries (ECO-003 uses the saga instead).
BAD:  handler loads `Order` AND `StockItem`, mutates both, saves both
GOOD: handler mutates `Order`; stock moves via `StockReserved` / `ReleaseStock` saga steps.
Enforced by: architecture test (one `*Repository->save` per handler) + architecture-reviewer.

### AGG-003 — Aggregates record events, never dispatch them
Scope: `src/*/Domain/Model/**`
Rule: Aggregates MUST use `recordThat(event)` and expose `releaseEvents(): array`; injecting any bus or dispatcher into Domain is forbidden.
Rationale: Dispatch timing is a transaction concern (EVT-002); the domain must stay framework-free (LAY-001).
BAD:  `Order::__construct(private EventDispatcherInterface $d)` then `$this->d->dispatch(...)`
GOOD: `private array $events = []; $this->recordThat(new OrderPlaced(...));`
Enforced by: Deptrac (Domain layer) + unit test asserting recorded events.
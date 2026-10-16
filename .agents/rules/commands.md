---
id: commands
paths: ["src/*/Application/Command/**", "src/*/Application/CommandHandler/**", "src/*/Application/Saga/**"]
---
# Command rules

### CMD-001 — Commands are task-based, never CRUD
Scope: `src/*/Application/Command/**`
Rule: Name every command `<Verb><Noun>` expressing one business task; forbid `Create/Update/Delete<entity>` with nullable field bags.
Rationale: Task-based commands carry business intent (Azure CQRS guidance); CRUD commands hide invariants in controllers.
BAD:  `final class UpdateOrder { public ?string $status = null; public ?array $lines = null; }`
GOOD: `final class ConfirmOrder { public function __construct(public readonly OrderId $orderId) {} }`
Enforced by: NAM-001 architecture test (`tests/Architecture/NamingTest.php`) + code-reviewer.

### CMD-002 — Commands are immutable
Scope: `src/*/Application/Command/**`
Rule: Commands MUST be `final` with `readonly` properties set only via constructor; no setters, no public mutable state.
Rationale: Commands travel through middleware and retries; mutation makes retries non-deterministic (ECO-005).
BAD:  `class PlaceOrder { public array $lines; public function setLines(array $l): void {...} }`
GOOD: `final class PlaceOrder { public function __construct(public readonly CustomerId $customerId, public readonly array $lines, public readonly string $idempotencyKey) {} }`
Enforced by: phpstan-strict-rules + `guard-cqrs.sh` hook (greps `function set` in Command dirs).

### CMD-003 — Commands and handlers return void
Scope: `src/*/Application/**`
Rule: A command MUST have no getter for results and its handler MUST declare `: void`; callers obtain outcomes via the read model or a synchronous write-side confirmation query (ECO-007).
Rationale: Returning the aggregate couples callers to the write model and breaks the CQRS separation.
BAD:  `public function __invoke(PlaceOrder $c): Order { ... return $order; }`
GOOD: `public function __invoke(PlaceOrder $c): void { ...; $this->orders->save($order); }`
Enforced by: custom PHPStan rule `HandlerReturnVoidRule` (`.agents/phpstan/`, §6) + `guard-cqrs.sh` hook.

### CMD-004 — Exactly one handler per command
Scope: `src/**`
Rule: Each command class MUST be handled by exactly one `#[AsMessageHandler]` on `command.bus`; zero or two handlers is a build failure.
Rationale: Ambiguous handling makes transactions and idempotency unverifiable.
BAD:  two classes handling `PlaceOrder`, one "for logging"
GOOD: one `PlaceOrderHandler`; logging lives in command.bus middleware.
Enforced by: architecture test counting handlers per command + `php bin/console debug:messenger` in CI.

### CMD-005 — The handler is the transaction boundary
Scope: `src/*/Application/CommandHandler/**`
Rule: One command = one database transaction opened by `doctrine_transaction` middleware; the handler flushes once at the end and MUST NOT flush inside loops, subscribers, or the domain.
Rationale: Partial commits break aggregates and leave the outbox inconsistent with state.
BAD:  `$this->em->flush()` inside a `foreach ($order->lines() ...)` and again in an event subscriber
GOOD: handler: load → `$order->place()` → `$this->orders->save($order)` (single flush) → outbox written in same TX.
Enforced by: integration test asserting outbox row exists atomically with order + code-reviewer.

### CMD-006 — Retriable commands carry an idempotency key
Scope: `src/*/Application/Command/**`
Rule: Any command triggered by a retryable source (HTTP submit, webhook, consumer retry) MUST implement `RetryableCommand { public function idempotencyKey(): string; }`; its handler MUST record the key in `processed_commands` and no-op on replay.
Rationale: Network retries must never double-place an order or double-charge a card (ECO-005).
BAD:  `PlaceOrder` with no key; double-click → two orders
GOOD: handler first runs `INSERT INTO processed_commands(key) VALUES (:k) ON CONFLICT DO NOTHING`; 0 rows affected → return.
Enforced by: integration test replaying the same key + security/integration reviewers.
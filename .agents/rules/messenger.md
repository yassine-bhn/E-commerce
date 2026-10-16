---
id: messenger
paths: ["config/packages/messenger.yaml", "src/**"]
---
# Messenger bus rules (config delivered in §6)

### BUS-001 — Three buses with distinct middleware
Scope: `config/packages/messenger.yaml`
Rule: The application MUST define `command.bus`, `query.bus`, `event.bus`; command.bus = [validation, doctrine_transaction, outbox_append], query.bus = [validation only], event.bus = [retry]; no cross-use.
Rationale: Mixing concerns in one default bus makes transaction and retry behavior untestable per message type.
BAD:  single `messenger.bus.default` handling commands, queries and events
GOOD: three buses; handlers declare their bus via `#[AsMessageHandler(bus: 'command.bus')]`.
Enforced by: integration test booting the container and asserting middleware per bus + architecture-reviewer.

### BUS-002 — Command/query buses are synchronous; events async via the relay only
Scope: `config/packages/messenger.yaml`, `src/**`
Rule: Commands and queries MUST NOT be routed to an async transport; only the outbox relay sends to `event.bus` transport. Code MUST NOT use `HandleTrait` results from command.bus.
Rationale: Async commands lose the transaction and the RED/GREEN feedback loop; read results belong to query.bus.
BAD:  `'App\Ordering\Application\Command\PlaceOrder' => async` in messenger.yaml
GOOD: commands unrouted (sync); `App\*\Domain\Event\*` routed via outbox relay only.
Enforced by: config review + integration test (dispatching a command executes inline).
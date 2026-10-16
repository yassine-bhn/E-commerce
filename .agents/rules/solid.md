---
id: solid
paths: ["src/**", "tests/**"]
---
# SOLID rules (3+ per principle; detection method on each)

## SRP
### SRP-001 — One handler per message; handlers only orchestrate
Rule: A handler MUST only: load via port → call domain → save via port → return void. Mapping, HTTP, formatting, second use cases are forbidden.
BAD:  `PlaceOrderHandler` also renders the confirmation email template
GOOD: email is a subscriber on `OrderConfirmed` (PRF-003).
Enforced by: architecture test (one `#[AsMessageHandler]` per class per message) + code-reviewer.

### SRP-002 — Controllers only translate HTTP ↔ bus
Rule: A controller MUST only: deserialize/validate input → dispatch command or query → map result to HTTP. No repositories, no entities, no business branches.
BAD:  controller calls `OrderRepository->find()` then `if ($order->isPaid()) ...`
GOOD: `$view = $this->queryBus->ask(new GetOrderConfirmation($id, $userId));`
Enforced by: Deptrac (Http layer deps) + code-reviewer.

### SRP-003 — One subscriber, one side effect
Rule: An event subscriber class MUST perform exactly one side effect (one projection, one email, one index update).
BAD:  `OrderPlacedSubscriber` updates the view AND sends email AND indexes search
GOOD: three subscribers: `ProjectOrderHistoryOnOrderPlaced`, `SendEmailOnOrderPlaced`, `IndexProductOnProductPublished`.
Enforced by: code-reviewer checklist + subscriber naming test.

## OCP
### OCP-001 — New behavior = new handler/strategy, not edits to existing ones
Rule: A new use case MUST be a new command+handler; a new pricing/shipping rule MUST be a new strategy class; editing an existing handler's branches for a new case is forbidden.
BAD:  adding `if ($type === 'gift')` inside `CalculateShipping`
GOOD: new `GiftWrappingShippingStrategy` tagged into the calculator.
Enforced by: code-reviewer on diffs + solid-guardian.

### OCP-002 — Selection via tagged iterators, never switch/match on type
Rule: Application/Domain code MUST NOT `switch`/`match` on class names or type strings to pick behavior; use `#[TaggedIterator]` strategy collections.
BAD:  `match ($gateway) { 'stripe' => $stripe->charge(), 'adyen' => $adyen->charge() };`
GOOD: `foreach ($this->gateways as $g) { if ($g->supports($method)) return $g->authorize(...); }`
Enforced by: `guard-cqrs.sh` hook grep heuristic + solid-guardian + architecture-reviewer.

### OCP-003 — Adding a handler requires zero config edits
Rule: Messenger handlers MUST rely on autoconfiguration (`#[AsMessageHandler]`); `services.yaml` MUST NOT register individual handlers.
BAD:  `PlaceOrderHandler: tags: [messenger.message_handler]` in services.yaml
GOOD: attribute only; container compiles with no edits for the next handler.
Enforced by: config review + integration test (container compiles, handler routed).

## LSP
### LSP-001 — Every repository implementation passes the port contract test
Rule: Each Infrastructure implementation of a Domain port MUST run the shared abstract contract test (save/find/not-found semantics).
BAD:  `DoctrineOrderRepository` throws `EntityNotFoundException` while the port promises `OrderNotFound`
GOOD: `tests/Integration/Ordering/DoctrineOrderRepositoryTest extends OrderRepositoryContractTest`.
Enforced by: contract-test inheritance check in harness/architecture test.

### LSP-002 — Implementations never narrow preconditions or weaken postconditions
Rule: Overriding methods MUST accept at least the port's types and return at most the port's types; PHPStan level 8 + strict rules must pass.
BAD:  impl requires non-empty array where port allows empty
GOOD: signature-compatible, contract-tested implementations.
Enforced by: `vendor/bin/phpstan analyse` (level 8, strict-rules).

### LSP-003 — All payment gateways pass one contract suite
Rule: Every `PaymentGateway` implementation (Stripe, Adyen, Fake) MUST pass `tests/Contract/PaymentGatewayContractTest` including failure and idempotency cases.
BAD:  Fake returns success shapes Stripe never returns → saga tested against fantasy
GOOD: same suite against all drivers; provider sandbox recorded fixtures.
Enforced by: contract suite in CI + e2e-contract-test-agent.

## ISP
### ISP-001 — Ports are per use case and small
Rule: A Domain/Application port MUST have ≤ 3 methods and serve one use case (e.g. `StockReserver::reserve`), not a `InventoryServiceInterface` grab-bag.
BAD:  `InventoryInterface` with reserve, release, list, import, sync, report
GOOD: `StockReserver`, `StockReleaser`, `StockAvailabilityView` as separate ports.
Enforced by: architecture test (interface method count on `*Port|*Repository`) + solid-guardian.

### ISP-002 — Read ports are separate from write ports
Rule: Interfaces returning view DTOs MUST NOT live on write repositories; `OrderRepository` (write) and `OrderHistoryFinder` (read) are different ports in different layers.
BAD:  `OrderRepository::historyFor(CustomerId): array` next to `save()`
GOOD: read finder under `Infrastructure/ReadModel` using DBAL (QRY-003).
Enforced by: Deptrac + architecture-reviewer.

### ISP-003 — No fat constructors depending on unused collaborators
Rule: A class MUST NOT depend on collaborators it never calls; > 5 constructor dependencies triggers mandatory review.
BAD:  handler with 9 constructor args, 4 unused in most paths
GOOD: split by use case (SRP-001) or introduce a small facade port.
Enforced by: PHPStan (unused constructor param detection) + solid-guardian checklist.

## DIP
### DIP-001 — Ports are owned by inner layers; Infrastructure implements them
Rule: Interfaces MUST live in `Domain` or `Application`; `Infrastructure` contains only implementations bound in `services.yaml`.
BAD:  `App\Ordering\Infrastructure\Persistence\OrderRepositoryInterface` imported by the handler
GOOD: `App\Ordering\Domain\Repository\OrderRepository` implemented by `DoctrineOrderRepository`.
Enforced by: Deptrac + architecture-reviewer.

### DIP-002 — No service location outside the composition root
Rule: `ContainerInterface`, `->get(`, `static::getContainer()` MUST NOT appear in `src/**` outside Kernel/compiler passes.
BAD:  `$this->container->get(StripeGateway::class)` inside the saga
GOOD: constructor injection of `PaymentGateway`.
Enforced by: `guard-cqrs.sh` hook grep + phpstan-symfony + solid-guardian.

### DIP-003 — Handlers depend on abstractions, not Doctrine concretes
Rule: Handler/saga constructor types MUST be ports or `final` value services; type-hinting `EntityManagerInterface`, concrete repositories or HTTP clients is forbidden in Application.
BAD:  `__construct(private EntityManagerInterface $em)` in `PlaceOrderHandler`
GOOD: `__construct(private OrderRepository $orders, private StockReserver $stock, private Outbox $outbox, private ClockInterface $clock)`.
Enforced by: PHPStan custom rule `NoInfrastructureInHandlerRule` (§6) + Deptrac.
---
id: queries
paths: ["src/*/Application/Query/**", "src/*/Infrastructure/ReadModel/**"]
---
# Query & read-model rules

### QRY-001 — Query handlers never change state
Scope: `src/*/Infrastructure/ReadModel/**`
Rule: A query handler MUST NOT call `persist`, `flush`, `executeStatement` with INSERT/UPDATE/DELETE, or dispatch any command/event.
Rationale: Queries that write bypass the write model's invariants — the core violation CQRS exists to prevent.
BAD:  `final class GetOrderHistoryHandler { public function __invoke(GetOrderHistory $q): array { $this->em->flush(); ... } }`
GOOD: `public function __invoke(GetOrderHistory $q): OrderHistoryView { return new OrderHistoryView($this->connection->fetchAllAssociative(self::SQL, ['customerId' => $q->requesterId()])); }`
Enforced by: custom PHPStan rule `NoWriteInQueryHandlerRule` (§6) + `guard-cqrs.sh` hook (greps `persist(|flush(|commandBus->dispatch` in ReadModel).

### QRY-002 — Queries return DTOs, never ORM entities
Scope: `src/*/Infrastructure/ReadModel/**`
Rule: Query handlers MUST return view DTOs (`*View`) built from arrays/scalars; returning a Doctrine entity is forbidden.
Rationale: Entities on the read side invite lazy-loading, N+1 (PRF-001) and accidental mutation.
BAD:  `return $this->em->find(Order::class, $id);` (entity escapes to the controller)
GOOD: `return new OrderConfirmationView(...$row);` from a DBAL fetch.
Enforced by: Deptrac (ReadModel layer may not use `doctrine/orm`) + PHPStan return types + review.

### QRY-003 — Read side uses DBAL or SQL only
Scope: `src/*/Infrastructure/ReadModel/**`, `src/*/Infrastructure/Projection/**`
Rule: Read-model queries and projections MUST use `Doctrine\DBAL\Connection` or native SQL; the ORM is reserved for the write side.
Rationale: Denormalized read tables don't map to aggregates; the ORM adds cost and coupling with zero benefit here.
BAD:  `OrderViewRepository extends ServiceEntityRepository` with DQL
GOOD: `final class OrderHistoryFinder { public function __construct(private Connection $db) {} }` with explicit SQL.
Enforced by: Deptrac + `guard-cqrs.sh` hook (greps `EntityManagerInterface` in ReadModel/Projection).

### QRY-004 — Queries are authorized at the handler
Scope: `src/*/Application/Query/**`, `src/*/Infrastructure/ReadModel/**`
Rule: Every query MUST carry the requester identity and the handler MUST filter by ownership unless the requester has an admin role; "fetch by id then check in controller" is forbidden.
Rationale: Authorization at the edge is forgotten; authorization in the handler is unavoidable (SEC-002).
BAD:  `final class GetOrder { public function __construct(public readonly OrderId $id) {} }` — anyone reads any order
GOOD: `final class GetOrderHistory { public function __construct(public readonly CustomerId $requesterId, public readonly bool $isAdmin) {} }` and SQL has `WHERE customer_id = :requesterId` when not admin.
Enforced by: functional tests (customer B cannot read customer A's order) + security-reviewer.
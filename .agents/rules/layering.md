---
id: layering
paths: ["src/**"]
---
# Layering rules (enforced by deptrac.yaml, §6)

### LAY-001 — Domain is dependency-free
Scope: `src/*/Domain/**`
Rule: Domain code MUST NOT import from Application, Infrastructure, Symfony HttpKernel/HttpFoundation, or Doctrine ORM.
Rationale: The domain model is the stable core; framework coupling makes unit tests slow and rules unverifiable.
BAD:  `use Symfony\Component\HttpFoundation\Request;` inside `src/Ordering/Domain/Model/Order.php`
GOOD: `Order` depends only on `OrderId`, `Money`, `OrderLine` (Domain) and `Shared\Domain` types.
Enforced by: Deptrac layers `Domain`/`Application`/`Infrastructure` + `guard-domain-imports.sh` hook.

### LAY-002 — Application depends on Domain only
Scope: `src/*/Application/**`
Rule: Application code (commands, handlers, queries, sagas) MUST NOT import Infrastructure, Doctrine ORM, or Symfony HTTP classes; Messenger interfaces are allowed.
Rationale: Handlers orchestrate ports owned by the inner layers (DIP-001); infrastructure is replaceable.
BAD:  `use Doctrine\ORM\EntityManagerInterface;` in `PlaceOrderHandler`
GOOD: constructor takes `OrderRepository` (port) + `ClockInterface`; Doctrine impl lives in Infrastructure.
Enforced by: Deptrac + `guard-domain-imports.sh` hook.

### LAY-003 — Infrastructure implements ports, inner layers never name it
Scope: `src/**`
Rule: Only Infrastructure may import vendors (ORM, DBAL, HTTP, Meilisearch, payment SDK); no Domain/Application file may reference an Infrastructure class.
Rationale: DIP-001: inner layers own the interfaces, outer layers implement them.
BAD:  `PlaceOrderHandler` type-hints `DoctrineOrderRepository`
GOOD: `PlaceOrderHandler` type-hints `OrderRepository`; binding via `services.yaml` alias.
Enforced by: Deptrac + PHPStan (constructor param types) + architecture-reviewer.

### LAY-004 — No cross-context reads; integrate via events or ACL
Scope: `src/**`
Rule: Context X MUST NOT import `Domain`/`Application` classes of context Y; integration happens via domain events or an explicit anti-corruption layer in `X/Infrastructure/Acl`.
Rationale: Direct reads re-couple the monolith into a big ball of mud and break ECO-006.
BAD:  `Ordering\Application\CommandHandler\PlaceOrderHandler` imports `Catalog\Domain\Model\Product`
GOOD: Handler calls `CatalogPricing` ACL port returning a `PriceSnapshot` DTO; order lines store the snapshot.
Enforced by: Deptrac `context` ruleset + architecture-reviewer.
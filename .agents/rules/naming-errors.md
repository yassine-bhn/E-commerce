---
id: naming-errors
paths: ["src/**", "tests/**"]
---
# Naming & error-handling rules

### NAM-001 — Names follow the CQRS table
Scope: `src/**`
Rule: Command `PlaceOrder`; handler `PlaceOrderHandler`; query `GetOrderHistory`; view `OrderHistoryView`; event `OrderPlaced`; subscriber `<Verb>On<Event>` e.g. `ProjectOrderHistoryOnOrderPlaced`; repository port `OrderRepository`; impl `DoctrineOrderRepository`.
Rationale: Predictable names let agents route tasks and let hooks scope rules by path.
BAD:  `OrderManager`, `OrderHelper`, `HandleOrderStuff`
GOOD: the table above, in the directory layout of AGENTS.md.
Enforced by: `tests/Architecture/NamingTest.php` + code-reviewer.

### ERR-001 — Format validation happens before dispatch
Scope: `src/*/Infrastructure/Http/**`, `src/*/Application/Command/**`
Rule: Syntax validation (email shape, positive qty, known currency) MUST run via Symfony Validator on the command DTO at the edge, before `command.bus` dispatch.
Rationale: The domain should never receive structurally impossible input; edge validation gives 422s with field paths.
BAD:  `if ($qty <= 0) throw new \InvalidArgumentException(...)` inside the aggregate for a missing HTTP field
GOOD: `#[Assert\Positive] public readonly int $quantity` on the command; controller returns 422 on violations.
Enforced by: functional tests (422 on invalid payload) + code-reviewer.

### ERR-002 — Invariant violations throw domain exceptions, mapped in Infrastructure
Scope: `src/*/Domain/**`, `src/*/Infrastructure/Http/**`
Rule: Business rule violations MUST throw `DomainException` subclasses (e.g. `InsufficientStock`); a single HTTP listener maps them to 4xx problem+json; Domain MUST NOT throw HTTP exceptions.
Rationale: Keeps LAY-001 intact while giving clients consistent error contracts.
BAD:  `throw new BadRequestHttpException('no stock')` inside `StockItem`
GOOD: `throw InsufficientStock::forProduct($sku)` → listener maps to 409 with `type: insufficient-stock`.
Enforced by: Deptrac (Domain) + functional test on the 409 contract.

### ERR-003 — No silent catch around bus dispatch
Scope: `src/**`
Rule: Code MUST NOT wrap `bus->dispatch()` in try/catch that swallows or logs-and-continues; only catch to map to a domain decision (saga compensation) or rethrow.
Rationale: Swallowed handler failures desynchronize write and read models silently.
BAD:  `try { $this->bus->dispatch($cmd); } catch (\Throwable) { $this->logger->warning('ignored'); }`
GOOD: `catch (PaymentAuthorizationFailed $e) { $this->bus->dispatch(new ReleaseStock(...)); }`
Enforced by: `guard-cqrs.sh` hook (grep pattern) + code-reviewer.
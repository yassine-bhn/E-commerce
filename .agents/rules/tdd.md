---
id: tdd
paths: ["src/**", "tests/**"]
---
# TDD rules

### TDD-001 — No production code without a recorded failing test
Scope: `src/**`
Rule: An edit under `src/**` MUST be preceded by a RED entry (failing test file + assertion output) in `.agents/state/<task-id>.md` for the current task.
Rationale: The TDD law is the harness's core; unrecorded REDs are indistinguishable from no RED.
BAD:  implementer writes `Money::multiply` before any test exists
GOOD: state file has `RED: tests/Unit/Shared/Domain/MoneyTest.php — "Failed asserting 5997 equals null"` before the method exists.
Enforced by: `tdd-gate.sh` hook (blocks src/** edits without RED entry) — see §6.

### TDD-002 — RED must fail for the right reason
Scope: `tests/**`
Rule: The recorded failure MUST be an assertion/behavior failure (or "method does not exist" for new APIs), never a syntax error, fatal, or misconfigured autoload; fixing GREEN MUST change production code, never the test.
Rationale: A test that fails for the wrong reason proves nothing; editing tests to pass is fraud.
BAD:  RED record shows `ParseError`; GREEN achieved by deleting the assertion
GOOD: RED shows `Failed asserting that two objects are equal` on the new behavior; GREEN adds the minimal implementation.
Enforced by: `tdd-gate.sh` parses phpunit output + test-reviewer.

### TDD-003 — One behavior per test, one behavior per cycle
Scope: `tests/**`
Rule: Each test method MUST verify exactly one behavior; each RED→GREEN cycle adds exactly one behavior.
Rationale: Multi-behavior tests obscure which rule broke and invite big-bang implementations.
BAD:  `testMoney()` asserting add, subtract, multiply and rounding in one method
GOOD: `it_multiplies_minor_units_by_quantity()` — single assertion path.
Enforced by: test-reviewer (one-assertion-path heuristic) + code-reviewer.

### TDD-004 — Test names describe behavior in domain language
Scope: `tests/**`
Rule: Test methods MUST be named `it_<behavior>` / `test_<behavior>` stating the business rule, not the implementation.
Rationale: Tests are the executable specification; implementation names rot on refactor.
BAD:  `testReserveStockMethodReturnsTrue()`
GOOD: `it_rejects_reservation_when_available_stock_is_insufficient()`
Enforced by: `vendor/bin/phpunit --testdox` in CI + test-reviewer.

### TDD-005 — Tests are deterministic
Scope: `tests/**`
Rule: Tests MUST NOT call `sleep`, `rand`, `mt_rand`, `uniqid`, real time or real network; time via fixed `ClockInterface`, IDs via `InMemoryIdGenerator`, HTTP via contract fakes.
Rationale: Flaky tests get ignored, then real regressions get ignored.
BAD:  `sleep(2)` waiting for the projection
GOOD: dispatch event synchronously in the test, or `await`-helper with DB poll timeout 0 in projection tests.
Enforced by: `guard-cqrs.sh` hook grep (`sleep(|rand(|mt_rand(|uniqid(` in tests) + test-reviewer.

### TDD-006 — No logic in tests
Scope: `tests/**`
Rule: Tests MUST NOT contain `if`, `for`, `while`, `try/catch` (except PHPUnit `expectException`); parameterized cases use data providers.
Rationale: Logic in tests needs its own tests.
BAD:  `foreach ($cases as $c) { self::assertEquals($c[1], Money::ofMinor($c[0], EUR)); }` with inline loop over ad-hoc arrays
GOOD: `#[DataProvider('multiplicationCases')]`.
Enforced by: test-reviewer + grep heuristic in `guard-cqrs.sh`.

### TDD-007 — Test data comes from builders/mothers
Scope: `tests/**`
Rule: Complex object setup MUST use builders under `tests/Builders/<Context>/` with sensible defaults and explicit overrides; no copy-pasted 20-line arrangements.
Rationale: Setup noise hides the behavior under test and breaks in bulk on refactors.
BAD:  15 lines of constructor calls in every Ordering test
GOOD: `anOrder()->withLines(2)->placed()->build()`.
Enforced by: test-reviewer.

### TDD-008 — Domain objects are real; only ports are mocked
Scope: `tests/Unit/**`
Rule: Unit tests MUST instantiate value objects and aggregates for real; `createMock` is allowed only for ports (repositories, gateways, clock, bus).
Rationale: Mocking the domain tests the mock, not the business rule.
BAD:  `$order = $this->createMock(Order::class);`
GOOD: `$order = anOrder()->build();` + `$this->createMock(OrderRepository::class)`.
Enforced by: `guard-cqrs.sh` hook grep `createMock\((Money|Order|Cart|StockItem)::class` + test-reviewer.

### TDD-009 — Handler unit tests use in-memory repositories
Scope: `tests/Unit/**`
Rule: Command-handler unit tests MUST run against in-memory fake repositories (arrays), not Doctrine and not mocks of the aggregate.
Rationale: Fast, deterministic tests that still exercise the real port contract.
BAD:  handler test booting the kernel with SQLite
GOOD: `new InMemoryOrderRepository()` implementing the same port; integration suite covers Doctrine.
Enforced by: test-reviewer + shared `InMemory*` fixtures.

### TDD-010 — Mutation score gate on Domain + Application
Scope: `src/*/Domain/**`, `src/*/Application/**`
Rule: Infection MUST report MSI ≥ 85 and covered MSI ≥ 90 on Domain+Application; surviving mutants are triaged, not ignored.
Rationale: Green tests with dead mutants are assertions that never fire.
BAD:  infection at 61% MSI committed as "fine"
GOOD: `vendor/bin/infection --min-msi=85 --min-covered-msi=90` in the pre-finish hook and CI; survivors get test requests via mutation-test-agent.
Enforced by: infection.json.dist + `pre-finish.sh` hook + CI.
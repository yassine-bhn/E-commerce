# Task G-solid-remediation
Type: refactor
Started: 2026-11-01T20:41:22Z
Requirement: Enforce the SOLID rulebook mechanically — architecture suite, PHPStan rules, `bin/solid-check`, `bin/tdd-check`, hooks, CI — per docs/superpowers/plans/2026-10-29-solid-remediation.md. Gates exist and fail first; production fixes land phase by phase.

## RED record
RED: tests/Unit/PhpStan/NoDoctrineInApplicationRuleTest.php — "Failed asserting that two strings are identical: expected DIP-003 application error missing on line 11, skeleton reports nothing" — cmd: docker compose exec -T app vendor/bin/phpunit tests/Unit/PhpStan → FAIL
RED: tests/Unit/PhpStan/ControllerThinRuleTest.php — "Failed asserting that two strings are identical: expected 3 SRP-002 controller errors missing on lines 12-14, skeleton reports nothing" — cmd: docker compose exec -T app vendor/bin/phpunit tests/Unit/PhpStan → FAIL
RED: tests/Unit/PhpStan/OneHandlerPerClassRuleTest.php — "Failed asserting that two strings are identical: expected SRP-001 one-handler error missing on line 9, skeleton reports nothing" — cmd: docker compose exec -T app vendor/bin/phpunit tests/Unit/PhpStan → FAIL
RED: tests/Unit/PhpStan/InterfaceMethodLimitRuleTest.php — "Failed asserting that two strings are identical: expected ISP-001 method-limit error missing on line 7, skeleton reports nothing" — cmd: docker compose exec -T app vendor/bin/phpunit tests/Unit/PhpStan → FAIL
RED: tests/Unit/PhpStan/ConstructorDependencyLimitRuleTest.php — "Failed asserting that two strings are identical: expected ISP-003 dependency-limit error missing on line 33, skeleton reports nothing" — cmd: docker compose exec -T app vendor/bin/phpunit tests/Unit/PhpStan → FAIL
RED: tests/Unit/PhpStan/NoInfrastructureInHandlerRuleTest.php — "Failed asserting that two strings are identical: expected DIP-003 handler error missing for Connection, DBAL and HttpFoundation params, current rule misses those types" — cmd: docker compose exec -T app vendor/bin/phpunit tests/Unit/PhpStan → FAIL
ATTEMPTS: 1
Run record: tests/Unit/PhpStan — Tests: 18, Assertions: 18, Failures: 8 (every `it_reports` test; all 10 clean-case tests green on the skeletons).

## Gate RED (Task 1.7 — 2026-11-01)
All gates exist and fail; failures match the audit. No production fix yet.

## Phase 2 — structural fixes

### Task 2.1 — split CartCommandHandler
RED: tests/Architecture/SolidBoundariesTest.php — "Cart/Application/CommandHandler/CartCommandHandler.php has 4 AsMessageHandler attributes" — cmd: docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture --filter itAllowsOneHandlerPerClass → FAIL
ATTEMPTS: 1
GREEN: tests/Unit/Cart (20 tests, incl. 8 new handler tests over in-memory fakes), tests/Functional/StorefrontCartTest (8 tests through the kernel) — cmd: docker compose exec -T app vendor/bin/phpunit tests/Unit/Cart && vendor/bin/phpunit --testsuite=Functional --filter StorefrontCartTest → PASS; itAllowsOneHandlerPerClass no longer flags Cart (3 identity findings remain for 2.2-2.4); phpstan src/Cart clean.

### Task 2.2 — split CustomerProfileCommandHandler
RED: tests/Architecture/SolidBoundariesTest.php — "Identity/Application/CommandHandler/CustomerProfileCommandHandler.php has 3 AsMessageHandler attributes" — cmd: docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture --filter itAllowsOneHandlerPerClass → FAIL
ATTEMPTS: 1
GREEN: tests/Integration/Identity/CustomerProfileHandlersTest (4 tests: upsert through command.bus, email-collision semantics, address upsert+update, delete) + Functional profile tests (4) — cmd: docker compose exec -T app vendor/bin/phpunit tests/Integration/Identity/CustomerProfileHandlersTest && vendor/bin/phpunit --testsuite=Functional --filter 'CustomerProfile|CustomerAddress|Profile' → PASS; Connection kept per plan (Phase 3.1 removes it; its agents.dip003.noInfrastructure findings are the intended RED).

- Architecture suite (SolidBoundariesTest): 68 failures —
  it_keeps_application_free_of_forbidden_types 37, it_keeps_controllers_thin 12,
  it_forbids_sql_in_http 7, it_limits_constructor_dependencies 5,
  it_allows_one_handler_per_class 4, it_forbids_match_true 2, it_limits_ports_to_three_methods 1;
  ports_in_inner_layers and service_location have no violations (sentinel cases pass).
  cmd: `docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture` → FAIL
  first failure: `Catalog/Application/CommandHandler/AddCategorySubLevelHandler.php uses forbidden type Doctrine\DBAL\Connection`
- PHPStan: 162 errors, all in src/** — agents.dip003.application 83,
  agents.dip003.noInfrastructure 33, agents.srp002.controller 30,
  agents.isp003.dependencyLimit 5, agents.srp001.oneHandler 4, agents.isp001.methodLimit 1
  (= 156 gate findings; +6 pre-existing StorefrontProductRepository type errors).
  cmd: `docker compose exec -T app vendor/bin/phpstan analyse --no-progress` → FAIL
- Deptrac: 0 errors (clean).
- guard-domain-imports.sh over src: 54 findings (LAY-002) → exit 1.
- guard-cqrs.sh over src: 38 findings (TDD-005 etc.) → exit 1.
  cmd: `make solid` → FAIL (fails fast at the Architecture suite).
- TDD gate: `make tdd` → tdd-check --staged passes (no src staged), Unit/Integration/
  Projection/Functional/Contract green, Architecture suite 68 failures → make aborts before
  infection. cmd: `make tdd` → FAIL.
  `bin/tdd-check --range f57ba95...HEAD` → FAIL on bb46117: 3 new brand exception classes
  without a referencing test (true positive; recorded as the mid-branch state).
  `bin/tdd-check --self-test` → 16/16 scenarios pass. `TddHarnessTest` → 5 tests pass.
  tdd-gate.sh accepts the RED records in this file (explicit-args and no-args modes).

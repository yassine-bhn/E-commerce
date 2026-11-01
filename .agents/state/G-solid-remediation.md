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

### Task 2.3 — split WishlistCommandHandler
RED: tests/Architecture/SolidBoundariesTest.php — "Identity/Application/CommandHandler/WishlistCommandHandler.php has 2 AsMessageHandler attributes" — cmd: docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture --filter itAllowsOneHandlerPerClass → FAIL
ATTEMPTS: 1
GREEN: tests/Integration/Identity/WishlistHandlersTest (4 tests: insert through command.bus, duplicate add is a no-op, removal deletes the matching row, other slugs untouched) + Functional WishlistTest (7) — cmd: docker compose exec -T app vendor/bin/phpunit tests/Integration/Identity/WishlistHandlersTest && vendor/bin/phpunit --testsuite=Functional --filter WishlistTest → PASS; Connection kept per plan (Phase 3.1 removes it).

GREEN: tests/Integration/Identity/CustomerProfileFinderHandlersTest (3 tests: profile row shape through query.bus, null for unknown, pagination + city search + LIKE escaping) + Functional CustomerProfileTest (2) — cmd: docker compose exec -T app vendor/bin/phpunit tests/Integration/Identity/CustomerProfileFinderHandlersTest && vendor/bin/phpunit --testsuite=Functional --filter 'CustomerProfile' → PASS. itAllowsOneHandlerPerClass is now fully green (sentinel case added; Architecture count 68 → 67). SRP-001 has zero remaining violations.

### Task 2.4 — split CustomerProfileFinder
RED: tests/Architecture/SolidBoundariesTest.php — "Identity/Infrastructure/ReadModel/CustomerProfileFinder.php has 2 AsMessageHandler attributes" — cmd: docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture --filter itAllowsOneHandlerPerClass → FAIL
ATTEMPTS: 1
GREEN: tests/Integration/Identity/CustomerProfileFinderHandlersTest (3 tests: profile row shape through query.bus, null for unknown, pagination + city search + LIKE escaping) + Functional CustomerProfileTest (2) — cmd: docker compose exec -T app vendor/bin/phpunit tests/Integration/Identity/CustomerProfileFinderHandlersTest && vendor/bin/phpunit --testsuite=Functional --filter 'CustomerProfile' → PASS. itAllowsOneHandlerPerClass is now fully green (sentinel case added; Architecture count 68 → 67). SRP-001 has zero remaining violations.

### Task 2.5 — split CatalogSubmissionStore (ISP-001)
RED: tests/Architecture/SolidBoundariesTest.php — "Catalog/Application/Port/CatalogSubmissionStore.php has 9 methods" — cmd: docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture --filter itLimitsPortsToThreeMethods → FAIL
RED: tests/Integration/Catalog/CatalogSubmissionStoreTest.php — "Error: Class App\Catalog\Application\Port\CatalogSubmissionCreator not found (resolution test: each narrow port resolves to the same adapter)" — cmd: docker compose exec -T app vendor/bin/phpunit tests/Integration/Catalog/CatalogSubmissionStoreTest.php → FAIL
ATTEMPTS: 1
GREEN: CatalogSubmissionStore (9 methods) replaced by six narrow ports + the existing repository — Creator (create+import), Submitter, Decider, Reviewer, OwnerEditor (ownerModified+move), Deleter; DbalCatalogSubmissionStore implements all, aliases in services.yaml public:true. All 11 consumers inject narrow ports (SetProductPublicationHandler now reads via CatalogSubmissionRepository). CatalogSubmissionStoreTest gains testEverySubmissionPortResolvesToTheSameAdapterInstance (7 ports = 1 adapter). CatalogApprovalDesignTest port inventory updated to CatalogSubmissionDecider.php (enumerated design files follow the intended split). Unit+Integration catalog 172 tests green, Functional catalog/product/brand 135 green, Architecture 66 (port-method-limit rule now green with sentinel). cmd: docker compose exec -T app vendor/bin/phpunit tests/Unit/Catalog tests/Integration/Catalog → PASS.

### Task 2.6 — replace match(true) (OCP-002)
RED: tests/Architecture/SolidBoundariesTest.php — "Catalog/Domain/Model/ApprovalStatus.php uses match (true)" and "Shared/Application/Service/ReadinessService.php uses match (true)" — cmd: docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture --filter itForbidsMatchTrueInApplicationAndDomain → FAIL
ATTEMPTS: 1
GREEN: ApprovalStatus::canMoveTo uses explicit early returns (same transition table), ReadinessService uses ok/unavailable/degraded if-elseif — ApprovalStatusTest + ReadinessServiceTest + Unit (273) + Functional readiness (3) green; Architecture 66 → 64 (match-true rule green with sentinel). cmd: docker compose exec -T app vendor/bin/phpunit tests/Unit/Catalog tests/Unit/Shared && vendor/bin/phpunit --testsuite=Architecture → PASS (64 remaining are Phase 3/4 rules).

### Task 2.7 — PlaceOrderHandler → OrderPlacementStore (ISP-003)
RED: tests/Architecture/SolidBoundariesTest.php — "Ordering/Application/CommandHandler/PlaceOrderHandler.php has 6 class-typed constructor params" — cmd: docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture --filter itLimitsConstructorDependencies → FAIL
ATTEMPTS: 1
GREEN: New OrderPlacementStore port (claim + save) and DbalOrderPlacementStore adapter (processed_commands INSERT ON CONFLICT DO NOTHING moved verbatim; save delegates to OrderRepository); PlaceOrderHandler at 5 deps (quotes, stock, payments, placements, outbox); alias in services.yaml. New tests/Integration/Ordering/OrderPlacementStoreTest (claim/duplicate-claim, save-through-repository) — Ordering integration 11 green, checkout Functional 53 green; itLimitsConstructorDependencies now flags only the four Phase 4 controllers. cmd: docker compose exec -T app vendor/bin/phpunit tests/Integration/Ordering && vendor/bin/phpunit --testsuite=Functional --filter 'Order|Checkout' → PASS.

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

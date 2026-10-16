# Enforcement matrix

Rule ID | Mechanism | File where enforced | Auto-fixable
---|---|---|---
LAY-001 | Deptrac layer rule + hook | deptrac.yaml; .agents/hooks/guard-domain-imports.sh | no
LAY-002 | Deptrac layer rule + hook | deptrac.yaml; .agents/hooks/guard-domain-imports.sh | no
LAY-003 | Deptrac + PHPStan + review | deptrac.yaml; phpstan.neon; architecture-reviewer | no
LAY-004 | Deptrac context ruleset + review | deptrac.yaml; architecture-reviewer | no
CMD-001 | architecture test + review | tests/Architecture/NamingTest.php; code-reviewer | no
CMD-002 | phpstan-strict-rules + hook grep | phpstan.neon; .agents/hooks/guard-cqrs.sh | no
CMD-003 | custom PHPStan rule + hook | .agents/phpstan/HandlerReturnVoidRule.php; .agents/hooks/guard-cqrs.sh | no
CMD-004 | architecture test + CI command | tests/Architecture/*; CI: php bin/console debug:messenger | no
CMD-005 | integration test + review | tests/Integration/**; code-reviewer | no
CMD-006 | integration test (replay same key) | tests/Integration/** | no
QRY-001 | custom PHPStan rule + hook grep | .agents/phpstan/NoWriteInQueryHandlerRule.php; .agents/hooks/guard-cqrs.sh | no
QRY-002 | Deptrac + PHPStan return types + review | deptrac.yaml; phpstan.neon | no
QRY-003 | Deptrac + hook grep | deptrac.yaml; .agents/hooks/guard-cqrs.sh | no
QRY-004 | functional tests (cross-customer 404) + review | tests/Functional/**; security-reviewer | no
EVT-001 | architecture test (reflection) + review | tests/Architecture/**; code-reviewer | no
EVT-002 | custom PHPStan rule + integration test | .agents/phpstan/NoEventBusInCommandHandlerRule.php; tests/Integration/** | no
EVT-003 | projection/integration tests (dispatch ×2) | tests/Projection/**; tests/Integration/** | no
AGG-001 | unit tests per invariant + review | tests/Unit/**; code-reviewer | no
AGG-002 | architecture test + review | tests/Architecture/**; architecture-reviewer | no
AGG-003 | Deptrac Domain layer + unit test | deptrac.yaml; tests/Unit/** | no
BUS-001 | container integration test + review | tests/Integration/**; architecture-reviewer | no
BUS-002 | config review + integration test (sync dispatch) | config/packages/messenger.yaml; tests/Integration/** | no
NAM-001 | architecture naming test + review | tests/Architecture/NamingTest.php | no
ERR-001 | functional tests (422 contract) + review | tests/Functional/** | no
ERR-002 | Deptrac Domain layer + functional 4xx test | deptrac.yaml; tests/Functional/** | no
ERR-003 | hook grep + review | .agents/hooks/guard-cqrs.sh; code-reviewer | no
SEC-001 | hook (PAN/secret scan on diff) + contract tests + review | .agents/hooks/guard-secrets.sh; tests/Contract/**; security-reviewer | no
SEC-002 | functional tests per read model + review | tests/Functional/**; security-reviewer | no
SEC-003 | contract test (valid/invalid HMAC) + review | tests/Contract/**; security-reviewer | no
SEC-004 | projection test (views scrubbed) + review | tests/Projection/**; security-reviewer | no
SEC-005 | hook blocks commit + gitleaks in CI | .agents/hooks/guard-secrets.sh; CI | no
MIG-001 | human approval gate + CI migrate up/down + review | .agents/orchestration.md; CI; code-reviewer | no
MIG-002 | projection replay test + review | tests/Projection/** | no
PRF-001 | functional test query count + review | tests/Functional/**; performance-reviewer | no
PRF-002 | migration review checklist + EXPLAIN in tests | migrations/**; performance-reviewer | no
PRF-003 | messenger routing review | config/packages/messenger.yaml; performance-reviewer | no
ECO-001 | custom PHPStan rule + hook grep + unit tests | .agents/phpstan/NoFloatInDomainRule.php; .agents/hooks/guard-cqrs.sh | no
ECO-002 | unit tests on Money + review grep | tests/Unit/Shared/Domain/MoneyTest.php; code-reviewer | no
ECO-003 | architecture test + saga integration tests + ADR | tests/Architecture/**; tests/Integration/**; docs/adr/ADR-0004-checkout-saga.md | no
ECO-004 | hook heuristic + concurrency integration test | .agents/hooks/guard-cqrs.sh; tests/Integration/Inventory/** | no
ECO-005 | contract test (webhook ×2) + integration tests | tests/Contract/**; tests/Integration/** | no
ECO-006 | Deptrac (Ordering ⇏ Catalog) + unit test | deptrac.yaml; tests/Unit/Ordering/** | no
ECO-007 | ADR + review | docs/adr/ADR-0003-ux-consistency.md; architecture-reviewer | no
ECO-008 | ADR + review + projection test | docs/adr/ADR-0005-meilisearch.md; architecture-reviewer; tests/Projection/Catalog/** | no
TDD-001 | TDD gate hook blocks src/** writes | .agents/hooks/tdd-gate.sh | no
TDD-002 | gate hook parses phpunit output + review | .agents/hooks/tdd-gate.sh; test-reviewer | no
TDD-003 | review (one assertion path) | test-reviewer | no
TDD-004 | CI testdox + review | CI: vendor/bin/phpunit --testdox; test-reviewer | no
TDD-005 | hook grep (sleep/rand/uniqid) + review | .agents/hooks/guard-cqrs.sh; test-reviewer | no
TDD-006 | review + hook grep heuristic | test-reviewer; .agents/hooks/guard-cqrs.sh | no
TDD-007 | review (builders under tests/Builders) | test-reviewer | no
TDD-008 | hook grep (createMock of domain classes) + review | .agents/hooks/guard-cqrs.sh; test-reviewer | no
TDD-009 | review + shared InMemory fixtures | test-reviewer | no
TDD-010 | infection gate in hook + CI | .agents/hooks/pre-finish.sh; infection.json.dist; CI | no
SRP-001 | architecture test + review | tests/Architecture/**; code-reviewer | no
SRP-002 | Deptrac Http layer + review | deptrac.yaml; code-reviewer | no
SRP-003 | review + subscriber naming test | code-reviewer; tests/Architecture/** | no
OCP-001 | diff review | code-reviewer; solid-guardian | no
OCP-002 | hook grep (switch/match on type) + review | .agents/hooks/guard-cqrs.sh; solid-guardian | no
OCP-003 | config review + container integration test | services.yaml review; tests/Integration/** | no
LSP-001 | shared abstract contract tests | tests/Integration/** (ContractTest inheritance) | no
LSP-002 | PHPStan level 8 + strict-rules | phpstan.neon | no
LSP-003 | one contract suite per gateway, CI | tests/Contract/PaymentGatewayContractTest.php | no
ISP-001 | architecture test (port method count) + review | tests/Architecture/**; solid-guardian | no
ISP-002 | Deptrac + review | deptrac.yaml; architecture-reviewer | no
ISP-003 | PHPStan unused-param detection + review | phpstan.neon; solid-guardian | no
DIP-001 | Deptrac + review | deptrac.yaml; architecture-reviewer | no
DIP-002 | hook grep (container->get) + phpstan-symfony | .agents/hooks/guard-cqrs.sh; phpstan.neon | no
DIP-003 | custom PHPStan rule + Deptrac | .agents/phpstan/NoInfrastructureInHandlerRule.php; deptrac.yaml | no

## Agent scope & TDD gates

Gate/rule | Mechanism | File | Auto-fixable
---|---|---|---
AGT-001 | scope guard hook per AGENT_NAME + audit log | .agents/hooks/scope-guard.sh | no
AGT-002 | completion hook regex on findings format | .agents/hooks/completion-check.sh | no
AGT-003 | audit log review + ownership check | .agents/hooks/completion-check.sh | no
AGT-004 | scope guard hook (orchestrator globs) | .agents/hooks/scope-guard.sh | no
AGT-005 | completion hook + harness self-test | .agents/hooks/completion-check.sh; bin/harness-check | no
AGT-006 | orchestrator gate + state schema check | .agents/orchestration.md; bin/harness-check | no
TDD gate (RED record) | tdd-gate hook on every src/** write | .agents/hooks/tdd-gate.sh | no
Post-edit fast check | php-cs-fixer + PHPStan on changed files | .agents/hooks/post-edit.sh | yes (php-cs-fixer without --dry-run)
Pre-finish full check | phpstan + deptrac + suites + infection | .agents/hooks/pre-finish.sh | no
Hook wiring | declarative mapping → tool hook events | .agents/hooks/hooks.yaml | no
Harness self-test | validates agents/rules/skills/hooks/workflows | bin/harness-check; CI | no

## Skills (invoked by agents, not enforcement)

Skill | Used by
---|---
tdd-cycle | unit-test-writer, implementer, refactorer
add-command | test-planner, unit-test-writer, implementer, integration-test-agent
add-query | test-planner, implementer, projection-test-agent
add-domain-event | implementer, integration-test-agent, projection-test-agent
add-read-model-projection | implementer, projection-test-agent
process-manager-saga | pattern-selector, implementer, integration-test-agent
choose-pattern | pattern-selector, orchestrator
solid-review | solid-guardian, refactorer, code-reviewer
code-review | code-reviewer, architecture-reviewer, security-reviewer, performance-reviewer, test-reviewer
investigate | investigator
mutation-triage | mutation-test-agent
write-adr | pattern-selector, orchestrator
manage-agents | orchestrator

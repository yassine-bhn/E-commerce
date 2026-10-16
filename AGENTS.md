# AGENTS.md — E-commerce CQRS Harness

Project: **ecommerce-core** (Composer: `ecommerce/ecommerce-core`, Symfony 8.1)

Bootstrap: [docs/PROJECT-START-SPEC.md](docs/PROJECT-START-SPEC.md). Load on every task. Rules, agents, skills, hooks are linked, never repeated here.

## Architecture (10 lines)
1. Modular monolith, 8 bounded contexts: Catalog, Cart, Ordering, Payment, Inventory,
   Shipping, Identity, Promotions — split justified in [ADR-0001](docs/adr/ADR-0001-bounded-contexts.md).
2. CQRS (Azure guidance): write model = commands + aggregates + Doctrine ORM;
   read model = queries + denormalized `*_view` tables + Doctrine DBAL/SQL.
3. Three Messenger buses, each with its own middleware: `command.bus` (sync),
   `query.bus` (sync), `event.bus` (async) — see [.agents/rules/messenger.md](.agents/rules/messenger.md).
4. Commands are task-based, immutable, return void, exactly one handler; the handler is the transaction boundary.
5. Aggregates record domain events; events go to the `outbox` table in the SAME transaction,
   a relay publishes to `event.bus`; every subscriber is idempotent.
6. Query handlers: DBAL-only, authorized per requester, return DTOs; never persist/flush/dispatch.
7. Storefront catalog search: Meilisearch projection (ECO-008); all other reads are SQL.
8. State-based write model; no event sourcing; Payment audit via append-only `payment_ledger`.
9. Checkout = process-manager saga: PlaceOrder + stock reservation SYNC → payment authorization
   ASYNC → confirmation ASYNC; compensations ReleaseStock / VoidPayment (ECO-003).
10. Layers per context: `Domain ← Application ← Infrastructure`; cross-context only via
    events or an anti-corruption layer — enforced by Deptrac.

## Stack
- Runtime: FrankenPHP in worker mode (integrated Caddy) — never php-fpm, nginx, Apache or `symfony serve` (INF-003).
- Database: PostgreSQL 16 for dev, test and prod — never MySQL/MariaDB or SQLite, even in tests (INF-001).
- Execution: Docker Compose only — every PHP/Composer/vendor command runs as `docker compose exec app …` on the host (INF-002).
- Search: Meilisearch projection for storefront catalog search (ECO-008).
- Details: [.agents/rules/infrastructure.md](.agents/rules/infrastructure.md), [docs/adr/ADR-0002-postgresql-docker-frankenphp.md](docs/adr/ADR-0002-postgresql-docker-frankenphp.md).

## Directory layout
    src/<Context>/{Domain,Application,Infrastructure}/
      Domain/{Model,Event,Repository,Service}              # ports (interfaces) live here
      Application/{Command,CommandHandler,Query,Saga}      # command & query DTOs, saga
      Infrastructure/{Persistence,ReadModel,Projection,Http,MessageHandler}
    src/Shared/{Domain,Application,Infrastructure}         # Money, Id, bus interfaces, outbox
    tests/{Unit,Integration,Projection,Functional,Contract}/<Context>/...
    tests/Architecture/                                    # naming/structure tests
    docs/adr/                                              # one short ADR per design decision
    .agents/{rules,agents,skills,superpowers/*,hooks,state,audit,phpstan}
    .agents/skills/*.md                                     # 13 domain skills; superpowers/ = vendored plugin library
    .agents/state/<task-id>.md                             # task state, updated at every gate

## Commands (repo root)
    composer install && docker compose up -d
    php bin/console doctrine:migrations:migrate -n && php bin/console doctrine:migrations:migrate -n --env=test
    vendor/bin/phpunit --testsuite=Unit
    vendor/bin/phpunit --testsuite=Integration
    vendor/bin/phpunit --testsuite=Projection
    vendor/bin/phpunit --testsuite=Functional
    vendor/bin/phpunit --testsuite=Contract
    vendor/bin/php-cs-fixer fix --dry-run --diff          # drop --dry-run to fix
    vendor/bin/phpstan analyse
    vendor/bin/deptrac analyse --config-file=deptrac.yaml
    vendor/bin/infection --threads=max --min-msi=85 --min-covered-msi=90
    bin/harness-check                                      # validates this harness

## TDD law
No production code without a failing test that fails for the right reason
([.agents/rules/tdd.md](.agents/rules/tdd.md)). RED: ONE failing test, failure output recorded
in `.agents/state/<task-id>.md`. GREEN: smallest production change, never edit tests.
REFACTOR: suite stays green. One behavior per cycle.

## Agent roster (definitions: [.agents/agents/](.agents/agents/))
| Agent | Writes | Mission |
|---|---|---|
| orchestrator | .agents/state/**, .agents/audit/** | plans, routes, enforces workflow gates |
| pattern-selector | read-only | picks pattern, outputs ADR with rejected alternatives |
| investigator | read-only | hypothesis-driven root cause + reproducing-test proposal |
| solid-guardian | read-only | SOLID review citing SOLID-* rule IDs |
| test-planner | read-only | requirement → ordered behavior list |
| unit-test-writer | tests/Unit/** | RED: one failing test at a time |
| implementer | src/** (never tests/**) | GREEN: minimal passing change |
| refactorer | src/**, tests/** | behavior-preserving cleanup, suite green |
| integration-test-agent | tests/Integration/** | Doctrine, Messenger, outbox, concurrency |
| projection-test-agent | tests/Projection/** | read models, idempotent/replayable projections |
| e2e-contract-test-agent | tests/Functional/**, tests/Contract/** | HTTP flows, gateway contracts, webhooks |
| mutation-test-agent | read-only (runs infection) | triage survivors, request missing tests |
| code-reviewer | read-only | correctness, readability, naming, error handling |
| architecture-reviewer | read-only | CQRS/layering/context boundaries, cites rule IDs |
| security-reviewer | read-only | PCI/PII, authorization, injection, webhooks |
| performance-reviewer | read-only | N+1, indexes, throughput, locking |
| test-reviewer | read-only | TDD discipline, determinism, pyramid balance |

## Routing
| Task type | Workflow | First agent | Entry |
|---|---|---|---|
| new feature | feature | test-planner | /feature |
| bug | bugfix | investigator | /bugfix |
| unclear behavior | investigation-only | investigator | /investigate |
| review only | review-only | orchestrator → reviewers in parallel | /review |
| design decision | pattern-decision-only | pattern-selector | /choose-pattern |
| single TDD cycle | tdd-cycle | unit-test-writer | /tdd |

## Handoff (full contract: [.agents/orchestration.md](.agents/orchestration.md))
Every agent returns the handoff template: task id, summary, artifacts (paths), rule IDs
applied/violated, commands run + results, open questions, next agent. Each gate updates
`.agents/state/<task-id>.md`. Non-conforming output is rejected by the completion hook,
re-dispatched once, then escalated to a human.

## Definition of Done
- [ ] Unit, Integration, Projection, Functional, Contract suites green
- [ ] phpstan, php-cs-fixer, deptrac clean
- [ ] infection MSI ≥ 85 / covered MSI ≥ 90 on touched Domain+Application code
- [ ] Parallel reviews approved; every finding cited a rule ID and is fixed or ADR'd
- [ ] ADR written for any new pattern or boundary decision
- [ ] State file at gate DONE; audit log complete

## Hard NEVERs
- NEVER use float for money (ECO-001)
- NEVER persist/flush/dispatch in a query handler (QRY-001)
- NEVER store or log card data; provider tokens only (SEC-001)
- NEVER read-then-write stock; atomic conditional update only (ECO-004)
- NEVER write production code without a RED record (TDD-001)
- NEVER write outside your agent's declared globs (AGT-001)
- NEVER commit vendor/, .env or secrets (SEC-005)
- NEVER dispatch domain events outside the outbox (EVT-002)
- NEVER recalculate orders from the current catalog (ECO-006)
- NEVER edit tests to reach GREEN; change production code (TDD-002)
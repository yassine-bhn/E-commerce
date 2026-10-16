# Project start spec — `ecommerce-core`

Symfony **8.1** modular monolith merged into this repository. Runtime and workflow are defined by **[AGENTS.md](../AGENTS.md)**, **[.agents/orchestration.md](../.agents/orchestration.md)**, and ADRs — not by the default Symfony skeleton alone.

| Item | Value |
|------|--------|
| Composer name | `ecommerce/ecommerce-core` |
| PHP | ≥ 8.4 (FrankenPHP image) |
| Database | PostgreSQL **16** only (INF-001) |
| HTTP | FrankenPHP worker + Caddy (INF-003) — **never** `symfony serve`, php-fpm, nginx, Apache |
| Execution | **Docker Compose**; host runs `docker` + `git` only (INF-002) |
| Contexts | 8 bounded contexts + `Shared` (ADR-0001) |

---

## 1. Prerequisites

- Docker Engine + Compose v2
- Git
- (Optional) Symfony CLI — **scaffolding only**; do not use `symfony serve` for this project

---

## 2. First-time bootstrap

From the repository root (`E-commerce/` git checkout):

```bash
cp .env .env.local
# Edit .env.local: APP_SECRET, POSTGRES_* if needed. Do not commit secrets (SEC-005).

docker compose up -d --build

docker compose exec app composer install

docker compose exec app php bin/console doctrine:database:create --if-not-exists
docker compose exec app php bin/console doctrine:migrations:migrate -n
docker compose exec app php bin/console doctrine:database:create --if-not-exists --env=test
docker compose exec app php bin/console doctrine:migrations:migrate -n --env=test

bin/harness-check
docker compose exec app vendor/bin/phpunit --testsuite=Architecture
```

**App URL:** http://localhost:8080 (override with `APP_PORT` in `.env.local`).

**Meilisearch:** http://localhost:7700 (`MEILI_PORT` optional).

---

## 3. Day-to-day commands (always in container)

Per AGENTS.md and INF-002:

```bash
docker compose exec app php bin/console …
docker compose exec app vendor/bin/phpunit --testsuite=Unit
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/deptrac analyse --config-file=deptrac.yaml
docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff
# Infection (TDD-010): add `infection/infection` when a release supports Symfony Console 8.x; config is `infection.json.dist`
```

Host-only harness (no PHP app required):

```bash
bin/harness-check
bin/hook-smoke    # optional hook regression (needs /tmp/opencode fixtures)
```

---

## 4. Source layout (already scaffolded)

```
src/
  Catalog|Cart|Ordering|Payment|Inventory|Shipping|Identity|Promotions/
    Domain/{Model,Event,Repository,Service}
    Application/{Command,CommandHandler,Query,Saga}
    Infrastructure/{Persistence,ReadModel,Projection,Http,MessageHandler}
  Shared/
    Domain/          # Money, Id, …
    Application/     # bus ports, outbox interface
    Infrastructure/  # outbox, messenger wiring
tests/
  Unit|Integration|Projection|Functional|Contract|Architecture|Builders/
```

Empty leaves use `.gitkeep`. **Do not** add production code under `src/**` without a recorded `RED:` in `.agents/state/<task-id>.md` (TDD-001, `tdd-gate.sh`).

---

## 5. Agent harness (mandatory process)

1. Load **AGENTS.md** on every task.
2. Entry points (orchestrator): `/feature`, `/bugfix`, `/investigate`, `/review`, `/choose-pattern`, `/tdd`.
3. Agents, rules, hooks: `.agents/agents/`, `.agents/rules/`, `.agents/hooks/`.
4. Domain skills: `.agents/skills/*.md`; process skills: `.agents/skills/superpowers/*/SKILL.md`.
5. State + audit: `.agents/state/<task-id>.md`, `.agents/audit/<task-id>.log` (orchestrator only).
6. Hooks run on write/commit/finish — see `.agents/hooks/hooks.yaml`.

**Workflow (feature):** PLAN → PATTERN (if needed) → RED → GREEN → REFACTOR → SPEC-TESTS → REVIEW → FIXES → DONE.

---

## 6. Architecture checkpoints (implementation order)

Use this as the default backlog after bootstrap. Each slice should follow **TDD** and an orchestrator task id (`F-*`).

| Phase | Deliverable | Rule / ADR refs |
|-------|-------------|-----------------|
| A | Shared `Money`, `Currency` | ECO-001, ECO-002 |
| B | Three Messenger buses + middleware stubs | BUS-001, BUS-002, messenger.yaml |
| C | Outbox table + `outbox_append` middleware + relay consumer | EVT-002, EVT-003 |
| D | Deptrac context rulesets (8 contexts) | LAY-004, ADR-0001 |
| E | First vertical slice: Catalog command + read model | CMD-*, QRY-* |
| F | Meilisearch catalog projection | ECO-008, ADR-0005 |
| G | Checkout saga | ECO-003, ADR-0004 |

PHPStan custom rules (harness copies in `.agents/phpstan/`, registered in `phpstan.neon`): CMD-003, QRY-001, EVT-002, ECO-001, DIP-003.

---

## 7. Definition of Done (repo)

Matches AGENTS.md:

- All PHPUnit suites green (Unit, Integration, Projection, Functional, Contract, Architecture)
- `phpstan`, `php-cs-fixer`, `deptrac` clean
- Infection MSI ≥ 85 / covered MSI ≥ 90 on touched Domain+Application code
- Reviews cite rule IDs; migrations and Payment changes pass human approval gates
- `bin/harness-check` passes

---

## 8. What was merged from `symfony new ecommerce-core`

Symfony 8.1 webapp was generated as **`ecommerce-core`**, then merged into this git repo while **keeping**:

- `AGENTS.md` (harness entrypoint)
- `.agents/**` (agents, rules, skills, hooks, phpstan reference rules)
- `docs/adr/**`
- `bin/harness-check`, `bin/hook-smoke`

Replaced / added for stack compliance:

- `compose.yaml` / `docker-compose.yaml` — FrankenPHP `app` + PostgreSQL 16 + Meilisearch
- `config/packages/messenger.yaml` — `command.bus`, `query.bus`, `event.bus` skeleton
- `deptrac.yaml`, `infection.json.dist`, `phpunit.dist.xml` (named suites)
- Bounded-context directory skeleton under `src/`

---

## 9. Troubleshooting

| Issue | Action |
|-------|--------|
| Host `composer` / `php` used by habit | Use `docker compose exec app …` only (INF-002) |
| SQLite/MySQL in `.env` | Forbidden (INF-001); use PostgreSQL DSN only |
| `symfony serve` | Forbidden (INF-003); use FrankenPHP on `:8080` |
| `src/**` write blocked | Create task state + `RED:` line (TDD-001) |
| PHPStan “no container xml” | Run `docker compose exec app php bin/console cache:clear` before full Symfony container analysis |

---

## 10. Related documents

- [ADR-0001 — Bounded contexts](adr/ADR-0001-bounded-contexts.md)
- [ADR-0002 — PostgreSQL + Docker + FrankenPHP](adr/ADR-0002-postgresql-docker-frankenphp.md)
- [.agents/enforcement-matrix.md](../.agents/enforcement-matrix.md)
- [.agents/CHANGELOG.md](../.agents/CHANGELOG.md)

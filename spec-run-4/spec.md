# Spec: DevOps delivery baseline — CI, production runtime, local ops, health observability
Version: 1

## Goal
Both repositories become operable through automation: every change is verified by a GitHub Actions
pipeline that mirrors the local gates, the backend can be built, migrated, released and rolled back
on a single Docker host behind Caddy, routine operations are one `make` command away, and the
running API can be asked whether it is alive and ready. Business behavior does not change.

## Corrected prompt
Implement a DevOps feature for this project: a CI pipeline, production deployment, local operations
tooling, and observability/health monitoring, using GitHub Actions, covering both the `E-commerce`
backend and the `Zidane_front_web` storefront.

## Requirements

### Backend continuous integration

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | `E-commerce/.github/workflows/ci.yml` triggers on `push` (`develop`, `main`), `pull_request` and `workflow_dispatch`, uses a concurrency group with `cancel-in-progress`, and defaults to `permissions: contents: read`; `packages: write` appears only on the publish job of the deploy workflow, never on pull requests. | functional | Q1.1 + Q2 + Q1.2 | must |
| R2 | CI builds the project image with `docker compose build` and runs Composer, PHPUnit and every `vendor/bin/*` tool through that image (`docker compose run/exec`), never through a host PHP; `composer install --no-interaction` runs once per job with a cache keyed on `composer.lock`. | constraint | Q1.1 + INF-002 | must |
| R3 | CI starts PostgreSQL 16 and Meilisearch from `compose.yaml` and waits for their healthchecks (no fixed `sleep`), applies migrations for the dev and test databases, then runs the six PHPUnit suites sequentially so schema-resetting Integration tests cannot race Functional tests. | constraint | Q1.1 + INF-001 | must |
| R4 | CI runs `php-cs-fixer fix --dry-run --diff`, `phpstan analyse`, `deptrac analyse` and `bin/harness-check`; PyYAML is installed explicitly before `harness-check`, whose exact-count assertions are not weakened. The job is red if any gate is red; no step sets `continue-on-error`. | functional | Q1.1 | must |
| R5 | A dedicated infection job runs with the thresholds from `infection.json.dist` against the PHP files changed by the push/PR (filtered to `src/`), installs the coverage driver only in that job through a Docker build argument, and skips cleanly when no `src/` PHP file changed. The measured baseline is recorded in the task state file. | quality | Q1.1 "infection" + repo DoD | must |
| R6 | A prod-image smoke job builds `--target prod`, starts that image against PostgreSQL 16 on a user-defined network, runs migrations in a one-off container, and asserts HTTP 200 with the expected JSON from the readiness endpoint within a bounded retry window; it does not use the dev bind mount. | quality | Q1.2 + Q1.4 | must |
| R7 | `make ci` executes the same command list, in the same order, as the CI quality job (build → composer install → static analysis → migrations → suites → harness), so a local green predicts a CI green apart from environment bootstrap. | quality | Q1.1 + Q1.3 | must |

### Backend production image

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R8 | `docker/Dockerfile` is multi-stage with an explicit `dev` target that preserves today's volume-mounted behavior and a `prod` target that copies the application, installs `--no-dev` dependencies with an optimized autoloader, warms the prod cache, runs as a non-root user, listens on unprivileged port `8080`, and declares a `HEALTHCHECK` against `/healthz`. | functional | Q1.2 | must |
| R9 | `E-commerce/.dockerignore` excludes `vendor/`, `var/`, `.git/`, `node_modules/`, `.phpunit.cache/`, `.php-cs-fixer.cache`, `.deptrac.cache`, `spec-run*/`, `backups/` and every `.env*.local` file; no secret enters any image layer, and `composer dump-env` is not used, so runtime environment variables always win. | constraint | SEC-005 + Q1.2 | must |
| R10 | The prod image serves through FrankenPHP with the image's integrated Caddy configuration (INF-003); worker mode is explicitly **not** enabled in this delivery because it changes request lifecycle semantics, and the resulting INF-003 gap is recorded in the ADR and runbook as a follow-up. | constraint | INF-003 + Q1.2 | must |

### Production stack, release, rollback and backups

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R11 | `E-commerce/compose.prod.yaml` defines `app`, `worker` (`messenger:consume async failed`), `outbox` (repeating `app:outbox:relay`), `database` (PostgreSQL 16), `meilisearch` (v1.11, `MEILI_ENV=production`, master key) and `edge` (Caddy); only `edge` publishes ports (`80`, `443`, `443/udp`), and no database or search port is host-published. | functional | Q1.2 + Q1.4 + SEC | must |
| R12 | Uploaded media is a named volume mounted at `/app/public/uploads` in `app` and `worker`; database, Meilisearch and uploads each have their own persistent volume; edge certificates use named volumes. | functional | Q1.2 (data durability) | must |
| R13 | Production configuration comes from a server-side `.env.prod`; the repository ships `.env.prod.example` with every required key (`APP_DOMAIN`, `STOREFRONT_DOMAIN`, `EDGE_EMAIL`, `APP_SECRET`, `POSTGRES_*`, `MEILI_MASTER_KEY`, `BACKEND_IMAGE`, `IMAGE_TAG`, `STOREFRONT_IMAGE`, `STOREFRONT_CORS_ORIGIN`, `STOREFRONT_APP_URL`, `MAILER_DSN`, `GOOGLE_OAUTH_*`, `BACKUP_RETENTION_DAYS`) and placeholder values; `.env.prod` and `backups/` are git-ignored. | constraint | SEC-005 + Q1.2 | must |
| R14 | The edge Caddy terminates TLS for `APP_DOMAIN` → `app:8080` and `STOREFRONT_DOMAIN` → `storefront:3000`, applies gzip/zstd, and forwards client IP/proto headers the backend trusts (`TRUSTED_PROXIES`). | functional | Q1.2 "Caddy TLS with a real domain" | must |
| R15 | All prod services set `restart: unless-stopped`, healthchecks where meaningful, `depends_on` with `condition: service_healthy` for the database, and json-file log limits (`max-size: 10m`, `max-file: 3`). | quality | Q1.4 | must |
| R16 | `deploy/release.sh` validates required `.env.prod` keys, records the current `IMAGE_TAG` as `PREVIOUS_IMAGE_TAG`, pulls images, runs `doctrine:migrations:migrate -n --allow-no-migration` in a one-off `app` container, runs `app:storefront:index-search`, starts the stack with `--wait`, then smoke-tests `/readyz`; it uses `set -euo pipefail` and aborts on the first failure. | functional | Q1.2 "migrations on release" | must |
| R17 | `deploy/rollback.sh` restores `PREVIOUS_IMAGE_TAG` in `.env.prod`, re-runs `up -d --wait`, smoke-tests readiness, refuses when no previous tag is recorded, and never removes volumes. Migrations are forward-only: rollback reverts images, not schema, which the runbook states. | functional | Q1.2 "rollback path" | must |
| R18 | `scripts/backup-db.sh` uses the `pg_dump` of the running PostgreSQL 16 container to write `backups/db-<UTC timestamp>.dump` plus a tar of the uploads volume, sets `umask 077`, and prunes files older than `BACKUP_RETENTION_DAYS` (default 7); `scripts/restore-db.sh <file>` requires `--yes`, restores into the running database, and refuses to run while application containers are serving unless `--force` is passed. | functional | Q1.3 | must |
| R19 | The backend `deploy.yml` workflow builds and pushes `app` and `storefront` images to `ghcr.io/<owner>/<repo>` tagged with the commit SHA and `latest`, using `GITHUB_TOKEN` and `packages: write`, only on `v*` tags or `workflow_dispatch`; it never publishes on pull requests. Deployment runs `deploy/release.sh` on the host; an optional SSH job is included only behind the repository variable `DEPLOY_ENABLED` and never runs without it. | functional | Q1.2 + Q2 | must |

### Local operations

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R20 | `E-commerce/Makefile` is the single entry point and exposes at least `help` (self-documenting targets), `build`, `up`, `down`, `ps`, `logs`, `sh`, `composer`, `console`, `migrate`, `migrate-test`, `test`, `test-unit`, `test-integration`, `test-projection`, `test-functional`, `test-contract`, `test-architecture`, `cs`, `cs-fix`, `phpstan`, `deptrac`, `infection`, `harness`, `ci`, `db-backup`, `db-restore`, `urls`. It calls only `docker`/`docker compose`; no PHP runs on the host, preserving INF-002. | functional | Q1.3 | must |
| R21 | `E-commerce/compose.yaml` gains an `app` healthcheck and two consumers: `worker` (`messenger:consume async failed`, restarting) and `outbox` (looping `app:outbox:relay` every 5 seconds), both mounting the code as the `app` service does, so locally queued events are actually processed. | functional | Q1.3 "worker + outbox relay services" | must |
| R22 | `make urls` prints the API base URL, Swagger UI, OpenAPI JSON and Meilisearch URLs using `APP_PORT` (default `8081`). | quality | Q1.3 | could |

### Observability and health

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R23 | `GET /healthz` returns `200` and `{"status":"ok"}` without touching any dependency, and is safe to call while the database is down. | functional | Q1.4 | must |
| R24 | `GET /readyz` returns `200` with `{"status":"ok","checks":{...}}` only when the database answers `SELECT 1` and no migration is pending; a database failure or pending migration returns `503` with `{"status":"unavailable","checks":{...}}` naming each failing check; Meilisearch is reported as `ok` or `degraded` but never flips readiness to 503. The database check uses a bounded connection timeout so the endpoint cannot hang. | functional | Q1.4 + Q1.2 migrations | must |
| R25 | Both endpoints are public (explicit `PUBLIC_ACCESS` rules in `security.yaml`), bypass the application rate limiters, send `Cache-Control: no-store`, and never expose `DATABASE_URL`, credentials, SQL text, stack traces or PII. | constraint | Q1.4 + SEC | must |
| R26 | `.github/workflows/healthcheck.yml` runs on a 15-minute schedule and on `workflow_dispatch`, calls `HEALTHCHECK_URL` with a bounded retry, and exits non-zero on a non-200 answer; when the secret is absent the job succeeds with an explicit "not configured" message instead of failing every 15 minutes. | functional | Q1.4 "uptime checks" | must |
| R27 | Production logging stays JSON on stderr via the existing Monolog `prod` configuration, and the observability document explains reading logs (`docker compose -f compose.prod.yaml logs -f app worker outbox`) and handling failed messages (`messenger:failed:show|retry|remove`). | quality | Q1.4 + Q1.2 | must |

### Storefront (Zidane_front_web)

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R28 | `eslint.config.js` ignores generated `.next` output, so `yarn lint` exits 0 on the current tree without relaxing rules or deleting the lint gate. | functional | Q1.1 + verified failure | must |
| R29 | `package.json` gains a `typecheck` script running `tsc --noEmit`, which exits 0 on the current tree. | functional | Q1.1 "type-check" | must |
| R30 | `.github/workflows/ci.yml` runs on `push` (`develop`, `main`) and `pull_request` with Node 22, yarn cache, `yarn install --frozen-lockfile`, `yarn lint`, `yarn typecheck` and `yarn build` with a placeholder `NEXT_PUBLIC_API_URL`; it states in a comment that no unit/e2e suite exists yet. | functional | Q1.1 + Q3 | must |
| R31 | `Zidane_front_web/Dockerfile` is multi-stage with `output: 'standalone'`, copies `public/` and `.next/static` into the runtime stage, runs as a non-root user on port `3000`, accepts `NEXT_PUBLIC_API_URL` as a build argument (Next inlines it), and declares a healthcheck against `/`. | functional | Q1.2 + Q3 | must |
| R32 | `Zidane_front_web/.github/workflows/deploy.yml` builds and pushes the storefront image to `ghcr.io/<owner>/<repo>` on `v*` tags and `workflow_dispatch` only, tagged with commit SHA and `latest`, using `GITHUB_TOKEN` with `packages: write` and never on pull requests. | functional | Q1.2 + Q2 | must |

### Cross-cutting

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R33 | No business behavior, route or payload changes: the only application code addition is the health surface, developed test-first (RED recorded before production code) and recorded in `.agents/state/<task-id>.md`. | constraint | scope | must |
| R34 | After the change, all six suites, `phpstan`, `php-cs-fixer --dry-run`, `deptrac`, `bin/harness-check` and `composer validate` are green. | quality | repo Definition of Done | must |
| R35 | `docs/adr/ADR-0010-operational-baseline.md` records topology, registry, health semantics, backup scope and the explicit deferral of FrankenPHP worker mode, with rejected alternatives. | quality | AGENTS.md ADR rule | should |
| R36 | Documentation states what was not executed here (no registry push, no live deploy, no ACME issuance, no scheduled probe against a real URL), what the operator must provide (server, DNS, secrets, branch protection), and how to run every delivered command. | quality | Q1.2 + Q1.3 | must |

## Locked decisions

| # | Decision | Consequence |
|---|----------|-------------|
| D1 | Production topology is one Docker host with `compose.prod.yaml` and an `edge` Caddy in front of `app` and `storefront`. | No Kubernetes/PaaS; `app` is never host-published; port 8080 internal. |
| D2 | Images are published to GHCR by GitHub Actions on tags/manual dispatch; the release runs on the host. | No SSH key requirement; optional gated SSH job for later. |
| D3 | Worker mode stays disabled; the INF-003 gap is documented rather than silently changed. | Prod runs `php_server`; worker-mode migration is a separate future task. |
| D4 | Health contract: liveness is dependency-free; readiness fails on database **or** pending migrations; Meilisearch degradation is non-fatal. | `readyz` is the deploy/uptime probe; `/healthz` is the container healthcheck. |
| D5 | Uploads are a named volume and are backed up alongside the database (tar). | No CDN/object storage in this delivery. |
| D6 | Migrations are forward-only; rollback reverts image tags only. | Migrations must stay backward-compatible; runbook states this. |
| D7 | Infection is scoped to changed `src/` files and skips when none changed. | Matches the repo DoD ("touched Domain+Application code") without a permanent red gate. |
| D8 | The storefront lint failure is fixed by ignoring `.next`, not by relaxing rules. | `yarn lint` becomes a real gate. |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | Production is a single Linux host with Docker Engine + Compose v2, two DNS names, and ports 80/443 reachable for ACME. | med |
| 2 | The backend repository owner is `yassine-bhn` and the storefront owner is `yousseflimem`; GHCR names derive from each repository. | low |
| 3 | No server, domain or credentials exist in this environment, so the live deployment is delivered as validated artifacts plus a runbook. | high |
| 4 | `.env` may be baked into the image because it carries only non-secret defaults; all real configuration is injected at runtime. | med |
| 5 | `runtime/frankenphp-symfony` compatibility is not required because worker mode is deferred. | low |
| 6 | The storefront is server-rendered per request (`getServerSideProps`), so a Node standalone container is the correct runtime. | low |
| 7 | `NEXT_PUBLIC_API_URL` is public and is therefore baked at build time; changing it needs a rebuild. | low |
| 8 | Backups stay on the same host under `backups/`; off-host copies are a documented follow-up. | high |
| 9 | GitHub-hosted `ubuntu-latest` runners provide Docker Compose v2, Python 3 and enough disk for two image builds. | med |
| 10 | The infection baseline may be below the configured thresholds today; the measured value is recorded and escalated, never muted. | high |
| 11 | Meilisearch uses one master key inside the private network; no separate search API key is introduced. | med |
| 12 | The storefront has no automated test suite and none is added here. | low |

## Open questions
- [minor] Which domains, DNS zone and server will be used? Default: placeholders in `.env.prod.example`; nothing is deployed from here.
- [minor] Should backups be replicated off-host (rsync/S3)? Default: no, recorded as a follow-up risk.
- [minor] Should the optional SSH deploy job be enabled now? Default: no — `DEPLOY_ENABLED` unset, release runs on the host.
- [minor] Should FrankenPHP worker mode be scheduled next? Default: separate follow-up task, documented in the ADR.

## Out of scope
- Any live deployment, DNS change, certificate issuance or registry push performed in this session.
- Kubernetes, Terraform, Ansible, multi-node, blue/green or canary releases.
- Prometheus/Grafana/Sentry/OpenTelemetry, APM vendors, alert routing beyond the scheduled workflow.
- Object storage/CDN for uploads.
- Frontend unit/e2e test framework.
- FrankenPHP worker mode.
- Branch protection, CODEOWNERS and repository settings (documented as manual).
- Business logic, API contracts and existing test expectations.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Backend runtime image (dev + prod) | R8, R9, R10 | `docker/Dockerfile`, `docker/Caddyfile`, `composer.json` | Multi-stage Dockerfile, `.dockerignore`, prod Caddyfile, prod PHP ini | `E-commerce/docker/**`, `E-commerce/.dockerignore` | none | `docker compose build` still works; `docker build --target prod` succeeds; the prod container runs as non-root and serves `/healthz` once the health route exists |
| WP2 | Local ops entry point and dev consumers | R20–R22 | `compose.yaml`, `AGENTS.md` commands | `Makefile`, compose `worker`/`outbox`/app healthcheck, README ops section | `E-commerce/Makefile`, `E-commerce/compose.yaml`, `E-commerce/README.md` | none | every required target exists; `make help`, `make up`, `make ps`, `make test-unit`, `make harness` pass; `docker compose config` valid |
| WP3 | Health and readiness surface (TDD) | R23–R25 | Shared HTTP layer, routing, security | `HealthController` + readiness checker, routes, access-control rules, Functional/Unit tests | `E-commerce/src/Shared/Infrastructure/Http/**`, `E-commerce/config/routes/storefront_api.yaml`, `E-commerce/config/packages/security.yaml`, `E-commerce/tests/**` | none | RED recorded; `/healthz` 200 without auth; `/readyz` 503 on database failure and 200 when up; no-store; no secrets in body |
| WP4 | Backend CI pipeline | R1–R7 | WP1, WP3 | `.github/workflows/ci.yml`, `docs/ci.md` | `E-commerce/.github/workflows/ci.yml`, `E-commerce/docs/ci.md` | WP1, WP3 | YAML parses; every gate command executed locally with its exit status recorded; prod-image smoke job defined |
| WP5 | Production stack, release, rollback, backup | R11–R19 | WP1, health contract | `compose.prod.yaml`, edge Caddyfile, `.env.prod.example`, `deploy/**`, `scripts/**`, runbook, `.gitignore` update | `E-commerce/compose.prod.yaml`, `E-commerce/deploy/**`, `E-commerce/scripts/**`, `E-commerce/.env.prod.example`, `E-commerce/docs/runbook-deployment.md`, `E-commerce/.gitignore`, `E-commerce/.github/workflows/deploy.yml` | WP1 | `docker compose -f compose.prod.yaml config` valid; backup/restore + media tar round-trip proven on the dev stack; shell scripts pass `bash -n` and are exercised where possible |
| WP6 | Storefront image and green lint | R28, R29, R31 | `next.config.ts`, `package.json`, `eslint.config.js` | Dockerfile, `.dockerignore`, standalone output, `typecheck` script, eslint ignore | `Zidane_front_web/Dockerfile`, `Zidane_front_web/.dockerignore`, `Zidane_front_web/next.config.ts`, `Zidane_front_web/package.json`, `Zidane_front_web/eslint.config.js` | none | `yarn lint`, `yarn typecheck`, `yarn build` exit 0; image builds and answers 200 on `/` |
| WP7 | Storefront CI and image publishing | R30, R32 | WP6 | `.github/workflows/ci.yml`, `.github/workflows/deploy.yml` | `Zidane_front_web/.github/workflows/**` | WP6 | YAML parses; the exact commands in the workflow pass locally |
| WP8 | Uptime workflow, observability doc, ADR | R26, R27, R35, R36 | WP3, WP5 | `healthcheck.yml`, `docs/observability.md`, `docs/adr/ADR-0010-operational-baseline.md` | `E-commerce/.github/workflows/healthcheck.yml`, `E-commerce/docs/observability.md`, `E-commerce/docs/adr/ADR-0010-operational-baseline.md` | WP3, WP5 | YAML parses; the no-secret path exits 0; documented curl examples match implemented behavior; ADR lists alternatives |

## Decisions (synthesizer only)

| Topic | A (literal) | B (intent) | C (skeptic) | Chosen | Why |
|-------|-------------|------------|-------------|--------|-----|
| CI runner model | Docker image, commands in container (implied) | Build dev image, run through compose | Must not use host PHP; services from compose | Build dev image + `docker compose run/exec` | Only reading that satisfies INF-002 and stays verifiable locally |
| Prod topology | "production compose + TLS" | Full stack + edge Caddy | Edge only public listener; warn about unverifiable deploy | Single host, `edge` Caddy, no host-published DB/search | Faithful to the prompt, least infrastructure invention |
| Registry | Not stated | GHCR | GHCR, warn owners differ | GHCR via `GITHUB_TOKEN` | Standard for GitHub-hosted repos; no new credentials |
| Deploy trigger | Release procedure | Release + optional SSH deploy | Manual on host, SSH optional | Manual `release.sh`; SSH job gated by `DEPLOY_ENABLED` | Avoids storing SSH keys and claiming an execution we cannot verify |
| Worker mode | Not addressed | Enable with `runtime/frankenphp-symfony` | Behavior change; defer unless proven | Defer, document INF-003 gap in ADR | Less scope creep and no request-lifecycle risk inside a DevOps delivery |
| Health semantics | Liveness + DB readiness | DB critical, Meilisearch non-fatal | DB + pending migrations 503; bounded timeout; no leaks | D4 contract | Prevents deploying against an unmigrated schema while not flapping on search |
| Backup scope | DB backup/restore | DB + retention | DB + uploads tar + restore guard | DB dump + uploads tar, retention 7d, `--yes` | A DB-only backup silently loses banner/product images |
| Infection gating | Run infection | Run with thresholds | Measure first; scope to changed code | Changed-`src/` filter, skip when none, record baseline | Matches repo DoD without a permanent red check |
| Frontend lint | Lint in CI | Ignore generated output | Verified failure is `.next/types` | Ignore `.next`, keep rules | Fixes the real cause, keeps the gate meaningful |
| Uptime probe | Scheduled workflow | Scheduled + docs | No-op when unconfigured | `healthcheck.yml` with secret-absent success | A red schedule every 15 minutes with no URL is noise |

## Changelog (synthesizer and orchestrator only)
- v1: created from analyst-a, analyst-b and analyst-c after user scoping (CI + prod deploy + local
  ops + observability; GitHub Actions; both repositories). No blocking questions: the only
  high-impact unknown (a real server/domain) is answered by the safe default — deliver validated
  artifacts and a runbook, and label the live deploy as not executed.

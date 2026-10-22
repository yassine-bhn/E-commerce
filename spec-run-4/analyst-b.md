# Spec: Operational delivery baseline — CI, production runtime, local ops, observability (analyst B, intent)
Version: 1

## Goal
The project becomes operable by someone other than its author: every change is verified by CI the
same way it is verified locally, a production release can be built, migrated, deployed, rolled
back and backed up from documented commands, and the running system can be asked whether it is
alive and ready.

## Corrected prompt
Implement the DevOps feature for this project — CI, production deployment, local operations
tooling and observability/health — on GitHub Actions, covering both the `E-commerce` backend and
the `Zidane_front_web` storefront.

## Requirements

### Continuous integration — backend

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A GitHub Actions workflow in the backend repository triggers on `push` (develop, main), `pull_request` and `workflow_dispatch`, with a concurrency group that cancels superseded runs. | functional | Q1.1 + Q2 | must |
| R2 | CI builds the same `app` image developers use (`docker compose build`) and runs Composer, PHPUnit and every `vendor/bin` tool through that image/container, never through host PHP. | constraint | INF-002 + Q1.1 | must |
| R3 | CI starts PostgreSQL 16 and Meilisearch from the project's compose file, waits for their health, and applies migrations for the dev and test databases before running tests. | functional | Q1.1 + INF-001 | must |
| R4 | CI runs the six PHPUnit suites (Unit, Integration, Projection, Functional, Contract, Architecture), `phpstan`, `php-cs-fixer --dry-run`, `deptrac` and `bin/harness-check`, and the job is red if any of them is red. | functional | Q1.1 | must |
| R5 | Infection runs in CI with the configured MSI thresholds; the coverage driver is installed only for that job (build argument), so normal image builds stay lean. | quality | Q1.1 "infection" + repo DoD | must |
| R6 | Composer downloads are cached between runs; the cache key derives from `composer.lock` so a lock change invalidates it. | quality | Q1.1 implied by a usable pipeline | should |
| R7 | CI proves the production artifact: it builds `--target prod`, starts it against PostgreSQL 16, runs migrations, and asserts HTTP 200 from the readiness endpoint. A broken prod image fails CI, not the deploy. | quality | Q1.2 "production deployment" + Q1.4 | must |

### Production runtime — backend

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R8 | `docker/Dockerfile` is multi-stage with an explicit `dev` target (current volume-mounted behavior) and a `prod` target that copies the code, installs `--no-dev` dependencies with an optimized autoloader, warms the prod cache and runs as a non-root user on an internal port. | functional | Q1.2 "hardened multi-stage prod Dockerfile" | must |
| R9 | A `.dockerignore` keeps `vendor/`, `var/`, `.git/`, `node_modules/`, test caches and local env files out of the build context, so the prod image contains no secrets and no development artifacts. | constraint | SEC-005 + Q1.2 | must |
| R10 | The production image runs the application on FrankenPHP with the integrated Caddy config of the image (INF-003), listens on an internal port, and declares a `HEALTHCHECK` hitting the liveness endpoint. | constraint | INF-003 + Q1.4 | must |
| R11 | `compose.prod.yaml` runs: `app`, `worker` (`messenger:consume async failed`), `outbox` (repeating `app:outbox:relay`), `database` (PostgreSQL 16, persistent volume), `meilisearch` (production mode, master key, persistent volume) and an `edge` Caddy that is the only service publishing 80/443 and terminates TLS for `APP_DOMAIN` and `STOREFRONT_DOMAIN`. | functional | Q1.2 + Q1.3 | must |
| R12 | Uploaded media survives a release: `public/uploads` is a named volume shared by `app` and `worker`, not an ephemeral layer of the image. | functional | Q1.2 "production deployment" (data durability) | must |
| R13 | All prod services restart automatically, wait for dependencies (`database` healthy) and bound their logs (json-file `max-size`/`max-file`) so a disk cannot fill. | quality | Q1.4 "observability" + Q1.2 | must |
| R14 | Production configuration comes from a server-side `.env.prod`; the repository ships `.env.prod.example` with every required key and placeholder values, and `.env.prod` is git-ignored. | constraint | SEC-005 + Q1.2 | must |
| R15 | The API container receives `APP_ENV=prod`, `APP_DEBUG=0`, `TRUSTED_PROXIES`/`TRUSTED_HOSTS` that reflect the edge proxy, and a session cookie policy usable behind TLS. | constraint | Q1.2 + Symfony prod practice | should |
| R16 | `deploy/release.sh` performs an ordered release: pull images → run migrations in a one-off `app` container → rebuild the Meilisearch index → start/update services with `--wait` → smoke-test readiness; it aborts on the first failure. | functional | Q1.2 "migrations on release" | must |
| R17 | `deploy/rollback.sh` re-deploys the previous image tag recorded in `.env.prod`, waits for health and re-runs the smoke test; it refuses to run when no previous tag is recorded and never drops the database volume. | functional | Q1.2 "rollback path" | must |
| R18 | `scripts/backup-db.sh` produces a compressed `pg_dump` in `backups/` with a timestamped filename and prunes files older than `BACKUP_RETENTION_DAYS` (default 7); `scripts/restore-db.sh <file>` restores into a running stack and requires explicit confirmation. | functional | Q1.3 "DB backup/restore" | must |
| R19 | The backend `deploy.yml` workflow builds and pushes both images to GHCR tagged with the commit SHA and `latest`, using `GITHUB_TOKEN` and minimal permissions; deployment itself runs the release script on the server unless `DEPLOY_ENABLED` is set, in which case an SSH step triggers it. | functional | Q1.2 + Q2 | must |
| R20 | A deployment runbook covers prerequisites, DNS, first deploy, release, rollback, backup/restore, log inspection and failure-transport recovery. | quality | Q1.2/Q1.3/Q1.4 | must |

### Local operations

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R21 | A root `Makefile` is the single entry point: `help` (self-documenting), `build`, `up`, `down`, `ps`, `logs`, `sh`, `composer`, `console`, `migrate`, `migrate-test`, `test`, per-suite test targets, `cs`, `cs-fix`, `phpstan`, `deptrac`, `infection`, `harness`, `ci`, `db-backup`, `db-restore`, `urls`. It invokes only `docker compose`/`docker`; no PHP runs on the host. | functional | Q1.3 | must |
| R22 | `make ci` reproduces the CI sequence locally (composer install, static analysis, all suites, harness, infection optional/fast flag) and exits non-zero on the first failure. | quality | Q1.1 + Q1.3 | must |
| R23 | The dev compose file runs the `async` worker and the outbox relay so locally queued events are actually consumed, and the `app` service declares a healthcheck. | functional | Q1.3 "worker + outbox relay services" | must |
| R24 | `make urls` prints the local entry points (API, docs, Meilisearch) using `APP_PORT`. | quality | Q1.3 "local ops" | could |

### Observability

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R25 | `GET /healthz` returns 200 with a minimal JSON body and no dependency access; `GET /readyz` returns 200 only when the database answers `SELECT 1`, otherwise 503 with a JSON body naming the failing dependency; Meilisearch degradation is reported but does not flip readiness. | functional | Q1.4 | must |
| R26 | Both endpoints are public, excluded from authentication and rate limiting, send `Cache-Control: no-store`, log no PII and leak no connection strings or stack traces. | constraint | Q1.4 + SEC | must |
| R27 | Production logs stay JSON on stderr (Monolog `prod` config) with no change to dev logging; the observability doc states how to read them (`docker compose logs -f app`). | constraint | Q1.4 "structured production logging" | must |
| R28 | A scheduled workflow (every 15 minutes) probes the deployed readiness URL and fails when it is not 200; it no-ops safely when the secret is absent. | functional | Q1.4 "uptime checks" | should |
| R29 | The failure transport is operable: the worker consumes `failed`, and the runbook documents how to list, retry and delete failed messages. | functional | Q1.4 "failure-transport monitoring" | must |

### Continuous integration and runtime — storefront

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R30 | The storefront workflow runs `yarn install --frozen-lockfile`, `yarn lint`, `yarn typecheck` (added `tsc --noEmit` script) and `yarn build` on Node 22 with yarn cache, on push and pull request; lint must be green, which requires ignoring generated `.next` output. | functional | Q1.1 + Q3 | must |
| R31 | The storefront gets a multi-stage Dockerfile using Next.js `output: 'standalone'`, a non-root runtime, `NEXT_PUBLIC_API_URL` as a build argument, and a healthcheck against `/`. | functional | Q1.2 + Q3 | must |
| R32 | The storefront `deploy.yml` builds and pushes its image to GHCR on tags/manual dispatch, mirroring the backend conventions (SHA + latest tags, minimal permissions). | functional | Q1.2 + Q2 | should |

### Cross-cutting

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R33 | No business behavior changes: no new business endpoints, no altered API contracts, no changed test expectations; the only application code addition is the health surface. | constraint | scope of the request | must |
| R34 | `bin/harness-check`, `phpstan`, `deptrac`, `php-cs-fixer` and all suites stay green after the change. | quality | repo Definition of Done | must |
| R35 | Infrastructure decisions (prod topology, registry, health semantics) are recorded in an ADR, as AGENTS.md requires for new infrastructure decisions. | quality | AGENTS.md ADR rule | should |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | Single VPS or dedicated host with Docker Compose; two DNS names (API, storefront) pointing at it; ports 80/443 free. | med |
| 2 | GHCR is the registry and the repositories are private or public with package write permission for Actions. | low |
| 3 | The host has outbound HTTPS to pull images and issue ACME certificates. | low |
| 4 | The deployment user can run `docker compose` and read `.env.prod`; no root-only step is required beyond Docker group membership. | med |
| 5 | `runtime/frankenphp-symfony` 1.0.0 works with Symfony 8.1/PHP 8.4; if not, php_server mode remains and the gap is recorded rather than silently skipped. | med |
| 6 | The storefront talks to the API from the browser, so `NEXT_PUBLIC_API_URL` must be the public API URL and is baked at build time; changing it requires an image rebuild. | low |
| 7 | Uploads are served from the container/volume today; no CDN or object storage is introduced. | med |
| 8 | Backup storage is the same host (`backups/`), acceptable for this stage; off-host copies are a later step. | high |
| 9 | GitHub-hosted runners allow Docker-in-Docker style Compose usage (standard on `ubuntu-latest`). | low |
| 10 | The team accepts GitHub Actions minutes for infection; the job can be limited to pushes if minutes become a problem. | low |
| 11 | Meilisearch in production must have `MEILI_MASTER_KEY`; the app uses the same key as search API key (private network only). | med |

## Open questions
- [minor] Exact domains, server address and deploy method (SSH from Actions vs manual on host). Default: env-driven domains, `deploy/release.sh` on the host, SSH deploy disabled.
- [minor] Should backups be copied off-host (S3/rsync)? Default: no, documented as a follow-up.
- [minor] Is a production Meilisearch API key distinct from the master key required now? Default: one master key inside the private network only.

## Out of scope
- Kubernetes/Helm/Terraform/Ansible, multi-node clustering, blue/green or canary deploys.
- Prometheus/Grafana/Sentry/OpenTelemetry collectors.
- CDN/object storage for media, image optimisation pipeline.
- Frontend unit/e2e test framework.
- Changing the application's business logic, routes or payloads.
- Branch protection, CODEOWNERS and repository settings (manual, documented).

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Backend runtime image (dev + prod) | R8, R9, R10 | current Dockerfile/Caddyfile, composer.json | Multi-stage Dockerfile, `.dockerignore`, prod Caddyfile/php.ini | `E-commerce/docker/**`, `E-commerce/.dockerignore` | none | `docker build --target prod` passes; prod image boots, answers `/healthz` and runs as non-root |
| WP2 | Local ops entry point and dev services | R21–R24 | compose.yaml, AGENTS.md commands | Makefile + compose worker/outbox/healthcheck | `E-commerce/Makefile`, `E-commerce/compose.yaml`, `E-commerce/README.md` | none | every required target exists and the exercised ones pass; `docker compose config` valid |
| WP3 | Health surface (TDD) | R25, R26 | Shared HTTP layer, routing, security | `/healthz`, `/readyz`, tests | `E-commerce/src/Shared/Infrastructure/Http/**`, `E-commerce/config/routes/storefront_api.yaml`, `E-commerce/config/packages/security.yaml`, `E-commerce/tests/**` | none | RED recorded; Functional + Unit green; endpoints public, no-store, 503 on DB failure |
| WP4 | Backend CI | R1–R7 | WP1, WP3 | `.github/workflows/ci.yml`, `docs/ci.md` | `E-commerce/.github/workflows/ci.yml`, `E-commerce/docs/ci.md` | WP1, WP3 | YAML valid; all commands executed locally via `make ci`; prod-image smoke job defined |
| WP5 | Production stack, release, rollback, backup | R11–R20 | WP1, WP8 docs contract | `compose.prod.yaml`, edge Caddyfile, env example, scripts, runbook | `E-commerce/compose.prod.yaml`, `E-commerce/deploy/**`, `E-commerce/scripts/**`, `E-commerce/.env.prod.example`, `E-commerce/docs/runbook-deployment.md`, `E-commerce/.gitignore` | WP1 | `docker compose -f compose.prod.yaml config` valid; backup/restore round-trip proven; release/rollback scripts shellcheck-clean |
| WP6 | Storefront image and lint gate | R30 (lint), R31 | next config, package.json, eslint config | Dockerfile, `.dockerignore`, standalone output, `typecheck`, eslint ignore | `Zidane_front_web/Dockerfile`, `.dockerignore`, `next.config.ts`, `package.json`, `eslint.config.js` | none | lint/typecheck/build exit 0; image builds and serves 200 |
| WP7 | Storefront CI and publishing | R30, R32 | WP6 | `.github/workflows/ci.yml`, `deploy.yml` | `Zidane_front_web/.github/workflows/**` | WP6 | YAML valid; local commands match workflow |
| WP8 | Uptime + observability docs | R27–R29 | WP3, WP5 | `healthcheck.yml`, `docs/observability.md` | `E-commerce/.github/workflows/healthcheck.yml`, `E-commerce/docs/observability.md` | WP3 | YAML valid; no-secret run exits 0; curl examples match behavior |
| WP9 | ADR for the operational baseline | R35 | this spec | `docs/adr/ADR-0010-*.md` | `E-commerce/docs/adr/ADR-0010-*.md` | none | ADR follows repo format with alternatives and consequences |

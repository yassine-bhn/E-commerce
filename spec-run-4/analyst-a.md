# Spec: DevOps feature — CI, production deployment, local ops tooling, observability (analyst A, literal)
Version: 1

## Goal
The two repositories gain the DevOps capabilities the user selected — a GitHub Actions CI
pipeline, a production deployment path, local ops tooling and health/observability — without
changing any business behavior of the backend or the storefront.

## Corrected prompt
Implement the DevOps feature for this project: CI pipeline, production deployment, local ops
tooling and observability/health, running on GitHub Actions, for both the `E-commerce` backend and
the `Zidane_front_web` storefront.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | `E-commerce/.github/workflows/ci.yml` runs on every push and pull request and executes the backend gates: `composer install`, PHPUnit suites Unit, Integration, Projection, Functional, Contract and Architecture. | functional | Q1.1 "automated pipeline on push/PR … all PHPUnit suites" | must |
| R2 | The same workflow runs `php-cs-fixer --dry-run --diff`, `phpstan analyse`, `deptrac analyse` and `bin/harness-check`. | functional | Q1.1 "phpstan, php-cs-fixer, deptrac, harness-check" | must |
| R3 | The same workflow runs `infection` with the repository's MSI thresholds (`--min-msi=85 --min-covered-msi=90`). | functional | Q1.1 "infection" | must |
| R4 | CI uses PostgreSQL 16 as the database service and runs migrations for dev and test before the suites, never SQLite or MySQL. | constraint | Q1.1 "start PostgreSQL 16, run migrations" + INF-001 | must |
| R5 | CI executes PHP, Composer and `vendor/bin/*` inside the `app` container built from `docker/Dockerfile`, not on the runner's host PHP. | constraint | INF-002 (AGENTS.md, applies to CI hosts) | must |
| R6 | The workflow fails when any gate fails; no step uses `continue-on-error`. | constraint | Q1.1 "automated pipeline" | must |
| R7 | The backend image builds a production target (`docker build --target prod`) that contains the application code and production Composer dependencies. | functional | Q1.2 "hardened multi-stage prod Dockerfile" | must |
| R8 | `E-commerce/compose.prod.yaml` defines the production stack: application, PostgreSQL 16, Meilisearch, a Messenger `async` worker and an outbox relay worker. | functional | Q1.2 "production compose … Messenger/outbox worker services" | must |
| R9 | The production stack terminates TLS for the configured domain through Caddy. | functional | Q1.2 "production compose/Caddy TLS with a real domain" | must |
| R10 | A release procedure applies Doctrine migrations before the new application version serves traffic, and reruns the Meilisearch index command. | functional | Q1.2 "migrations on release" | must |
| R11 | A documented rollback returns the stack to the previous image tag without losing database data. | functional | Q1.2 "rollback path" | must |
| R12 | `E-commerce/Makefile` exposes at least: build, up, down, ps, logs, sh, composer, console, migrate, migrate-test, test (all suites), cs, phpstan, deptrac, infection, harness, ci, db-backup, db-restore. | functional | Q1.3 "Makefile/task runner wrapping docker compose (up, test, migrate, shell, logs)" | must |
| R13 | `E-commerce/compose.yaml` gains services that consume the `async` Messenger transport and run `app:outbox:relay`. | functional | Q1.3 "worker + outbox relay services in compose" | must |
| R14 | `E-commerce/scripts/backup-db.sh` writes a `pg_dump` archive of the production database and `scripts/restore-db.sh` restores one. | functional | Q1.3 "DB backup/restore" | must |
| R15 | The backend exposes an unauthenticated health endpoint that reports process liveness and a readiness endpoint that reports database connectivity. | functional | Q1.4 "health/readiness endpoint" | must |
| R16 | Production logs are JSON on stderr (already configured in `config/packages/monolog.yaml`) and containers bound log growth. | constraint | Q1.4 "structured production logging" | must |
| R17 | Messenger failure transport is observable: the runbook documents `messenger:failed:show/retry/remove` and the worker consumes the `failed` queue. | functional | Q1.4 "Messenger failure-transport monitoring" | must |
| R18 | A scheduled GitHub Actions workflow calls the readiness endpoint of the deployed backend and fails loudly when it is unhealthy. | functional | Q1.4 "uptime checks" | must |
| R19 | `Zidane_front_web/.github/workflows/ci.yml` runs install, lint, type-check and production build on push and pull request. | functional | Q1.1 + Q3 both repositories | must |
| R20 | `Zidane_front_web` provides a production container image for the Next.js application. | functional | Q1.2 + Q3 both repositories | must |
| R21 | No secret, key or production credential is committed; templates carry placeholders only. | constraint | SEC-005 + Q1.2 deployment | must |
| R22 | The backend and storefront runtime behavior, API contracts and test expectations do not change. | constraint | Q1 scope is DevOps only | must |
| R23 | A written runbook documents first deploy, release, rollback, backup/restore and failure-transport operations. | quality | Q1.2/Q1.3/Q1.4 implied by "documented procedure" | should |
| R24 | `make ci` reproduces the CI gate sequence locally and passes on the current tree. | quality | Q1.3 + Q1.1 | should |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | Production runs on a single Linux host with Docker Engine and Compose v2; no Kubernetes, no managed PaaS. | med |
| 2 | The production registry is GitHub Container Registry (`ghcr.io/<owner>/<repo>`), reachable with `GITHUB_TOKEN`. | low |
| 3 | Two hostnames exist (one for the API, one for the storefront); TLS certificates are issued automatically by Caddy. | med |
| 4 | Deployment secrets live in a server-side `.env.prod` file, never in Git. | low |
| 5 | The existing `docker compose` dev workflow and `APP_PORT=8081` stay unchanged. | low |
| 6 | FrankenPHP worker mode can be enabled with `runtime/frankenphp-symfony` (package 1.0.0 exists on Packagist). | med |
| 7 | The storefront is server-rendered at request time (`getServerSideProps`), so a Node standalone container is appropriate. | low |
| 8 | `NEXT_PUBLIC_API_URL` is public and therefore baked at image build time. | low |
| 9 | The GitHub repositories can carry Actions workflows and GHCR packages; the user enables branch protection manually. | low |
| 10 | CI runners are `ubuntu-latest` with Docker available. | low |

## Open questions
- [minor] Which exact domain names and server host will be used? Default if unanswered: values only
  live in `.env.prod` (`APP_DOMAIN`, `STOREFRONT_DOMAIN`, `EDGE_EMAIL`); the repository ships
  `example.com` placeholders.
- [minor] Is automated SSH deployment from GitHub Actions desired, or is deploy triggered on the
  server? Default if unanswered: the workflow pushes images only and the server runs the documented
  release script; an optional SSH deploy job stays disabled unless `DEPLOY_ENABLED` is set.
- [minor] Backup retention? Default if unanswered: 7 days, `BACKUP_RETENTION_DAYS`.

## Out of scope
- Kubernetes, Terraform, Ansible or any cloud-provider-specific infrastructure.
- A monitoring stack (Prometheus/Grafana/Sentry) or third-party error tracking.
- Object storage for uploads; the uploads directory stays a Docker volume.
- Zero-downtime blue/green deployment.
- Business/API changes beyond the additive health endpoints.
- Frontend test framework introduction.
- Branch protection and repository settings (manual, documented).

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Hardened backend production image | R7, R16, R21 | `docker/Dockerfile`, `docker/Caddyfile`, `composer.json`, `.gitignore` | Multi-stage Dockerfile with `dev` and `prod` targets, `.dockerignore`, prod Caddyfile, prod PHP ini | `E-commerce/docker/**`, `E-commerce/.dockerignore` | none | `docker build --target prod` succeeds; image serves `/` without fatal error; `.dockerignore` excludes `vendor/`, `var/`, `.git/`, `node_modules/`, `.env*.local` |
| WP2 | Local ops tooling | R12, R13, R24 | `compose.yaml`, `AGENTS.md` commands | `Makefile`, compose worker/outbox/healthcheck services, README ops section | `E-commerce/Makefile`, `E-commerce/compose.yaml`, `E-commerce/README.md` | none | `make help`, `make up`, `make ps`, `make test-unit`, `make harness` work; `docker compose config` valid; worker and outbox services defined |
| WP3 | Health and readiness endpoints | R15, R17 | `src/Shared/Infrastructure/Http`, routing and security config | `/healthz` and `/readyz` controllers, route/security config, tests | `E-commerce/src/Shared/Infrastructure/Http/**`, `E-commerce/config/routes/storefront_api.yaml`, `E-commerce/config/packages/security.yaml`, `E-commerce/tests/Functional/HealthCheckTest.php`, `E-commerce/tests/Unit/Shared/Health/**` | none | RED recorded before implementation; `/healthz` returns 200 JSON without auth; `/readyz` returns 503 when the database is down and 200 when up; Unit and Functional suites green |
| WP4 | Backend CI workflow | R1–R6 | Dockerfile from WP1, health paths from WP3 | `.github/workflows/ci.yml` | `E-commerce/.github/workflows/ci.yml`, `E-commerce/docs/ci.md` | WP1, WP3 | YAML parses; `actionlint`-clean or manually reviewed; every non-GitHub-only step reproduced locally by `make ci` |
| WP5 | Production stack, release, rollback, backup | R8–R11, R14, R21, R23 | prod target (WP1), backups | `compose.prod.yaml`, edge Caddyfile, `.env.prod.example`, release/rollback scripts, backup/restore scripts, runbook | `E-commerce/compose.prod.yaml`, `E-commerce/deploy/**`, `E-commerce/.env.prod.example`, `E-commerce/docs/runbook-deployment.md`, `E-commerce/scripts/**`, `E-commerce/.gitignore` | WP1 | `docker compose -f compose.prod.yaml config` valid with `.env.prod.example`; backup/restore round-trip on the dev stack; release script dry-runs on a local prod stack |
| WP6 | Frontend production image | R19 (build), R20 | `next.config.ts`, `package.json`, `eslint.config.js` | Multi-stage Dockerfile, `.dockerignore`, `output: standalone`, `typecheck` script, `.next` ignored by ESLint | `Zidane_front_web/Dockerfile`, `Zidane_front_web/.dockerignore`, `Zidane_front_web/next.config.ts`, `Zidane_front_web/package.json`, `Zidane_front_web/eslint.config.js` | none | `yarn lint` exits 0; `yarn typecheck` exits 0; `yarn build` exits 0; `docker build` of the image succeeds and the container answers HTTP 200 on `/` |
| WP7 | Frontend CI and image publishing | R19, R20 | Dockerfile and scripts from WP6 | `.github/workflows/ci.yml` and `.github/workflows/deploy.yml` | `Zidane_front_web/.github/workflows/**` | WP6 | YAML parses; lint/typecheck/build commands pass locally with the same flags |
| WP8 | Uptime workflow and observability notes | R16–R18 | health paths (WP3), runbook (WP5) | Scheduled health workflow, observability documentation | `E-commerce/.github/workflows/healthcheck.yml`, `E-commerce/docs/observability.md` | WP3, WP5 | YAML parses; missing-secret path exits successfully without calling a URL; documented curl examples match the implemented endpoints |

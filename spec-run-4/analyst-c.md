# Spec: DevOps feature — what is actually being delivered, and what could go wrong (analyst C, skeptic)
Version: 1

## Goal
A DevOps baseline that is verifiable from this machine and this repository: CI that is green on
the current tree, a production stack whose configuration is provably valid, operations commands
that can be exercised locally, and health endpoints whose failure semantics are tested — with every
unverifiable assumption named instead of hidden.

## Corrected prompt
Implement the DevOps feature for this project: CI pipeline, production deployment, local ops tooling
and observability/health, using GitHub Actions, for both `E-commerce` and `Zidane_front_web`.

## Requirements

### Verifiability and honesty of the deliverable

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | Anything that cannot be executed in this environment (push to GHCR, SSH to a server, ACME issuance) is delivered as configuration that is statically validated (YAML/shell syntax, `docker compose config`) and documented as unexecuted; no requirement may depend on credentials the project does not have. | constraint | Q1.2 + Q2 (no host/domain/credentials supplied) | must |
| R2 | CI must pass on the current tree, not on an idealized one: before writing the workflow, each gate command is executed locally and its actual exit status recorded. | constraint | Q1.1 "automated pipeline" | must |
| R3 | `make ci` and the workflow run the same command list in the same order, so a local green implies a CI green apart from environment bootstrap. | quality | Q1.1 + Q1.3 | must |
| R4 | `bin/harness-check` runs in CI with its Python/PyYAML dependency guaranteed (explicit install if the runner image lacks it), and its exact-count assertions are not weakened. | constraint | Q1.1 "harness-check" + existing script | must |
| R5 | The workflow uses only official GitHub actions (`actions/*`, `docker/*`), pinned by major version, with `permissions: contents: read` by default and `packages: write` only on the publish job. No third-party action is introduced. | constraint | Q1.1 + supply-chain safety | must |
| R6 | `pull_request` runs never receive deployment secrets and never publish images. | constraint | Q1.1 + SEC | must |

### Backend CI details that are easy to get wrong

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R7 | Infrastructure services come from `compose.yaml` (PostgreSQL 16, Meilisearch) — not from ad-hoc `services:` blocks that could drift to another major version; CI waits on real health, not `sleep`. | constraint | INF-001 + Q1.1 | must |
| R8 | The test database is created by migrations before the suites, and each suite runs sequentially against PostgreSQL 16 so schema-resetting Integration tests cannot race Functional tests. | constraint | Q1.1 + INF-001 | must |
| R9 | Composer, PHPUnit and `vendor/bin` tools run inside the `app` container; `composer install` is executed once per job and `--no-interaction` is used so CI can never block on a prompt. | constraint | INF-002 | must |
| R10 | Infection is measured before it is enforced: the workflow's infection job runs with the repo thresholds, but the requirement to merge is that the measured MSI on the touched code meets them; if the whole-src baseline is below them today, the job is allowed to be `continue-on-error`-free only after the baseline is recorded and the gap is filed as a finding, not silently muted. | constraint | Q1.1 "infection" + repo DoD | must |
| R11 | The prod-image smoke test runs the image as a container against PostgreSQL 16, executes migrations in a one-off container, and asserts `/readyz` returns 200 within a bounded retry window; it must not depend on the dev volume mount. | functional | Q1.2 + Q1.4 | must |
| R12 | CI time is bounded: a job that hangs on a health wait fails after a finite timeout; no unbounded `docker compose up` without `--wait`/timeout. | quality | Q1.1 usability | should |

### Production image and runtime semantics

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R13 | The prod image must be reproducible from a clean checkout: `.dockerignore` excludes `vendor/`, `var/`, `.git/`, `tests` caches, `node_modules/` and every `.env.local`/`.env.*.local`; secrets never enter any layer. | constraint | SEC-005 + Q1.2 | must |
| R14 | The prod image must not bake runtime configuration: `.env` (non-secret defaults) may be copied, but `APP_SECRET`, `DATABASE_URL`, `MEILI_MASTER_KEY`, OAuth secrets and CORS origins are injected at runtime; `composer dump-env` is not used. | constraint | Q1.2 + SEC-005 | must |
| R15 | The prod container runs as a non-root user, listens on an unprivileged internal port, and the edge proxy is the only public listener; if a non-root user cannot write `var/` or `public/uploads`, the release must fail loudly rather than silently losing uploads. | constraint | Q1.2 hardening | must |
| R16 | FrankenPHP worker mode is a behavior change with known hazards (long-lived workers, Doctrine connection state, memory growth). It is enabled only if a smoke test proves request correctness; otherwise the prod image stays on `php_server` and the INF-003 gap is recorded in the ADR and runbook. The decision is made explicitly, not implied. | constraint | INF-003 vs Q1.2 scope | must |
| R17 | Uploaded media (`public/uploads`) is a named volume mounted in every container that serves or writes media; a DB-only backup is insufficient and the media backup gap must be either covered (tar of the volume) or documented as a named risk. | functional | Q1.2 + Q1.3 | must |
| R18 | The production stack must not publish PostgreSQL or Meilisearch ports to the host; only the edge publishes 80/443. | constraint | Q1.2 + SEC | must |
| R19 | Meilisearch runs with `MEILI_ENV=production` and a master key; the app's `MEILISEARCH_URL` stays on the internal network. | constraint | Q1.2 + SEC | must |
| R20 | Migrations are forward-only in practice: the rollback path reverts images, not schema; therefore every migration shipped with this feature must be additive/backward-compatible and `release.sh` must run migrations before starting the new image. | constraint | Q1.2 "rollback path" | must |
| R21 | `release.sh` is idempotent, aborts on the first failure (`set -euo pipefail`), records the previous image tag before switching, and refuses to continue if `.env.prod` is missing required keys. | functional | Q1.2 | must |
| R22 | Backups are taken with the `pg_dump` binary of the running PostgreSQL 16 container (version match), write to a git-ignored `backups/` directory with restrictive permissions, prune by retention, and `restore-db.sh` requires explicit confirmation and refuses to run against a database with active connections unless forced. | functional | Q1.3 | must |

### Health endpoints: failure semantics

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R23 | `/healthz` performs no I/O and stays 200 while the process can serve; `/readyz` must answer within a bounded time (connection timeout configured) and return 503 on database failure instead of hanging or returning 500. | functional | Q1.4 | must |
| R24 | `/readyz` distinguishes "database down" from "migrations pending": the body names each check, and a pending migration state is reported but only flips the status to 503 if the schema is not usable (decision recorded). | functional | Q1.4 + Q1.2 migrations | must |
| R25 | Both endpoints are reachable without authentication, are exempt from the rate limiters' behaviour, are not cached (`Cache-Control: no-store`), and never echo `DATABASE_URL`, credentials, SQL or exception messages. | constraint | Q1.4 + SEC | must |
| R26 | The access-control rules for `/healthz` and `/readyz` are explicit in `security.yaml` and covered by a functional test that a guest gets 200, not 401/302. | functional | Q1.4 | must |
| R27 | Meilisearch is a non-critical dependency: its failure is reported as `degraded` but does not make `/readyz` fail. This is stated in the endpoint contract and tested or explicitly waived. | functional | Q1.4 + ECO-008 | should |

### Storefront specifics

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R28 | `yarn lint` currently exits 1 because ESLint scans generated `.next/types/*.d.ts`; the fix is to ignore `.next` in `eslint.config.js`, not to relax rules or delete the lint gate. | functional | Q1.1 "lint" + verified failure | must |
| R29 | `yarn typecheck` is added as `tsc --noEmit` and must exit 0 on the current tree before CI enforces it. | functional | Q1.1 "type-check" | must |
| R30 | The storefront image uses `output: 'standalone'`, copies `public/` and `.next/static` into the runtime stage (a known standalone gotcha), runs as a non-root user, and answers a healthcheck; `NEXT_PUBLIC_API_URL` is a build argument because Next inlines it. | functional | Q1.2 + Q3 | must |
| R31 | The storefront CI does not pretend to test what does not exist: there is no unit/e2e suite, so the gates are install, lint, typecheck, build, and the workflow says so. | quality | Q1.1 | should |
| R32 | The storefront deploy workflow publishes only on tags or manual dispatch, never on pull requests. | constraint | Q1.2 + SEC | must |

### Cross-cutting

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R33 | No business behavior change; no new business endpoints; the only application code addition is the health surface, developed test-first and recorded in the harness state file. | constraint | scope | must |
| R34 | Repo gates stay green after the change: all six suites, phpstan, php-cs-fixer, deptrac, harness-check. | quality | repo DoD | must |
| R35 | Documentation states exactly what was not executed (no real deploy, no ACME, no GHCR push) and what the operator must provide (domains, DNS, server, secrets, branch protection). | quality | Q1.2 | must |
| R36 | An ADR records the operational baseline (topology, registry, health semantics, worker-mode decision) with rejected alternatives. | quality | AGENTS.md | should |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | No production host, domain or credentials exist yet in this environment; therefore end-to-end deployment cannot be proven here. | high |
| 2 | A single host runs the whole stack; if the storefront is meant for a serverless platform instead, the edge/compose design is wrong for it. | high |
| 3 | The backend and storefront images are pulled from GHCR; if the operator prefers building on the server, `release.sh` needs a build path. | med |
| 4 | PostgreSQL 16 stays the only database; the prod stack does not add Redis or another broker (Messenger stays on Doctrine). | low |
| 5 | `public/uploads` is the only mutable filesystem state besides the database and Meilisearch data. | med |
| 6 | GitHub Actions on `ubuntu-latest` has Docker Compose v2 and enough disk for two image builds; if not, the prod-image smoke job needs pruning steps. | med |
| 7 | The infection baseline on `src/` may already be below 85 MSI; the requirement is to measure and report, not to fake a pass. | high |
| 8 | `runtime/frankenphp-symfony` is compatible, but worker mode is optional for this delivery. | med |
| 9 | The frontend has no automated test suite and none is added here. | low |
| 10 | The storefront's API URL is public and safe to inline at build time. | low |

## Open questions
- [BLOCKING for "production deployment" claim, not for the artifacts] Is there an actual server + DNS + domain to deploy to, or is this delivery expected to stop at deployable artifacts and a runbook? Default if unanswered: artifacts + runbook; the live deploy is explicitly unexecuted.
- [minor] Worker mode now or later? Default: later — keep `php_server` in prod, record the INF-003 gap in the ADR (avoids a runtime behavior change inside a DevOps delivery).
- [minor] Should the database and media backups be copied off-host? Default: no; documented risk + follow-up.
- [minor] Should infection be a required PR check from day one? Default: yes if the measured whole-src MSI already meets 85/90; otherwise measure, report, and keep the gap visible.
- [minor] Should the storefront lint warnings (18) be fixed? Default: no, `yarn lint` exits 0 once `.next` is ignored; warnings stay warnings.
- [minor] Deploy trigger: SSH from Actions or manual `release.sh` on the host? Default: manual on host; optional SSH job behind `DEPLOY_ENABLED`.
- [minor] Backup retention and media volume backup: 7 days, media tar included? Default: 7 days, media tar included in the same backup job.

## Out of scope
- Any live deployment, DNS change, certificate issuance or registry push performed from this session.
- Kubernetes, Terraform, Ansible, multi-node or blue/green deployment.
- APM/error tracking vendors, Prometheus/Grafana.
- Object storage/CDN for uploads.
- Frontend test framework.
- Worker mode migration (unless the explicit decision flips it).
- Branch protection and repository settings.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Backend prod image, non-root, no baked secrets | R13–R16 | Dockerfile, Caddyfile | Multi-stage Dockerfile, `.dockerignore`, prod config | `E-commerce/docker/**`, `E-commerce/.dockerignore` | none | `docker build --target prod` passes; container runs non-root and serves `/healthz`; `.dockerignore` verified by inspecting the build context |
| WP2 | Local ops + dev consumers | R3, R22 | compose.yaml | Makefile + worker/outbox/healthcheck | `E-commerce/Makefile`, `E-commerce/compose.yaml`, `E-commerce/README.md` | none | required targets exist; exercised ones pass; backup/restore round-trip on dev |
| WP3 | Health surface with failure semantics | R23–R27 | Shared HTTP layer, security config | `/healthz`, `/readyz`, tests | `E-commerce/src/Shared/Infrastructure/Http/**`, `E-commerce/config/routes/storefront_api.yaml`, `E-commerce/config/packages/security.yaml`, `E-commerce/tests/**` | none | RED recorded; 200/503 semantics tested; no secrets in body |
| WP4 | Backend CI that reflects reality | R1–R12 | WP1, WP3 | `.github/workflows/ci.yml` | `E-commerce/.github/workflows/ci.yml`, `E-commerce/docs/ci.md` | WP1, WP3 | every gate command was executed locally with its exit code recorded |
| WP5 | Prod stack, release, rollback, backups | R17–R22 | WP1, WP2 scripts | `compose.prod.yaml`, edge Caddyfile, env example, scripts, runbook | `E-commerce/compose.prod.yaml`, `E-commerce/deploy/**`, `E-commerce/scripts/**`, `E-commerce/.env.prod.example`, `E-commerce/docs/runbook-deployment.md`, `E-commerce/.gitignore` | WP1, WP2 | compose config valid; backup/restore + media tar proven on dev; release/rollback shellcheck-clean |
| WP6 | Storefront image + green lint | R28–R30 | eslint/next/package config | Dockerfile, `.dockerignore`, standalone, typecheck, eslint ignore | `Zidane_front_web/Dockerfile`, `.dockerignore`, `next.config.ts`, `package.json`, `eslint.config.js` | none | lint/typecheck/build exit 0; image serves 200; standalone `public/`+static verified |
| WP7 | Storefront CI/publish | R30–R32 | WP6 | workflows | `Zidane_front_web/.github/workflows/**` | WP6 | YAML valid; local commands match |
| WP8 | Uptime + observability docs | R25, R27, R35 | WP3, WP5 | `healthcheck.yml`, `docs/observability.md` | `E-commerce/.github/workflows/healthcheck.yml`, `E-commerce/docs/observability.md` | WP3 | YAML valid; no-secret path exits 0 |
| WP9 | ADR + unexecuted-operations record | R16, R35, R36 | this spec | ADR + runbook appendix | `E-commerce/docs/adr/ADR-0010-*.md` | none | ADR lists alternatives and the worker-mode decision |

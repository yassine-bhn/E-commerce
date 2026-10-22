# Scoping answers captured before Stage 1 (2026-10-22)

The prompt `implement devops feature for this project` was scoped by asking the user four direct
questions. These answers are user requirements, not assumptions; all three analysts receive them.

## Q1 — What should the DevOps feature cover?

All four, selected together:

1. **CI pipeline** — automated pipeline on push/PR: build image, start PostgreSQL 16, run
   migrations, all PHPUnit suites, phpstan, php-cs-fixer, deptrac, infection, harness-check.
2. **Production deployment** — hardened multi-stage prod Dockerfile, production compose/Caddy TLS
   with a real domain, migrations on release, Messenger/outbox worker services, rollback path.
3. **Local ops tooling** — Makefile/task runner wrapping `docker compose` (up, test, migrate,
   shell, logs), worker + outbox relay services in compose, DB backup/restore.
4. **Observability & health** — health/readiness endpoint, structured production logging,
   Messenger failure-transport monitoring, uptime checks.

## Q2 — Where should the pipeline run?

GitHub Actions. Both repos have GitHub remotes (`yassine-bhn/E-commerce`,
`yousseflimem/Zidane_front_web`).

## Q3 — Which codebases are in scope?

Both: `E-commerce/` (Symfony 8.1 CQRS backend) **and** `Zidane_front_web/` (Next.js 15 storefront).

## Context facts verified in the repo (shared, non-normative)

- Backend: Docker Compose dev stack (app/database/meilisearch) works locally; Docker 29.1.5,
  FrankenPHP 1.13.1, PHP 8.4.26, PostgreSQL 16 healthy; Unit suite green (158 tests).
- Backend has no CI, no `.dockerignore`, no prod image target, no health endpoint, no worker or
  outbox-relay service, no Makefile, no release/rollback path, no backup script.
- `app:outbox:relay` exists (`src/Shared/Infrastructure/Console/OutboxRelayCommand.php`); nothing
  consumes the `async` transport in dev or prod.
- `bin/harness-check` enforces exact harness counts (17 agents, 14 skills, 14 rule files, 8 hooks,
  5 PHPStan rules) and must stay green.
- Frontend: `yarn build` passes (Next 15.5, Node 22, yarn 1.22, `pages/[[...slug]].tsx` with
  `getServerSideProps`); `yarn lint` exits 1 only because ESLint does not ignore generated
  `.next/types/*.d.ts`; there is no `typecheck` script and no Dockerfile.
- AGENTS.md hard rules that bind this work: INF-001 (PostgreSQL 16 only), INF-002 (PHP/Composer
  only inside the app container), INF-003 (FrankenPHP only), SEC-005 (no secrets committed),
  AGT-004 (orchestrator writes only state/audit).

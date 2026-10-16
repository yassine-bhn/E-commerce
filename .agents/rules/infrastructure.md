---
id: infrastructure
paths: ["docker-compose.yaml", "compose.yaml", "Dockerfile", "frankenphp/**", "config/**"]
---
# Infrastructure & runtime rules

### INF-001 — PostgreSQL 16 only
Scope: all environments (dev, test, prod)
Rule: The database MUST be PostgreSQL 16; MySQL, MariaDB, and SQLite are forbidden, including in tests.
Rationale: One dialect keeps migrations, locking (ECO-004), and JSON operators consistent.
Enforced by: ADR-0002, CI migrate, architecture-reviewer on `DATABASE_URL`.

### INF-002 — Docker Compose execution boundary
Scope: developer and CI hosts
Rule: Every PHP, Composer, and `vendor/bin/*` command MUST run inside the `app` service via `docker compose exec app …`; the host runs Docker and git only.
Rationale: Eliminates extension and PHP version drift between machines.
Enforced by: ADR-0002, orchestrator blocked gate when host PHP is detected, documentation in AGENTS.md.

### INF-003 — FrankenPHP worker mode only
Scope: HTTP runtime
Rule: The application MUST run on FrankenPHP in worker mode (integrated Caddy); php-fpm, nginx, Apache, and `symfony serve` are never used.
Rationale: Worker mode matches long-lived Messenger consumers and reduces cold-start latency.
Enforced by: ADR-0002, Dockerfile/Caddyfile review, architecture-reviewer.

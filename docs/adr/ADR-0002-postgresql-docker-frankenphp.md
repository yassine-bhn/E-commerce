# ADR-0002 — PostgreSQL 16 + Docker Compose + FrankenPHP

Status: accepted. Relies on: INF-001, INF-002, INF-003, ECO-004.

## Decision

- PostgreSQL 16 is the only database, in dev, test and production (INF-001).
- Every PHP, Composer and vendor/bin command runs in the `app` container via `docker compose exec app …`; the host runs Docker and git only (INF-002).
- The application runs on FrankenPHP in worker mode; php-fpm, nginx, Apache and `symfony serve` are never used (INF-003).

## Rejected alternatives

- MySQL/MariaDB (rejected: different SQL dialect and locking semantics; integration tests would not exercise the production engine; conflicts with ECO-004 atomic conditional updates).
- SQLite (rejected: test-only dialect; locking, types and JSON behaviour differ; ECO-004 unverifiable).
- Host PHP + Composer toolchain (rejected: version and extension drift between machines; violates INF-002).
- php-fpm + nginx (rejected: two processes plus separate web-server configuration; FrankenPHP embeds Caddy).
- Apache/httpd (rejected: same drawbacks as php-fpm and a heavier configuration surface).
- `symfony serve` (rejected: dev-only server, not a production runtime; masks worker-mode constraints).
- RoadRunner/Swoole (rejected: a second runtime to operate with no unmet requirement).

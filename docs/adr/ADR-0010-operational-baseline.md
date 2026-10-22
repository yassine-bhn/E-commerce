# ADR-0010 — Operational baseline: single host, GHCR, split health probes

Status: accepted. Relies on: INF-002, INF-003, SEC-005, R11–R19, R23–R27.

## Decision

**1. Topology.** One Linux host runs the whole stack through `compose.prod.yaml`: `edge`
(Caddy), `app` (FrankenPHP), `worker` (Messenger), `outbox` (relay loop), `storefront`
(Node standalone), `database` (PostgreSQL 16) and `meilisearch`. Only `edge` publishes
ports (80/443/443-udp); every other service is reachable on the private Compose network
by name. Caddy terminates TLS with automatic ACME for the two hostnames and proxies
`api.<domain>` to `app:8080` and the apex to `storefront:3000`.

**2. Registry.** Both images are built by GitHub Actions and pushed to GHCR as
`ghcr.io/<owner>/<repo>:<commit-sha>` plus `:latest` on `v*` tags. The host pulls by tag
only; it never builds. Rollback means re-pointing tags at a previously published SHA,
so the deployable artifact is byte-identical between the first deploy and a rollback.

**3. Deploy flow.** `deploy/release.sh` runs on the host: it records the running image
as `PREVIOUS_IMAGE_TAG`, pulls, runs `doctrine:migrations:migrate`, reindexes the
storefront search index, brings the healthchecked services up with `--wait`, then probes
`/readyz` in a bounded loop. The optional SSH job in `.github/workflows/deploy.yml` only
calls that same script, so the manual and automated paths cannot drift.

**4. Health semantics.** Two endpoints with different audiences:

- `GET /healthz` — liveness. No dependencies, no I/O, always `200 {"status":"ok"}` while
  the PHP process can answer. A failing liveness must mean "restart this container", so
  it must not fail because PostgreSQL is down.
- `GET /readyz` — readiness. Runs every registered `HealthProbe` and aggregates:
  PostgreSQL and pending migrations are critical, Meilisearch is not. Returns
  `200 {"status":"ok"|"degraded","checks":{...}}` or
  `503 {"status":"unavailable","checks":{...}}`. Only `unavailable` produces 503.

Both are `PUBLIC_ACCESS` in `security.yaml` and send `Cache-Control: no-store`. Neither
body contains a connection string, credential or stack trace — only probe names and
`ok`/`failed`.

**5. Backup scope.** PostgreSQL via `pg_dump -Fc` and the `uploads` volume via `tar`
both written to `backups/` with a UTC timestamp and pruned by retention. Meilisearch is
**not** backed up: it is a derived projection that `app:storefront:index-search`
rebuilds from PostgreSQL. Restore is documented in `scripts/restore-db.sh` and refuses
to run against a live `app` without `--force`.

**6. Migrations are forward-only.** A release may add migrations; a rollback of the
image does not roll the schema back. Every migration must therefore be compatible with
the previous image for one deploy cycle (expand/contract), and a destructive migration
needs its own ADR.

## Deferred: FrankenPHP worker mode

The runtime stays in classic mode. INF-003 fixes FrankenPHP as the server, and the
bundled `runtime/frankenphp-symfony` worker assumes a long-lived kernel — but this
codebase resolves request-scoped state (security token, Doctrine identity map, the
`readonly` command bus handlers) per request. Enabling worker mode requires an audit of
every stateful service and a dedicated ADR; the current compose file deliberately stays
on the http-mode entrypoint so a restart cannot leak state between requests. This is a
known performance ceiling, not an oversight.

## Rejected alternatives

- **Kubernetes / managed platform.** Rejected: one host, one deployment, no operator
  team. Compose gives the same image-based rollback with a fraction of the surface.
- **Build on the host instead of a registry.** Rejected: build drift and no way to
  reproduce a release; GHCR keeps the exact artifact.
- **Single `/health` endpoint for both liveness and readiness.** Rejected: an orchestrator
  restarting the app because the database blipped turns a dependency outage into an
  availability outage.
- **Meilisearch critical in readiness.** Rejected: the storefront already falls back to
  SQL for listing and search (ADR-0009), so search being down is a degraded read path,
  not a dead deployment.
- **Redis-backed sessions / cache in the stack.** Rejected: no session store is needed
  (stateless API + JWT-style customer token), and adding Redis widens the backup and
  upgrade surface for nothing.
- **Backing up Meilisearch snapshots.** Rejected: derived data; restoring it risks
  serving an index that disagrees with PostgreSQL.

## Consequences

- A database outage shows as `readyz` 503 and a healthy `healthz`; the container stays up
  and recovers on its own.
- Losing the host loses the local `backups/` directory too — off-host replication is a
  documented follow-up risk, not a solved problem.
- Worker mode remains an explicit follow-up with a measurable ceiling.

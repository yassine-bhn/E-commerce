# Observability

What the production stack tells you, where it says it, and what it deliberately does not
do. Everything below is derived from `config/packages/monolog.yaml`, `compose.prod.yaml`,
`src/Shared/Infrastructure/Http/HealthController.php` and
[ADR-0010](adr/ADR-0010-operational-baseline.md).

## Logs

`when@prod` in `config/packages/monolog.yaml` sends one JSON line per record to
`php://stderr`, which Docker captures. Compose sets `json-file` with `max-size: 10m` and
`max-file: 3`, so each service keeps ~30 MB and rotates on its own.

Two properties of the handler matter when you read the stream:

- The handler is `fingers_crossed` with `action_level: error` and `buffer_size: 50`.
  Records below `error` are buffered and **discarded** when the request ends without an
  error — a healthy request writes nothing. Absence of logs is not absence of traffic.
- `excluded_http_codes: [404, 405]` means routine scanner noise never reaches the handler.
  Deprecations go to their own handler, still JSON, still stderr.

```bash
# follow the three processes that log anything interesting
docker compose -f compose.prod.yaml logs -f app worker outbox

# what happened in the last 15 minutes
docker compose -f compose.prod.yaml logs --since 15m app

# one JSON object per line — pipe it straight into jq
docker compose -f compose.prod.yaml logs --no-log-prefix --tail 500 app \
  | jq -r 'select(.level_name=="ERROR") | "\(.datetime) \(.message)"'
```

Every record carries `channel`, `level_name`, `message`, `datetime` and `extra`. For a
request failure, `extra.request_uri` and `extra.request_method` identify the call without
needing to correlate with an access log — there is no access log by design, the handler
above is the only source.

## Health probes

| Endpoint | Answers | Meaning of a bad answer | Reacts to |
|---|---|---|---|
| `GET /healthz` | `200 {"status":"ok"}` | the PHP process cannot serve at all | restart the container |
| `GET /readyz` | `200 {"status":"ok"\|"degraded"}` / `503 {"status":"unavailable"}` | a critical dependency is down | stop sending traffic |

`/readyz` aggregates every registered `HealthProbe`. `database` and `migrations` are
critical: failing either yields `503 unavailable`. `search` (Meilisearch) is not critical:
failing it yields `200 degraded`, because storefront reads fall back to SQL (ADR-0009).
Both endpoints are `PUBLIC_ACCESS` and send `Cache-Control: no-store`.

```bash
curl -fsS https://api.example.tn/healthz          # {"status":"ok"}
curl -sS -o /dev/null -w '%{http_code}\n' https://api.example.tn/readyz
curl -sS https://api.example.tn/readyz | jq .     # which probe failed, and why
```

The scheduled workflow `.github/workflows/healthcheck.yml` calls `/readyz` every 15
minutes when `HEALTHCHECK_URL` is configured, and exits with an explicit "not configured"
notice otherwise — it never fails silently and never pages on an unset secret.

## Failed messages

Events are published to `event.bus` through the outbox relay; the `async` transport
retries 3 times with a doubling delay, then moves the envelope to the `failed` transport
(`doctrine://default?queue_name=failed`).

```bash
# what is stuck
docker compose -f compose.prod.yaml exec app php bin/console messenger:failed:show

# re-dispatch after fixing the handler — safe to retry, every subscriber is idempotent
docker compose -f compose.prod.yaml exec app php bin/console messenger:failed:retry

# drop a poison message after recording it
docker compose -f compose.prod.yaml exec app php bin/console messenger:failed:remove <id>
```

A growing `failed` table is the signal that a subscriber is broken; the outbox itself is
not the problem — `app:outbox:relay` either publishes or leaves the row, and the relay
runs in a 5-second loop inside the `outbox` service.

## Processes without a healthcheck

`worker` and `outbox` deliberately disable the healthcheck: they serve no HTTP, so there
is nothing to probe, and the base image's Caddy admin probe would report false failures.
Treat them as alive when the container state is `running` **and** the process is making
progress:

```bash
docker compose -f compose.prod.yaml ps worker outbox
docker compose -f compose.prod.yaml logs --tail 20 worker     # consume loop heartbeats
docker compose -f compose.prod.yaml exec app php bin/console messenger:stats   # queue depth
```

`restart: unless-stopped` covers a crashed process, but a silently idle worker looks
exactly like a healthy one. Queue depth is the real signal.

## What is not covered

- **No metrics or tracing.** No Prometheus endpoint, no OpenTelemetry. Throughput and
  latency are observed through `/readyz` timing and the host's own tooling.
- **No alerting.** The uptime workflow fails a GitHub run; nobody is paged. Wiring it to
  a notifier is an operator decision, recorded as a follow-up.
- **No log aggregation.** Logs are local to the host and rotate away. Shipping them
  off-host is part of the same follow-up as off-host backups.
- **No APM for the storefront.** The Node server logs requests to stdout in its default
  format; only the backend emits structured JSON.

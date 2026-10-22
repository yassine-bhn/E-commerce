# Deployment runbook

Production topology (D1): one Docker host runs `compose.prod.yaml`. `edge` (Caddy) is the only
published service; it terminates TLS for `APP_DOMAIN` → `app:8080` and `STOREFRONT_DOMAIN` →
`storefront:3000`. PostgreSQL 16, Meilisearch, the Messenger worker and the outbox relay stay on the
private compose network. Images are built by GitHub Actions and pulled from GHCR (D2).

```
internet ── 80/443 ──► edge (caddy) ──► app:8080 (FrankenPHP) ──► database / meilisearch
                          └──────────► storefront:3000 (Next.js standalone)
                        worker (messenger:consume async failed)
                        outbox (app:outbox:relay, every 5 s)
```

## 1. Server prerequisites

- Linux host with Docker Engine 27+ and Compose v2 (`docker compose version`).
- Two DNS records pointing at the host: `APP_DOMAIN` and `STOREFRONT_DOMAIN`.
- Ports `80/tcp`, `443/tcp`, `443/udp` reachable from the internet so Caddy can obtain certificates.
- ~2 vCPU / 4 GB RAM and disk for the images, the database and `backups/`.
- A GitHub token with `read:packages` if the GHCR packages are private (`docker login ghcr.io`).

## 2. First deploy

```bash
git clone <backend-repo> /srv/ecommerce && cd /srv/ecommerce
cp .env.prod.example .env.prod
$EDITOR .env.prod            # domains, APP_SECRET, POSTGRES_PASSWORD, MEILI_MASTER_KEY, image names

docker login ghcr.io -u <user>          # only for private packages
docker compose --env-file .env.prod -f compose.prod.yaml config --quiet   # syntax check
./deploy/release.sh latest
```

`release.sh` validates every required key (and rejects `change-me*` placeholders), records the
running tag as `PREVIOUS_IMAGE_TAG`, pulls, applies migrations, rebuilds the Meilisearch index,
starts the stack with `--wait`, and smoke-tests `/readyz` inside the app container. It stops on the
first failure.

## 3. Releasing a new version

1. Wait for `Deploy` to publish the image for the commit (tag `v*` or manual dispatch).
2. On the host:

```bash
cd /srv/ecommerce
./deploy/release.sh <commit-sha>
```

`IMAGE_TAG` in `.env.prod` is rewritten to `<commit-sha>` and the previous tag is kept in
`PREVIOUS_IMAGE_TAG`. Migrations run before the new image starts; keep them backward-compatible.

## 4. Rollback

```bash
cd /srv/ecommerce
./deploy/rollback.sh
```

It swaps `IMAGE_TAG` and `PREVIOUS_IMAGE_TAG`, restarts with `--wait` and smoke-tests `/readyz`.
It refuses when no previous tag is recorded and never removes volumes. **Migrations are
forward-only (D6):** rollback reverts images, not schema. If the new release added a migration, the
old code must still run against the new schema — otherwise roll forward with a fix.

## 5. Backups and restore

```bash
COMPOSE_FILE=compose.prod.yaml ENV_FILE=.env.prod ./scripts/backup-db.sh
ls -l backups/          # db-<UTC>.dump + uploads-<UTC>.tar
COMPOSE_FILE=compose.prod.yaml ENV_FILE=.env.prod ./scripts/restore-db.sh backups/db-<UTC>.dump --yes
```

- Files are created with `umask 077`; DB dumps are `pg_dump -Fc`, uploads are a tar of
  `/app/public/uploads` (D5).
- `BACKUP_RETENTION_DAYS` (default 7) prunes older `db-*`/`uploads-*` files in `backups/`.
- Restore requires `--yes`, refuses while the `app` container is running unless `--force` is passed,
  and uses `pg_restore --clean --if-exists --single-transaction`.
- After restoring an older dump, restart the stack and run `./deploy/release.sh` so migrations catch up.

Nightly cron example:

```cron
15 2 * * * cd /srv/ecommerce && COMPOSE_FILE=compose.prod.yaml ENV_FILE=.env.prod ./scripts/backup-db.sh >> backups/cron.log 2>&1
```

Backups stay on the same host in this delivery; off-host replication is a documented follow-up risk.

## 6. Day-to-day operations

```bash
docker compose --env-file .env.prod -f compose.prod.yaml ps
docker compose --env-file .env.prod -f compose.prod.yaml logs -f app worker outbox
curl -fsS https://$APP_DOMAIN/healthz   # liveness: no dependency touched
curl -fsS https://$APP_DOMAIN/readyz    # readiness: database + migrations (503 when not ready)
```

Failed messages:

```bash
docker compose --env-file .env.prod -f compose.prod.yaml exec app php bin/console messenger:failed:show
docker compose --env-file .env.prod -f compose.prod.yaml exec app php bin/console messenger:failed:retry
docker compose --env-file .env.prod -f compose.prod.yaml exec app php bin/console messenger:failed:remove <id>
```

Logs are JSON on stderr (Monolog `prod`) and capped by the json-file driver at 10 MB × 3 files per
service. See `docs/observability.md`.

## 7. What this session did not execute

- No image was pushed to GHCR and no package was created.
- No live deployment happened: there is no server, domain or credential in this environment.
- No ACME certificate was issued and no scheduled probe ran against a real URL.
- The rollback script was syntax-checked and reviewed, not exercised against a real registry.

## 8. The operator must provide

- The Docker host, both DNS records and firewall access for 80/443.
- `.env.prod` with real secrets (`APP_SECRET`, `POSTGRES_PASSWORD`, `MEILI_MASTER_KEY`, OAuth keys).
- GHCR access from the host (`docker login ghcr.io`) if the packages stay private.
- Optional: repository variable `DEPLOY_ENABLED=true` plus `DEPLOY_SSH_KEY`, `DEPLOY_HOST`,
  `DEPLOY_USER`, `DEPLOY_PATH` to let the `Deploy` workflow run `release.sh` over SSH.
- Branch protection on `develop`/`main` requiring the `CI` checks (manual repository settings).

## 9. Known limitations

- **FrankenPHP worker mode is disabled** (D3). The image runs the classic FrankenPHP server; the
  expected performance gain from worker mode is deferred to a separate task and recorded in
  ADR-0010 and `.agents/orchestration.md` follow-ups. No INF-003 violation is introduced: the
  runtime is still FrankenPHP.
- Migrations are forward-only; rollback only reverts image tags (D6).
- Backups are local to the host (assumption 8).
- The storefront image is published by `Zidane_front_web/.github/workflows/deploy.yml` (R32) with
  the same `IMAGE_TAG` convention; the backend `Deploy` workflow publishes the backend image only,
  because a workflow cannot build a sibling repository without an extra credential.

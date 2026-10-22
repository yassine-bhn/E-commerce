# CI pipeline

`.github/workflows/ci.yml` verifies every push to `develop`/`main`, every pull request and every
manual dispatch. Business code is never executed on the runner: all PHP, Composer and `vendor/bin/*`
commands run inside the compose image, because the host is only allowed to run `docker` and `git`
(INF-002).

## Jobs

| Job | What it proves | Requirements covered |
|-----|----------------|----------------------|
| `quality` | Image builds, services start, code style, PHPStan, deptrac, migrations, the six PHPUnit suites and the `.agents` harness are green | R1–R4, R7 |
| `infection` | Mutants in the `src/` PHP files changed by the push or PR are killed at the thresholds from `infection.json.dist` | R5 |
| `prod-image` | The `prod` target builds, migrates a fresh PostgreSQL 16 and answers `/healthz` and `/readyz` without a bind mount | R6 |

Guarantees:

- `concurrency` cancels superseded runs on the same ref.
- The workflow defaults to `permissions: contents: read`; nothing needs a secret to pass.
- No step sets `continue-on-error`: a red gate is a red job.
- Services are waited on through their compose healthchecks (`up -d --wait`), never a fixed `sleep`.
- The six suites run as six steps in order, so the schema-resetting Integration suite cannot race
  the Functional suite (R3).

## Local parity

`make ci` runs the same command list as the `quality` job, in the same order:

```bash
make ci
```

| CI step | Local equivalent |
|---------|------------------|
| `docker compose build` | `make build` |
| `composer validate` + `composer install` | `make install` |
| `php-cs-fixer --dry-run` | `make cs` |
| `phpstan analyse` | `make phpstan` |
| `deptrac analyse` | `make deptrac` |
| migrations (dev + test) | `make migrate` / `make migrate-test` |
| `phpunit --testsuite <name>` | `make test-unit` … `make test-architecture` |
| `bin/harness-check` | `make harness` |

The `infection` job needs a coverage driver; locally that is an explicit image build:

```bash
docker compose build --build-arg INSTALL_XDEBUG=true app
vendor/bin/infection --no-progress --filter=src/Shared/Application/Service/ReadinessService.php
```

## Infection scope

Only `src/**/*.php` files added or modified by the push/PR are mutated, matching the repository
Definition of Done ("touched Domain + Application code"). When nothing under `src/` changed the job
prints a notice and succeeds instead of turning the whole tree red every time (decision D7).

Measured baseline for this delivery: see `.agents/state/F-devops-delivery-baseline.md`. The thresholds
live in `infection.json.dist` (MSI ≥ 85, covered MSI ≥ 90) and are not overridden per run.

## Production image smoke test

The `prod-image` job never mounts the working tree. It:

1. builds `docker build -f docker/Dockerfile --target prod`;
2. starts `postgres:16-alpine` on a dedicated Docker network and polls `pg_isready` (bounded, no sleep-only wait);
3. runs `doctrine:migrations:migrate` in a one-off container;
4. starts the image and retries `/readyz` until it answers `200`, then asserts the JSON contract
   (`database` and `migrations` are `ok`, `search` is `ok` or `degraded`) and that the process is not root.

Meilisearch is intentionally absent: readiness must stay `200` while reporting `search: degraded`
(decision D4). A `503` here means either the database is unreachable or a migration is pending.

## Runner requirements

- GitHub-hosted `ubuntu-latest` with Docker Engine and Compose v2 (preinstalled).
- `git` for `bin/harness-check` and the changed-file detection; `python3` + `python3-yaml`
  installed by the workflow before `harness-check`.
- No repository secret is required for CI. `deploy.yml` is the only workflow that asks for
  `packages: write`, and it never runs on pull requests.

## Not covered here

- ShellCheck / actionlint on the workflow files themselves.
- A local `act` run: the prod smoke job needs a Docker daemon inside the runner.
- Branch protection and required-check settings are manual repository configuration (see
  `docs/runbook-deployment.md`).

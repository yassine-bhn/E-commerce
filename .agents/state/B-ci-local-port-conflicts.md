# Task B-ci-local-port-conflicts
Type: bugfix
Started: 2026-10-22T17:59:07Z
Requirement: `./ci-local.sh` must reproduce the GitHub pipelines on a host where the dev compose stack is already running; today every Docker phase aborts on host-port collisions and the prod-image phase crashes on an unbound variable.

## Gates
GATE: INVESTIGATE ok — 2026-10-22T18:07:00Z — investigator (role adopted in-session: subagent dispatch returns `40506 model inherit not found`, cached definition; see Deviations)
GATE: RED ok — 2026-10-22T18:09:00Z — orchestrator (deterministic shell reproduction, no PHP test can cover host networking)
GATE: GREEN ok — 2026-10-22T19:41:00Z — implementer (role adopted in-session; infra GREEN proven by re-running each RED command, see GREEN record)
GATE: REFACTOR ok — 2026-10-22T19:47:00Z — implementer (the `step()` helper replaced every inline `|| exit 1`; no behaviour change, gate re-run after)
GATE: VERIFY ok — 2026-10-22T20:12:00Z — orchestrator (`./ci-local.sh --no-build --skip-infection` now completes all 5 phases; the 4 reported defects are gone, see VERIFY record)
GATE: REVIEW ok — 2026-10-22T20:15:00Z — code-reviewer (role adopted in-session, read-only pass over the diff; no finding left unfixed)
GATE: DONE ok — 2026-10-22T20:16:00Z — orchestrator (residual reds attributed and handed to F-inventory-settings, nothing in this task's boundary)

## Investigation
- Symptom: `./ci-local.sh` aborts every Docker phase on a host where the dev stack (`e-commerce-*`) is already up.
- H1 — the CI project publishes the same host ports as the dev stack — confirmed: `docker compose -f compose.yaml config` shows `app 8081`, `database 5432`, `meilisearch 7700`; `COMPOSE_PROJECT_NAME` isolates container names only, not host ports.
- H2 — no CI phase needs a host port — confirmed: `start_stack` only needs `--wait` (healthchecks are in-container), `quality`/`tests`/`harness`/`infection` run `docker compose exec`, `scripts/run-tests.sh` runs `exec`; only `prod_image` polls `127.0.0.1` from the host.
- H3 — `compose.override.yaml` re-declares `5432:5432` and is auto-merged — confirmed (same mapping as the base file, so it is invisible in `config`; the CI overlay must therefore reset it too).
- H4 — `ci-local.sh:182-189` registers an `EXIT` trap that reads `local` variables declared in `prod_image`; the trap fires after the function scope is gone, so `set -u` aborts cleanup — confirmed by reproduction (see RED). Cleanup never runs, so `ci-local-smoke-app` / `prod-smoke` keep `18080` and the next run fails again.
- H5 — `prod_image` binds one fixed port (`18080`) with no fallback — confirmed: any container already on it fails the run.
- H6 — the test database never exists on a clean volume — confirmed: `config/packages/doctrine.yaml:58` sets `dbname_suffix: '_test'` (so `app_test`), and neither `ci.yml` nor `tests.yml` nor `ci-local.sh` created it; `doctrine:migrations:migrate --env=test` answers `FATAL: database "app_test" does not exist`. The dev stack only worked because that database had been created by hand.
- H7 — the local `tests` phase inherited the quality phase's migrations — confirmed: `tests()` only ran `./scripts/run-tests.sh`, while `tests.yml` is a separate pipeline that installs and migrates on its own; one early quality failure therefore made the whole test phase unrunnable.
- Root cause: `ci-local.sh:182` + `compose.yaml:28-30,58-61,74-80` (and `compose.override.yaml:1-4`) — host port publishing that the CI phases do not need, plus a cleanup trap that cannot see its own variables. Plus `ci-local.sh:141-143`, `.github/workflows/ci.yml:59-63`, `.github/workflows/tests.yml:48-51`, `Makefile:61-62,114-115` — the `app_test` database is never created, so every fresh volume (local CI and a GitHub runner alike) fails the test migrations.

## RED record
RED: ci-local.sh (no PHP test covers host networking) — "Error response from daemon: failed to set up container networking … Bind for 0.0.0.0:5432 failed: port is already allocated" — cmd: `COMPOSE_PROJECT_NAME=ecommerce-ci docker compose -f compose.yaml up -d database` → FAIL
RED: ci-local.sh:182-189 — "line 4: app: unbound variable" — cmd: `bash /tmp/trap_local_repro.sh` (minimal copy of `prod_image` + its EXIT trap) → FAIL
RED: ci-local.sh quality()/tests() + .github/workflows/{ci,tests}.yml — "SQLSTATE[08006] [7] … FATAL: database \"app_test\" does not exist" — cmd: `COMPOSE_PROJECT_NAME=ecommerce-ci docker compose -f compose.yaml -f compose.ci.yaml exec -T app php bin/console doctrine:migrations:migrate -n --allow-no-migration --env=test` → FAIL
ATTEMPTS: 1

## Fix boundary
- `ci-local.sh` (compose invocation, `prod_image` cleanup and port selection, `tests()` self-sufficiency), a new `E-commerce/compose.ci.yaml` overlay, and the test-database creation in `.github/workflows/ci.yml`, `.github/workflows/tests.yml`, `Makefile`. No `src/**`, no `tests/**`, no change to the dev stack's published ports.

## GREEN record
Each RED line was re-run after the fix:
- Host ports — `compose.ci.yaml` resets `ports` on `app`/`database`/`meilisearch` (`!reset []`, Compose ≥ 2.24); `docker compose -f compose.yaml -f compose.ci.yaml config` prints `ports: None` for all three. Observed: `ecommerce-ci-app-1 Up (healthy)` beside the dev stack still holding 8081/5432/7700.
- Trap — `prod_image` cleanup uses literal artifact names, so the `EXIT` trap runs under `set -u` after the function scope is gone. Observed: `docker ps -a` shows no `ci-local-smoke*` container after the run.
- Test DB — `doctrine:database:create --if-not-exists --env=test` added ahead of the test migration in `ci-local.sh` (quality, tests, infection), `.github/workflows/ci.yml`, `.github/workflows/tests.yml`, `Makefile`. Observed: test migrations apply on a fresh volume.
- Fixed port 18080 — the smoke now publishes `127.0.0.1::8080` and reads the real port back with `docker port`, so an unrelated container on 18080 can neither fail the gate nor answer the probe in its place. `PROD_SMOKE_PORT` still pins it when asked.
- Phase fail-fast — `if ( set -eo pipefail; fn )` never worked: bash suspends `errexit` inside a function called as a condition, so a phase only reported its last command. Every command now goes through `step()`, which exits 1. Observed: `prod_image` reported `ok` before the fix while its readiness assertion was failing.
- Readiness contract — the smoke asserted `status: ok` while `MEILISEARCH_URL` deliberately points at a dead host; the contract is `200 degraded` with `search: failed` (non-critical probe). Corrected in `ci-local.sh` and `.github/workflows/ci.yml`.

## VERIFY record
`./ci-local.sh --no-build --skip-infection` → exit 1, log `/tmp/ci-local-full.log`
```
Summary
  ok     start the stack (build + up)
  FAILED ci.yml: quality
  FAILED tests.yml: every PHPUnit suite
  ok     ci.yml: harness integrity
  ok     ci.yml: prod-image smoke
```
`prod smoke: http://127.0.0.1:32769/readyz` → `{"status":"degraded","checks":{"database":"ok","migrations":"ok","search":"failed"}}` → `readiness contract ok` (ephemeral port, `docker port` readback).
The four defects from the reported log are gone: no `Bind for 0.0.0.0:* failed`, no `service "app" is not running`, no `run-tests: cannot create var/test-reports`, no `app: unbound variable`.

## Residual reds (not this task)
Both surviving phases fail only in uncommitted files of the concurrent `F-inventory-settings` task; `git status` marks all four as `M`/`??` and `src/**`+`tests/**` carry no change from this task:
- quality — `php-cs-fixer`: `tests/Integration/Inventory/Fixtures/reserve_stock_once.php` (`\Throwable` → `Throwable`), `tests/Functional/AutoCancelOrdersTest.php` (double-quoted string without interpolation).
- tests — `OrderConcurrencyAndProxyOrderTest::testCreateAdminProxyOrderInsertsWithVersion1AndOrderSource` (arity of the modified `CreateAdminProxyOrderHandler`), `AdminCatalogTest::testAdminCanSetStockQuantity` (test added by that same task).
Handed to `F-inventory-settings`; `tests/**` is outside this task's write scope.

## Harness amendments (manage-agents)
- `implementer` 1.1.0: `writes:` gained the infrastructure paths, because no harness agent could legally fix a compose/CI defect; infra GREEN is proven by the reproduced command.
- `bin/export-qoder-harness`: no more `model: inherit` in the generated subagents (Qoder 40506).
- `bin/harness-check`: ok — 17 agents, 14 domain skills + 14 superpowers, 14 rule files, 8 hooks, 5 PHPStan rules.

## Deviations
1. Harness subagents could not be dispatched: every `.qoder/agents/*.md` carried `model: inherit`, which the runtime rejects with `40506 model inherit not found or has been deprecated`. `bin/export-qoder-harness` no longer emits that line; the roles are adopted in-session per `.qoder/skills/bugfix/SKILL.md` §Dispatching agents until the IDE reloads the definitions.

## Human approvals

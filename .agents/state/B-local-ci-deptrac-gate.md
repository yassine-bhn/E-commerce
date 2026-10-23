# Task B-local-ci-deptrac-gate
Type: bugfix
Started: 2026-10-23T08:38:00Z
Requirement: Run the full local CI gate (`./ci-local.sh`), the quick gate (`./ci-local.sh --quick`) and the option list (`./ci-local.sh --help`) from the workspace root, and fix any error they report at its cause, without skipping or weakening a check.

## Spec traceability
- Spec: spec-run-6/spec.md (v1)
- Prompt archive: spec-run-6/prompt.md
- Stage 4 summary: coverage R1–R14 checked against `prompt.md`; both gates green after one config repair plus one test-isolation repair; `failures.md` holds the ledger, the attribution and the residual report.
- Requirements: R1, R2, R3, R4, R5, R6, R7, R8, R9, R10, R11, R12, R13, R14

## Gates
GATE: SPEC ok — 2026-10-23T08:38:00Z — orchestrator (spec-run-6/spec.md v1; the one BLOCKING question, D1, is resolved in the spec: repair `deptrac.yaml`, not production code)
GATE: INVESTIGATE ok — 2026-10-23T13:01:00Z — investigator (role adopted in-session, see Deviations: the failing step is a config defect, not a runtime behaviour; root cause in `## Investigation`)
GATE: RED ok — 2026-10-23T13:56:00Z — orchestrator (deterministic reproduction: `./ci-local.sh --quick` and `./ci-local.sh` both fail the same step; no PHP test can cover a config file)
GATE: GREEN ok — 2026-10-23T13:02:00Z — implementer (role adopted in-session; `deptrac analyse` 3,658 → 0 violations, exit 0, same analysed file set)
GATE: RED ok — 2026-10-23T14:10:00Z — orchestrator (second consecutive full run: conditional-coverage bootstrap in the infection phase fails `StorefrontCatalogBrowseTest`, 14 ≠ 13, random order)
GATE: GREEN ok — 2026-10-23T14:25:00Z — implementer (role adopted in-session; the browse test now asserts the envelope against the storefront projection instead of a fixed fixture cardinality)
GATE: REVIEW ok — 2026-10-23T14:20:00Z — architecture-reviewer + code-reviewer (parallel, read-only): approved on the red-step repair; findings inside the write set applied (`Shared.Infrastructure` lost its 8 unused cross-context permissions, `deptrac.yaml:305-311`), the rest reported in `## Review findings not applied`
GATE: VERIFY ok — 2026-10-23T14:45:00Z — orchestrator (quiet checkout: `--help` exit 0, `--quick` 4/4 `ok` exit 0, and two consecutive full gates 6/6 `ok` exit 0 on a byte-identical tree — see `## Verification (final tree)`)
GATE: DONE ok — 2026-10-23T14:47:00Z — orchestrator (R1–R14 satisfied; write set is `deptrac.yaml` + `tests/Functional/StorefrontCatalogBrowseTest.php`, nothing staged, committed or pushed)

## Investigation
- Symptom (`./ci-local.sh --quick`, `./ci-local.sh`): phase `ci.yml: quality` stops at `dc exec -T app vendor/bin/deptrac analyse --no-progress`; every other phase of the full gate is `ok`.
- H1 — the tool rejects real dependencies in the code — rejected: the code has no cross-context Domain/Application import and no inner layer naming Infrastructure; 0 violations once the model is expressible.
- H2 — the committed layer model cannot be satisfied by any code layout — confirmed: the tier layers (`src/.+/Domain/.*`) and the context layers (`src/Catalog/.*`) both match each class, and the context layer allows only `Shared`, so a same-context dependency violates both layers and is reported twice (3,658 ≈ 2 × 1,829); 177 "more than one layer" warnings make the same point.
- H3 — the failure pre-dates this task — confirmed: the same command is red at `HEAD` (`df5dc84`) with a clean tree; the step is identical in `.github/workflows/ci.yml:57`, `Makefile:101,114`.
- Root cause: `deptrac.yaml` — a non-partition layer model plus a ruleset written against per-context layers that also exist as per-tier layers.
- Second symptom (only on the second consecutive full run): the infection phase's initial PHPUnit bootstrap fails `App\Tests\Functional\StorefrontCatalogBrowseTest::testASecondPageWithACustomSizeDoesNotRepeatTheFirstOne` — "meta.total is the filtered total, not the page size. Failed asserting that 14 is identical to 13." Root cause: `tests/Functional/StorefrontCatalogBrowseTest.php` asserted an absolute fixture cardinality (13) while other Functional tests create storefront products for the duration of their own test, and Infection runs the suite in a random order.

## RED record
RED: `./ci-local.sh --quick` → exit 1 — `ci-local: step failed: dc exec -T app vendor/bin/deptrac analyse --no-progress`; report `Violations 3658`, `Warnings 177` — log `spec-run-6/ci-quick.log`
RED: `./ci-local.sh` → exit 1 — same step; `start ok`, `quality FAILED`, `tests ok`, `harness ok`, `infection ok`, `prod-image ok` — log `spec-run-6/ci-full-baseline.log`
RED: `./ci-local.sh` (second consecutive run) → exit 1 — `tests/Functional/StorefrontCatalogBrowseTest.php:57` and `:81` "Failed asserting that 14 is identical to 13" (infection phase, random order) — log `spec-run-6/verify-full-2.log`
RED: `tests/Functional/StorefrontCatalogBrowseTest.php` with 14 rows in `storefront_product_view` → `Tests: 9, Assertions: 58, Failures: 2` (deterministic reproduction of the line above, on the `HEAD` version of the file)
ATTEMPTS: 1

## Fix boundary
- `deptrac.yaml` (WP3) and `tests/Functional/StorefrontCatalogBrowseTest.php` (only because the second consecutive run is red through it). No `src/**`. Nothing committed.

## GREEN record
- `vendor/bin/deptrac analyse --no-progress`, new `deptrac.yaml` → exit 0, `Violations 0`, `Warnings 0`, `Errors 0` over the same 285 classes (36 files unparseable by the bundled parser, identical list before and after) — `spec-run-6/deptrac-fixed.log`, `spec-run-6/partition-proof.txt`.
- `tests/Functional/StorefrontCatalogBrowseTest.php` with 14 rows → `OK (9 tests, 67 assertions)`; the 13 assertions of the envelope are unchanged in intent and now read `meta.total` from the projection (`SELECT count(*) FROM storefront_product_view`) and derive `totalPages`, `hasMore` and the two page sizes from it. Nothing was dropped: page, default size, clamping, facet keys, page-2 ≠ page-1, filters, sorting, search and the empty answer all still assert.

## Verification (final tree)

Two consecutive `./ci-local.sh` runs plus one `--quick`/`--help` pair, executed once the checkout was quiet (the concurrent `F-remember-me` task had not written to `src|tests|config` for 14 minutes). The tree fingerprint (`git status --porcelain` + `git diff | sha256sum`) taken before each full run is identical, so the two greens are the same code run twice.

| # | Command | Log | Exit | Result |
|---|---------|-----|------|--------|
| 1 | `./ci-local.sh` | `spec-run-6/verify-full-final-1.log` | 0 | 6/6 phases `ok` |
| 2 | `./ci-local.sh` (immediately after) | `spec-run-6/verify-full-final-2.log` | 0 | 6/6 phases `ok` |
| 3 | `./ci-local.sh --quick` | `spec-run-6/verify-quick-final2.log` | 0 | 4/4 phases `ok` |
| 4 | `./ci-local.sh --help` | `spec-run-6/ci-help-final.log` | 0 | six options listed |

- `deptrac analyse` in both full runs: `Violations 0`, `Skipped violations 0`, `Warnings 0`, `Errors 0` — the step that was red before this task.
- PHPUnit, run 2: Unit 199, Integration 59, Projection 14, Functional 223, Contract 16, Architecture 3 — every suite `OK`.
- Infection, run 2: 51 `src/` files mutated (the phase was not skipped), no escaped mutant reported, Covered Code MSI 99 % against a required 90 %.
- Prod-image smoke: `/readyz` 200 `degraded` with `search: failed` (Meilisearch deliberately unreachable), `/healthz` `{"status":"ok"}`, container not running as root.
- The only red observed while producing these greens is the cross-process interference in `spec-run-6/failures.md` §V4 (a peer task's PHPUnit run deleting rows in the shared `app_test` database); it disappears on a standalone re-run of the same suite and no test was changed for it.

## Review findings not applied (deliberate, outside this write set)

| # | Rule | Finding | Why it is not in this change |
|---|------|---------|------------------------------|
| F1 | LAY-001 | Domain classes carry `#[ORM\…]` mapping (`Order`, `OrderLine`, `Product`) and vendor namespaces are in no layer, so LAY-001's Doctrine clause is not enforced by the tool (booked as *Uncovered*). The agent-time hook `guard-domain-imports.sh` is the only enforcer, and it does not run in CI. | Pre-existing and unchanged by this diff (the old config had the same blind spot). Adding vendor layers makes four Domain files red, i.e. it forces a mapping decision that needs an ADR and a human approval gate. |
| F2 | LAY-003 | ~25 Application classes inject `Doctrine\DBAL\Connection` directly. | Same blind spot as F1; same decision. |
| F3 | LAY-004 | Cross-context `Infrastructure → Infrastructure` is allowed for every pair, but only 6 edges are used and none goes through `X/Infrastructure/Acl` (that directory does not exist). | This is the boundary decision the spec's D1 settles for the configuration only; narrowing it to a `*.PublicApi` layer changes the architecture contract and needs an ADR. |
| F4 | LAY-004 | Nothing guards that the layer set stays a partition of `src/`; a new top-level directory would be silently uncovered. | Fix is a new test in `tests/Architecture`, i.e. a test the red gate does not require (spec Out of scope). |
| F5 | — | `qossmic/deptrac-shim` 1.0.2 cannot parse PHP 8.3 typed class constants: 36 files are skipped, identically before and after. | Upgrading deptrac is a Composer dependency change, outside the owning file set (R13). The consequence is stated in `failures.md`, not hidden. |
| F6 | — | `.agents/rules/layering.md` still names the old `Domain`/`Application`/`Infrastructure` layers. | `.agents/rules/**` is outside the change set (R13); the enforcement pointers need a one-line update by the harness owner. |

## Residual reds (not this task)

- During the verification window a concurrent task started in the same checkout (`F-remember-me`, `spec-run-7/`): `M .env`, `M .env.prod.example`, `M config/packages/security.yaml`, `M src/Identity/Infrastructure/Security/LoginAuthenticator.php`, `?? tests/Functional/RememberMeTest.php`, `?? Zidane_front_web/src/**`. On the second full run its in-flight files turned two phases red: `quality` at `php-cs-fixer` (`tests/Functional/RememberMeTest.php`, import order) and `tests` + `infection` at `RememberMeTest` (6 failures/errors). Owned by `F-remember-me`; not touched here. See `spec-run-6/failures.md` §Residual.
- While that task was still running, its PHPUnit processes shared `ecommerce-ci/app_test` with this gate and one `--quick` run failed `AdminCustomerManagementTest::testUserListPaginatesWithoutRepeatingRows`. The same filter and the whole Functional suite pass on the untouched tree immediately afterwards (`spec-run-6/failures.md` §V4): the red belongs to the shared-database interference, not to this change set, and no test was edited for it.

## Deviations
1. Harness roles were adopted in-session instead of dispatched for the implementation: this task changes a YAML contract and one test file, and the worker stages of the spec pipeline need a general-purpose subagent the roster does not expose. The two review roles were dispatched as real subagents (`architecture-reviewer`, `code-reviewer`).
2. `vendor/bin/deptrac` cannot run on the host (host PHP 8.4.13, `vendor/` requires ≥ 8.5), so every isolated reproduction ran inside the compose image (`docker run --rm --entrypoint php -v "$PWD":/app ecommerce-core:dev …`), which is the same binary and `vendor/` the CI phase uses through `docker compose exec`.
3. `deptrac-candidate.yaml` at the repo root is tracked, empty and owned by `root`; it could not be written, so the candidate model was iterated in `spec-run-6/deptrac-candidate.yaml` (with `paths: ../src`, since deptrac resolves paths relative to the config file). The file at the root is unchanged.

## Requirement closure

| Req | Evidence |
|-----|----------|
| R1 | `spec-run-6/ci-help-final.log` — exit 0, `--quick`, `--skip-infection`, `--skip-prod-image`, `--skip-harness`, `--no-build`, `-h/--help` |
| R2 | `spec-run-6/verify-quick-final2.log` — start, quality, tests, harness; no infection, no prod-image |
| R3 | `spec-run-6/verify-full-final-2.log` — the six declared phases, in order |
| R4 | `spec-run-6/failures.md` §WP1 — verbatim output of all three commands, captured before the first edit |
| R5 | `spec-run-6/failures.md` §WP2 — one owning file per failure, each attribution confirmed by an isolated run |
| R6 | No skip in the repair: `ci-local.sh` (md5 `3bebb54c…`), `.github/workflows/ci.yml`, `compose.ci.yaml` and `Makefile` all still carry their 2026-10-22 mtime, the six phases still run, and deptrac reaches exit 0 by fixing the model rather than skipping the step |
| R7 | `spec-run-6/partition-proof.txt` + `deptrac-fixed.log` — same `paths`/`exclude_files`, no `skip_violations`, same 285 classified classes and the same 36 parser-skipped files, `Violations 0` |
| R8 | `spec-run-6/verify-quick-final2.log` — 4/4 phases `ok`, exit 0 |
| R9 | `spec-run-6/verify-full-final-1.log`, `verify-full-final-2.log` — 6/6 phases `ok`, exit 0 |
| R10 | `spec-run-6/tree-before-full-final-{1,2}.txt` — identical fingerprint, two consecutive full runs green |
| R11 | `## Residual reds` above, `spec-run-6/failures.md` §V3-b and §V4 — attributed to `F-remember-me` and to the shared `app_test`, never silenced |
| R12 | `.github/workflows/ci.yml:57` and `Makefile:101,114` run the identical `deptrac analyse` command with no `--config-file`, so they consume the repaired `deptrac.yaml`; no workflow edit needed |
| R13 | `git status --porcelain` — of the tracked files only `deptrac.yaml` and `tests/Functional/StorefrontCatalogBrowseTest.php` changed; the rest belongs to `F-remember-me` or predates the task |
| R14 | `git log -1` still `df5dc84`; `git diff --cached` empty; nothing pushed |

## Human approvals

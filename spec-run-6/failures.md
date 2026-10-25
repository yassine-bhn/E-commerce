# Failure ledger and attribution (WP1 + WP2 of `spec-run-6`)

Captured on 2026-10-23 from `/home/yassine/Desktop/freelance/Zidaine-groupe`, backend
checkout `E-commerce/` (branch `feat-add-missing-features`, `HEAD` `df5dc84`, working
tree clean except one untracked upload). Nothing was edited before the runs below
finished.

## WP1 — frozen failure set

| # | Command | Log | Exit | Phase summary |
|---|---------|-----|------|----------------|
| 1 | `./ci-local.sh --help` | `ci-help.log` | 0 | n/a (prints the six options, no phase) |
| 2 | `./ci-local.sh --quick` | `ci-quick.log` | 1 | start `ok`, **quality `FAILED`**, tests `ok`, harness `ok` |
| 3 | `./ci-local.sh` | `ci-full-baseline.log` | 1 | start `ok`, **quality `FAILED`**, tests `ok`, harness `ok`, infection `ok`, prod-image `ok` |

`R1` holds: `--help` exits 0 and lists `--quick`, `--skip-infection`,
`--skip-prod-image`, `--skip-harness`, `--no-build`, `-h/--help`.
`R2` holds: `--quick` runs start/quality/tests/harness and never prints the infection
or prod-image phases.
`R3` holds: the full gate runs the six declared phases in the declared order.

### The single red step

Both commands fail on the same step of the `ci.yml: quality` phase, and no other
step of any phase fails:

```
ci-local: step failed: dc exec -T app vendor/bin/deptrac analyse --no-progress
```

`deptrac analyse` report for the committed configuration (`ci-quick.log:8422-8432`):

```
 -------------------- ------
  Report
 -------------------- ------
  Violations           3658
  Skipped violations   0
  Uncovered            2993
  Allowed              685
  Warnings             177
  Errors               0
 -------------------- ------
```

Verbatim samples of the two causes:

```
App\Identity\Application\CommandHandler\ChangeCustomerRoleHandler is in more than one layer ["Application", "Identity"]. It is recommended that one token should only be in one layer.

Violation   App\Identity\Application\CommandHandler\ChangeCustomerRoleHandler must not depend on App\Identity\Domain\Model\AssignableCustomerRole (Domain)
Violation   App\Identity\Application\CommandHandler\ChangeCustomerRoleHandler must not depend on App\Identity\Domain\Model\AssignableCustomerRole (Identity)
```

The tests phase is green on all six suites (Unit 199, Integration 58, Projection 14,
Functional 204, Contract 14, Architecture 3 — `ci-quick.log:8544-8549`). The harness
phase and, in the full run, `infection` and `prod-image smoke` are green as well.

## WP2 — attribution

| Failing step | Owning file | Isolated reproduction | Result |
|---|---|---|---|
| `vendor/bin/deptrac analyse --no-progress` | `deptrac.yaml` (first and only file read by the tool: `paths: ./src`, `layers`, `ruleset`) | same command alone, old config (`ci-quick.log` §quality) | 3,658 violations, 177 overlap warnings |
| same | `deptrac.yaml`, rewritten | same command alone, new config (`deptrac-fixed.log`) | **0 violations, 0 warnings, 0 errors, exit 0** |

No other file can influence the step: the tool reads one config file plus `src/`;
`Makefile`/`docs/ci.md`/the workflow only carry the same command line, and that
command line is unchanged.

### Root cause

The committed model put every class in two layers at once — a tier layer
(`src/.+/Domain/.*`, `.../Application/.*`, `.../Infrastructure/.*`) and a context
layer (`src/Catalog/.*`, …). Each context layer allowed only `Shared`, and the tier
layers allowed only the generic `Domain`/`Application`/`Shared*` layers, so a
same-context dependency such as `Identity\Application → Identity\Domain` violated
both layers and was reported twice (3,658 ≈ 2 × 1,829). No arrangement of the code
could satisfy that model, and it was already red at `HEAD` before this task.

## WP3 — repair (config only, no code moved)

`deptrac.yaml` now declares 27 disjoint layers, one per (context, tier), and states
the documented rules directly:

- LAY-001: `*.Domain → Shared.Domain` only.
- LAY-002: `*.Application → <own>.Domain`, `Shared.Domain`, `Shared.Application`.
- LAY-003 / LAY-004: `*.Infrastructure → <own>.Domain/Application`, the shared
  kernel, and other contexts **only** through their `*.Infrastructure` (the ACL);
  no inner layer can reach Infrastructure, and no context can reach another
  context's Domain or Application.

Nothing was weakened (`R7`):

| Proof | Before | After |
|---|---|---|
| Config paths | `./src` + `Kernel.php` excluded | identical |
| `skip_violations` / baseline entries | none | none |
| Layer overlaps | 177 warnings ("more than one layer") | 0 |
| Classes in no layer | — | 0 (partition proof in `partition-proof.txt`) |
| Violations | 3,658 | 0 |

Pre-existing limitation, reported rather than hidden: the bundled parser of
`qossmic/deptrac-shim` 1.0.2 cannot parse PHP 8.3 typed class constants
(`private const string PATTERN`), so 36 files are printed as `Syntax Error on File`
and skipped by the analyser. The file list is **identical before and after**
(`diff` of the two runs: no difference), i.e. the repair neither added nor removed
an analysed file. Making those 36 files analysable means upgrading deptrac, which
is a dependency change outside this task.

## Verification record

| Run | Command | Log | Exit | Phases |
|---|---|---|---|---|
| V1 | `./ci-local.sh --quick` | `verify-quick.log` | 0 | start, quality, tests, harness — all `ok` |
| V2 | `./ci-local.sh` | `verify-full-1.log` | 0 | six phases `ok` |
| V3 | `./ci-local.sh` (consecutive) | `verify-full-2.log` | 1 | quality `FAILED` (php-cs-fixer), tests `FAILED`, infection `FAILED`, see below |
| V4 | `./ci-local.sh --quick` | `verify-quick-final.log` | 1 | start, quality, harness `ok`; tests `FAILED` on one test, see §V4 |
| V5 | `./ci-local.sh` (final tree) | `verify-full-final-1.log` | 0 | six phases `ok` |
| V6 | `./ci-local.sh`, immediately after V5 | `verify-full-final-2.log` | 0 | six phases `ok` — R10 satisfied |
| V7 | `./ci-local.sh --quick` and `--help` on the same tree | `verify-quick-final2.log`, `ci-help-final.log` | 0 / 0 | four phases `ok` / six options listed |

V5–V7 are the runs R8, R9 and R10 accept. They were taken once the concurrent
task had written nothing to `src|tests|config` for 14 minutes, and the fingerprint
(`git status --porcelain` + `git diff | sha256sum`) recorded before V5
(`tree-before-full-final-1.txt`) and before V6 (`tree-before-full-final-2.txt`) is
identical apart from the two fingerprint files themselves, so the two greens are
the same code run twice. Both runs report `deptrac` `Violations 0` / `Warnings 0`,
mutate the same 51 `src/` files with Infection (Covered Code MSI 99 % against a
required 90 %), and pass every suite — Unit 199, Integration 59, Projection 14,
Functional 223, Contract 16, Architecture 3.

V3 is the run that R10 exists for, and it found two distinct problems.

### V3-a — `StorefrontCatalogBrowseTest` depends on how many products a *neighbouring* test has created

```
App\Tests\Functional\StorefrontCatalogBrowseTest::testTheFirstPageCarriesTheOffsetEnvelopeAndFacets
Failed asserting that 14 is identical to 13.        (tests/Functional/StorefrontCatalogBrowseTest.php:57)

App\Tests\Functional\StorefrontCatalogBrowseTest::testASecondPageWithACustomSizeDoesNotRepeatTheFirstOne
meta.total is the filtered total, not the page size
Failed asserting that 14 is identical to 13.        (tests/Functional/StorefrontCatalogBrowseTest.php:81)
```

Cause: the test pinned the fixture cardinality (13 rows seeded by
`Version20261017000002`), while several Functional tests (`AdminCatalogTest`,
`AdminCatalogCategoryTest`, …) create storefront products for the duration of their
own test and delete them in `tearDown`. Under the deterministic suite order the
browse test never overlaps one of them; Infection runs PHPUnit with a random order
(`Random Seed: 1792760987` in the failing run), so the overlap happens whenever a
creating test is scheduled first. Observed directly: `storefront_product_view` holds
14 rows while another suite runs and 13 rows afterwards.

Deterministic reproduction (no random order involved): insert one extra row into
`storefront_product_view` and run the class.

| Version of the test | Result with 14 rows |
|---|---|
| `HEAD` (`git show HEAD:...`) | `Tests: 9, Assertions: 58, Failures: 2` — the two failures quoted above |
| repaired | `OK (9 tests, 67 assertions)` |

Repair: the envelope is now checked against the storefront projection itself
(`SELECT count(*) FROM storefront_product_view`) instead of a literal, and
`totalPages`, `hasMore` and both page sizes are derived from that number. The
contract that the two tests exist for — `meta.total` is the filtered total and not
the page size, page 2 does not repeat page 1, the default size is 24, `size` clamps
at 100, facets cover the filtered set — is unchanged and still asserted; nothing was
removed to get green.

### V3-b — the concurrent `F-remember-me` task (not this task)

While V3 ran, a second task started in the same checkout: `spec-run-7/`,
`.agents/state/F-remember-me.md`, `M .env`, `M .env.prod.example`,
`M config/packages/security.yaml`, `M src/Identity/Infrastructure/Security/LoginAuthenticator.php`,
`?? tests/Functional/RememberMeTest.php`, later also `M tests/Functional/{CorsTest,GoogleOAuthTest}.php`,
`M tests/Contract/Storefront/HttpApiContractTest.php`,
`?? tests/Integration/Identity/{RememberMeCookiePolicyTest,FreshProductionKernel}.php`.
Its in-flight files are what produced the other two V3 reds:

```
quality  — tests/Functional/RememberMeTest.php   (php-cs-fixer: import order)
tests    — App\Tests\Functional\RememberMeTest::testUnflaggedLoginIssuesNoRememberMeCookie
           App\Tests\Functional\RememberMeTest::testFailedFlaggedLoginIssuesNoRememberMeCookie
           App\Tests\Functional\RememberMeTest::testRememberMeCookieAloneAuthenticatesAfterRestart (LogicException)
           … 3 more (214 tests, 3 failures, 3 errors)
infection — same initial-coverage bootstrap, same files
```

Owned by `F-remember-me`; not touched here, because fixing another task's boundary
while it is being written would collide with it (spec R11: attribute, never silence).

### V4 — two PHPUnit processes on one database, and the one red it can fake

After the `deptrac.yaml` repair and the V3-a repair, `./ci-local.sh --quick` on the
repaired tree reached the end of `quality` green — the report is now
`Violations 0`, `Skipped 0`, `Warnings 0`, `Errors 0` (`Uncovered 1453`,
`Allowed 709`) — and failed the `tests` phase on a single, different assertion:

```
App\Tests\Functional\AdminCustomerManagementTest::testUserListPaginatesWithoutRepeatingRows
Failed asserting that an array contains '93dffc75-697a-43ec-bc9f-bb1c4ccb1256'.
/tests/Functional/AdminCustomerManagementTest.php:387
Tests: 223, Assertions: 1541, Failures: 1.
```

This is not a defect of the tree. The same tree, the same database, seconds later:

| Command | Result |
|---|---|
| `phpunit --testsuite Functional --filter testUserListPaginatesWithoutRepeatingRows` | `OK (1 test, 13 assertions)` |
| `phpunit --testsuite Functional` | `OK (223 tests, 1540 assertions)` |

- The test walks every page (`$pages = $payload['meta']['total']`), so a five-row
  table cannot hide a row from it; `customer_profile` holds 2 rows, so the "the
  database has accumulated too many rows" explanation is excluded.
- A row created by the test can only be missing from the walk if another process
  deletes it between two requests. `F-remember-me` runs PHPUnit against the same
  compose project (`ecommerce-ci`) and therefore the same `app_test` database; its
  `GATE GREEN ok` line is timestamped inside this run's `tests` phase.

Consequence for the gate: `./ci-local.sh` is a single-writer tool. While a second
PHPUnit process writes to `ecommerce-ci/app_test`, its `tests` phase can fail on any
test that counts rows it does not own. That is not repaired here — giving each run
its own database name is a change to the gate's contract (spec Out of scope: no new
feature). Verification runs are only accepted from a quiet checkout, and a red that
disappears on a standalone re-run of exactly that suite is recorded as interference,
never as `ok` by skipping it.



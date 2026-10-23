# Spec: A trustworthy local CI gate (intent reading)
Version: 1

## Goal
The user can run the local reproduction of the GitHub pipelines and trust the answer: the fast gate and the full gate both end green, and the only reason a phase is green is that the thing it checks is actually satisfied.

## Corrected prompt
Run the local CI reproduction from the workspace root — the full gate, the quick gate and the option list — then repair every failure it reports.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | `./ci-local.sh --help` exits 0 and lists every option the script accepts. | functional | `./ci-local.sh --help    # all options` | must |
| R2 | `./ci-local.sh --quick` executes exactly the quality, tests and harness phases and skips infection and the prod-image smoke test. | functional | `./ci-local.sh --quick    # quality + tests + harness only` | must |
| R3 | `./ci-local.sh` executes all phases including infection and the prod-image smoke test. | functional | `./ci-local.sh            # full gate` | must |
| R4 | `./ci-local.sh --quick` ends with every phase `ok` and exit status 0. | functional | `fix if there is errors` | must |
| R5 | `./ci-local.sh` ends with every phase `ok` and exit status 0. | functional | `fix if there is errors` | must |
| R6 | Each failure is repaired at its source: the check that fails is made genuinely satisfiable by the code, configuration or workflow it verifies. | constraint | `fix if there is errors` | must |
| R7 | No failure is repaired by removing or skipping a phase, by `continue-on-error`, by `--skip-infection`/`--skip-prod-image`, or by widening a threshold. | constraint | `fix if there is errors` | must |
| R8 | The repaired gate stays green on a second, independent run (no order dependence, no state left behind by the first run). | quality | `fix if there is errors` | must |
| R9 | A gate that is red for a reason owned by a different, concurrent piece of work is reported as such instead of being silenced. | constraint | `fix if there is errors` | should |
| R10 | The GitHub workflow that mirrors the failing local step carries the same fix, so local green implies remote green. | quality | `./ci-local.sh` reproduces the GitHub pipelines | should |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The user's goal is a pushable state for the backend, so both the quick and the full gate matter, not only the command that fails first. | low |
| 2 | A failure counts as an error when the phase reports `FAILED`, even if the underlying tool prints warnings only. | low |
| 3 | The current tree (branch `feat-add-filters` plus its uncommitted work) is the state to be made green; uncommitted work is legitimate input, not noise. | med |
| 4 | The gate must remain meaningful: a deptrac, phpstan, cs-fixer or PHPUnit check that passes because it stopped looking is a regression, not a fix. | med |
| 5 | Fixing a gate is allowed to touch architecture configuration (`deptrac.yaml`) and CI scripts, because those files *are* what the phase verifies. | med |
| 6 | Re-running the gate after the fix is the acceptance evidence; no commit or push is requested. | low |

## Open questions
- [minor] Must the full gate's infection and prod-image phases be fixed even if they are slow to reproduce? Default if unanswered: yes, both are part of the requested command and must end `ok`.

## Out of scope
- New features, new endpoints, new tests beyond what a red gate requires.
- Restructuring the bounded contexts themselves.
- Committing, tagging, pushing or deploying.
- Repo-wide refactors that are not needed to turn a phase green.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Establish the exact red set for both gates and attribute each failure | R1, R2, R3 | `ci-local.sh`, `compose.yaml`, `compose.ci.yaml`, the workflows | Failure ledger: phase, failing step, tool output, owning file | `spec-run-6/` evidence only | none | Every `FAILED` phase has a named failing step and an attributed cause |
| WP2 | Repair the failing checks at their source | R6, R7, R10 | WP1 ledger | Changes in the owning files, no phase skipped and no threshold widened | the files named by the ledger | WP1 | The failing step passes when invoked directly |
| WP3 | Verify the quick gate end to end | R2, R4 | WP2 | Logged `--quick` run | none | WP2 | Summary shows four phases `ok`, exit 0 |
| WP4 | Verify the full gate end to end, twice | R3, R5, R8 | WP2 | Two logged full runs | none | WP3 | Both runs show every phase `ok` and exit 0 |
| WP5 | Report residual reds owned by other work | R9 | WP4 | A short attribution note | `spec-run-6/`, `.agents/state/**` | WP4 | Each remaining failure names the file and the task that owns it |

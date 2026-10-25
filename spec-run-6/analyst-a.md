# Spec: Green local CI gate for the E-commerce backend (literal reading)
Version: 1

## Goal
`./ci-local.sh`, `./ci-local.sh --quick` and `./ci-local.sh --help` run from the workspace root and produce no error; every error they do produce is fixed.

## Corrected prompt
From the workspace root: run the full gate with `./ci-local.sh`, run the fast gate with `./ci-local.sh --quick`, list the options with `./ci-local.sh --help`, and fix whatever errors appear.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | `./ci-local.sh --help` prints every supported option and exits 0. | functional | `./ci-local.sh --help    # all options` | must |
| R2 | `./ci-local.sh --quick` runs the quality, tests and harness phases and nothing else. | functional | `./ci-local.sh --quick    # quality + tests + harness only` | must |
| R3 | `./ci-local.sh` runs all five phases: start the stack, quality, tests, harness, infection, prod-image. | functional | `./ci-local.sh            # full gate` | must |
| R4 | Every phase of `./ci-local.sh --quick` reports `ok` and the script exits 0. | functional | `fix if there is errors` | must |
| R5 | Every phase of `./ci-local.sh` reports `ok` and the script exits 0. | functional | `fix if there is errors` | must |
| R6 | The fix of each reported error is committed to the repository (file change), not worked around by skipping the phase or by weakening the check. | constraint | `fix if there is errors` | must |
| R7 | Re-running the same command after the fix reports no error, i.e. the result is reproducible. | quality | `fix if there is errors` | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The commands are run from `/home/yassine/Desktop/freelance/Zidaine-groupe`, where `ci-local.sh` resolves the backend checkout at `E-commerce/`. | low |
| 2 | Docker, Docker Compose v2, git, curl and python3 with PyYAML are present; the script's preflight already enforces this. | low |
| 3 | "Errors" means a non-zero exit of the script or a `FAILED` line in its summary, not warnings printed by third-party tools. | med |
| 4 | The same definition of green applies to the GitHub workflows the script reproduces, so a fix in the script alone is not enough when a workflow step is the red one. | med |

## Open questions
- [minor] Should the uncommitted work of the concurrent tasks (`F-inventory-settings`, `F-payment-core`) be treated as in scope when it is what turns a gate red? Default if unanswered: no; fix only what is independent of those tasks and report the rest.

## Out of scope
- Changing the phase list, the phase order, or the meaning of `--quick`, `--skip-*` or `--no-build`.
- Changing the GitHub workflow triggers or adding new jobs.
- Performance of the gate (build time, image size).

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Capture the current output of the three commands and identify every failing step | R1, R4, R5 | `ci-local.sh`, the run logs | A list of failing steps with the command and the exact error text | `spec-run-6/` evidence only | none | Each failing step is named with its exit code and error excerpt |
| WP2 | Fix the identified failures | R6, R7 | WP1 list | File changes that make the failing steps pass | the files named by the failing steps | WP1 | Every named step passes when re-run |
| WP3 | Prove the gate end to end | R2, R3, R4, R5, R7 | WP2 changes | A recorded `--quick` run and a recorded full run | none (read-only) | WP2 | Both runs end with every phase `ok` and exit 0 |

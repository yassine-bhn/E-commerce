# Spec: Local CI gate repair (skeptic reading)
Version: 1

## Goal
The three commands behave as advertised and the failures they report are repaired without silently shrinking what the gate checks — and with the ambiguity of "errors" resolved before any file is edited.

## Corrected prompt
From the workspace root, run `./ci-local.sh` (full gate), `./ci-local.sh --quick` (quality + tests + harness) and `./ci-local.sh --help`, then fix the errors that appear.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | `./ci-local.sh --help` exits 0 and documents every option accepted by the argument parser. | functional | `./ci-local.sh --help     # all options` | must |
| R2 | `./ci-local.sh --quick` runs the quality, tests and harness phases and provably skips infection and prod-image. | functional | `./ci-local.sh --quick    # quality + tests + harness only` | must |
| R3 | `./ci-local.sh` runs all phases the script declares, in the declared order. | functional | `./ci-local.sh            # full gate` | must |
| R4 | The set of `FAILED` phases produced by both commands is captured verbatim, with the failing step and tool output, before any fix is attempted. | constraint | `fix if there is errors` | must |
| R5 | Each captured failure is attributed to a specific file, and the attribution is checked against a run of the same step in isolation. | constraint | `fix if there is errors` | must |
| R6 | A failure is repaired at the file that owns it; the phase, the command list and the tool thresholds are left intact. | constraint | `fix if there is errors` | must |
| R7 | A repair of a static-analysis failure must preserve the rule the tool enforces: the number of analysed files/tokens must not fall, and no `skip_violations`/baseline entry may be added to hide an existing violation. | constraint | `fix if there is errors` (read as "repair, not silence") | must |
| R8 | After the repair, `./ci-local.sh --quick` ends with four phases `ok` and exit 0. | functional | `fix if there is errors` | must |
| R9 | After the repair, `./ci-local.sh` ends with every phase `ok` and exit 0, on a second consecutive run too. | quality | `fix if there is errors` | must |
| R10 | Any phase still red is attributed to a named concurrent task and reported, never marked `ok` by skipping it. | constraint | `fix if there is errors` | must |
| R11 | Files changed by the repair stay inside the set the failing steps own (CI script, compose overlays, workflows, architecture configuration, `src/**` or `tests/**` only when the red step requires it). | constraint | `fix if there is errors` | should |
| R12 | No commit, push, tag or deploy is performed. | constraint | the prompt asks to fix, not to ship | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Errors" means `FAILED` phases in the script summary plus any non-zero exit of one of the three commands. | med |
| 2 | The three commands are run against the current working tree, including uncommitted changes, and not against a clean checkout. | med |
| 3 | A phase that aborts at its first failing step hides its later steps; those steps are only known to pass once the phase gets past the earlier failure. | med |
| 4 | The full gate's infection and prod-image phases are in scope even though they are slow and were not part of a first failing run. | high if infection is genuinely red |

## Open questions
- [BLOCKING] When a static-analysis gate is red because its *configuration* cannot ever pass (the configured layers overlap and the ruleset forbids every same-layer dependency), is the intended repair the configuration or the production code? Default if unanswered: repair the configuration so it expresses the documented layering rules (`.agents/rules/layering.md` LAY-001…LAY-004), keep every analysed file in exactly one layer, and prove no rule was relaxed by showing the violation count falls to zero while the analysed token count stays the same.
- [minor] Does "fix" include the harness gates (`.agents/state/**`, audit log, DoD) for this repo? Default if unanswered: yes, record the task, gates and evidence as the harness requires.
- [minor] Is a rebuild of the image required, or is reusing the current image acceptable evidence? Default if unanswered: reuse the current image for iteration, and do at least one final run that includes the build.
- [minor] Should the repair also keep `make ci` / `make deptrac` and `docs/ci.md` truthful about the same command list? Default if unanswered: yes, documentation and Makefile parity only if a command actually changes.

## Out of scope
- Refactoring production code to satisfy a layer model that the code was never written against.
- Adding mutation-killing tests beyond what the infection phase requires.
- Changing phase names, flags or the meaning of `--quick`.
- Repo hygiene unrelated to the reported failures (untracked uploads, stray directories).

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Freeze the failure set from all three commands | R1, R2, R3, R4 | `ci-local.sh` and its logs | Verbatim failure ledger with the command that produced each line | `spec-run-6/` | none | Each command's exit code and each `FAILED` phase is recorded |
| WP2 | Attribute each failure to one owning file and prove it by isolating the step | R5 | WP1 ledger | Attribution table: step → file → isolated reproduction | `spec-run-6/` | WP1 | Each failure reproduces alone with the named file unchanged and stops reproducing when only that file is corrected |
| WP3 | Repair the failing static-analysis configuration without relaxing the rule | R6, R7 | WP2 table, `.agents/rules/layering.md` | Rewritten layer model with every class in exactly one layer, no skip list | `deptrac.yaml` (and `Makefile`/`docs/ci.md` only if the command changes) | WP2 | `deptrac analyse` exits 0 with zero violations and zero "more than one layer" warnings, on the same file set |
| WP4 | Verify the quick gate | R8 | WP3 | Logged `--quick` run | none | WP3 | Four phases `ok`, exit 0 |
| WP5 | Verify the full gate twice | R9, R10 | WP3 | Two logged full runs plus an attribution note for anything still red | `spec-run-6/`, `.agents/state/**` | WP4 | Every phase `ok` on both runs, or each residual red attributed to a named owning task |
| WP6 | Confirm the change set and the no-ship rule | R11, R12 | WP3–WP5 | `git status` of changed files and confirmation that nothing was committed or pushed | none | WP5 | Changed files are a subset of the owning set; `git log` unchanged |

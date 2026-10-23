# Spec: Green local CI gate for the E-commerce backend
Version: 1

## Goal
The three `ci-local.sh` commands behave as advertised, every phase they report is genuinely green, and the one red gate (`deptrac`) is repaired at its real cause instead of being silenced.

## Corrected prompt
From the workspace root, run the full local CI gate (`./ci-local.sh`), the quick gate (`./ci-local.sh --quick`) and the option list (`./ci-local.sh --help`); fix any error they report.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | `./ci-local.sh --help` exits 0 and lists every option the argument parser accepts (`--quick`, `--skip-infection`, `--skip-prod-image`, `--skip-harness`, `--no-build`, `-h/--help`). | functional | `./ci-local.sh --help    # all options` | must |
| R2 | `./ci-local.sh --quick` runs the quality, tests and harness phases and does not run infection or prod-image. | functional | `./ci-local.sh --quick    # quality + tests + harness only` | must |
| R3 | `./ci-local.sh` runs every phase the script declares, in the declared order: start, quality, tests, harness, infection, prod-image smoke. | functional | `./ci-local.sh            # full gate` | must |
| R4 | The failures of both gates are captured verbatim before any edit: phase name, failing step, exit code and tool output. | constraint | `fix if there is errors` | must |
| R5 | Each captured failure is attributed to one owning file, and the attribution is confirmed by running the same step in isolation. | constraint | `fix if there is errors` | must |
| R6 | Each failure is repaired in the file that owns it; no phase is removed or skipped, no `continue-on-error`, no threshold widened, no check disabled to reach green. | constraint | `fix if there is errors` | must |
| R7 | The repair of a static-analysis gate keeps the analysed file set unchanged and adds no `skip_violations`/baseline entry: existing violations are eliminated, not hidden. | constraint | `fix if there is errors` | must |
| R8 | After the repair, `./ci-local.sh --quick` ends with every phase `ok` and exit status 0. | functional | `fix if there is errors` | must |
| R9 | After the repair, `./ci-local.sh` ends with every phase `ok` and exit status 0. | functional | `fix if there is errors` | must |
| R10 | A second consecutive full run is also green: no order dependence and no state left behind by the first run. | quality | `fix if there is errors` | must |
| R11 | A phase still red after the permitted work is attributed to a named file and owning task in the report, never marked `ok` by skipping it. | constraint | `fix if there is errors` | must |
| R12 | When the red local step is itself a step of a GitHub workflow, the workflow carries the same fix, so local green implies remote green. | quality | `./ci-local.sh` reproduces the GitHub pipelines | should |
| R13 | Changed files stay inside the set the failing steps own:`ci-local.sh`, `compose*.yaml`, `docker/**`, `.github/workflows/**`, `Makefile`, `docs/ci.md`, `deptrac.yaml`, or `src/**` + `tests/**` only when a red step requires it. | constraint | `fix if there is errors` | must |
| R14 | No commit, push, tag or deploy. | constraint | the prompt asks to fix, not to ship | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The commands run from `/home/yassine/Desktop/freelance/Zidaine-groupe`; the backend checkout is `E-commerce/` and the image, compose overlays and Docker prerequisites are already available. | low |
| 2 | "Errors" means a `FAILED` phase in the script summary plus any non-zero exit of the three commands; deprecation notices printed by third-party PHARs are noise, not errors. | med |
| 3 | The current working tree, including the uncommitted work of the concurrent tasks, is the state to be made green. | med |
| 4 | A gate that turns green because it stopped looking is a regression; the repair must preserve what the tool verifies. | med |
| 5 | The full gate's infection and prod-image phases are in scope; both are part of the requested command and must end `ok`. | high |
| 6 | Repairing an architecture gate by correcting its configuration is legitimate when the configuration, not the code, is what cannot satisfy the documented rules. | med |
| 7 | Re-running the gates is the acceptance evidence; nothing is committed or deployed. | low |

## Open questions
- [resolved — see Decisions D1] Deptrac repair: configuration or production code? Default if unanswered: repair `deptrac.yaml` so it expresses the documented rules, keeping every analysed class in exactly one layer and the analysed file set identical.
- [minor] Do the harness gates (`.agents/state/**`, audit log, DoD) belong to this task? Default if unanswered: yes — record the task, gates and evidence as `AGENTS.md` requires.
- [minor] Is a fresh image build required for the evidence? Default if unanswered: iterate with the current image and make the final full run include the build.

## Out of scope
- Refactoring production code to satisfy a layer model the code was never written against.
- New features or new endpoints; tests beyond what a red gate requires.
- Mutation-killing tests beyond what the infection phase demands.
- Changing phase names, flags or the meaning of `--quick`, `--skip-*`, `--no-build`.
- Repo hygiene unrelated to the failures (untracked uploads, stray directories, `.deptrac.cache`).
- Committing, tagging, pushing, deploying.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Freeze the failure set of all three commands | R1, R2, R3, R4 | `ci-local.sh`, run logs | `spec-run-6/ci-quick.log`, `ci-full.log`, `failures.md` with the verbatim ledger | `spec-run-6/**` | none | Each command's exit code and every `FAILED` phase with its failing step is recorded |
| WP2 | Attribute each failure to one owning file and prove it in isolation | R5 | WP1 ledger | Attribution table in `failures.md` | `spec-run-6/**` | WP1 | Each failure reproduces with only its owning file unchanged, and disappears when only that file is corrected |
| WP3 | Repair the deptrac layer model so it expresses LAY-001…LAY-004 | R6, R7, R13 | WP2 table, `.agents/rules/layering.md`, `docs/adr/ADR-0001` | Rewritten `deptrac.yaml` with disjoint layers, intra-layer allows, DDD direction, cross-context allowed only Infrastructure→Infrastructure | `deptrac.yaml` | WP2 | `deptrac analyse` exits 0 with 0 violations and 0 "more than one layer" warnings over the same 412-file set; no `skip_violations` |
| WP4 | Verify the quick gate | R8 | WP3 | `spec-run-6/verify-quick.log` | `spec-run-6/**` | WP3 | Summary shows start/quality/tests/harness `ok`, exit 0 |
| WP5 | Verify the full gate twice | R9, R10, R11 | WP3 | `spec-run-6/verify-full-1.log`, `verify-full-2.log`, residual attribution | `spec-run-6/**`, `.agents/state/**`, `.agents/audit/**` | WP4 | Every phase `ok` on both runs and exit 0, or each residual red attributed to a named owning task |
| WP6 | Confirm the change set and the no-ship rule | R12, R13, R14 | WP3–WP5 | `git status`/`git log` evidence in the state file | `.agents/state/**` | WP5 | Changed files are a subset of the owning set; `git log` unchanged; mirrored workflow steps carry the same fix or are proven to need none |

## Decisions (synthesizer only)

| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Meaning of "fix" | A committed file change | Repair at the source, no weakening | Repair at the owning file, prove attribution | Repair at the owning file; the change is persisted in the working tree but not committed | The prompt asks to fix, not to ship; R14 keeps the repository history untouched |
| Scope of the gate | Full gate green (R5) | Both gates, twice | Both gates, twice, plus attribution | Both gates; a second consecutive full run (R10) | A single run can hide leftover Docker state and order dependence |
| Deptrac: config or code | not raised | implied by "repair at source" | explicit BLOCKING question | Repair the configuration (D1) | The committed config fails even on clean `HEAD` (3,051 violations) and its layers overlap by construction; the code already satisfies the documented rules once the model is written correctly (measured: 0 violations) |
| Residual reds from concurrent work | out of scope by default | report, do not silence | attribute and report | Attribute and report (R11) | Silencing them would turn a real red into a lie; fixing another task's boundary would collide with it |
| Weakening guards | not raised | forbid (R7) | forbid, with the analysed-file-set check | Forbid, with the file-set proof (R7) | The gate must stay meaningful; the proof is checkable |
| Commit or not | commit the fix (R6) | not requested | explicitly forbidden (R12) | No commit (R14) | The prompt asks to fix; committing is a separate, explicit decision |

## Changelog (synthesizer and orchestrator only)
- v1: created from `analyst-a.md`, `analyst-b.md`, `analyst-c.md`.

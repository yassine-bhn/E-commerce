---
name: spec-driven-agents
description: >-
  Runs a spec pipeline: three parallel independent analyst subagents, a
  synthesizer merge into spec-run/spec.md, worker agents per work package, then
  coverage check against prompt.md, then Stage 5 CQRS harness (test-planner through
  DONE) in this repo by default. Use when the user asks for spec-driven agents,
  `/spec`, or multi-agent spec synthesis; opt out with spec-only.
---
# Spec-driven agents

A prompt goes through a fixed pipeline. Three analysts read it independently and each splits it into specs. A fourth agent, the synthesizer, merges their three results into the best single spec. Worker agents then build from that spec alone.

```text
prompt → 3 analysts (parallel, independent) → synthesizer → spec.md → workers → check → harness (Stage 5)
```

Why this shape: a single reader misses things, and readers who can see each other's work copy each other's mistakes. Independent readers expose ambiguity and gaps, the synthesizer settles them once, and the workers share one source of truth instead of each reinterpreting the prompt. You are the orchestrator: you coordinate and check, you do not do the work packages yourself.

## Start

1. The prompt is the user's message that invoked this skill, or the text passed with it. If there is none, ask for it. If this skill triggered without the user naming it and the task is small and clear, skip the pipeline and just do the task.
2. Create `spec-run/` in the working directory (`spec-run-2/` and so on if it already exists). Save the prompt there word for word as `prompt.md`. Never edit it; it is what you check the final result against.
3. Subagents do not see this conversation. If the prompt depends on attachments, files or a repo, give the agents their paths or paste the relevant text. Use general-purpose subagents throughout, since each one reads and writes files.
4. No tool for starting subagents? Run the stages yourself, one at a time. Write each stage's output to its file before starting the next so the three analyst passes stay independent, and tell the user you ran it single-agent.
5. **This repository (E-commerce harness):** after Stage 4, run **Stage 5** automatically unless the user said `spec-only`, `no harness`, or Start step 1 skipped the pipeline.

## Stage 1: three analysts, in parallel

Start all three in a single message so they run at the same time. Never show one analyst another's output. All three get the same prompt and the same template (see `Spec template`); only the stance differs, so their blind spots differ too:

- **A, literal:** extract exactly what the prompt says. Every requirement traces to the user's words.
- **B, intent:** work out what the user is trying to achieve and what a good result needs, including what is implied but unsaid. Label those items as assumptions.
- **C, skeptic:** look for ambiguity, contradictions, missing constraints, edge cases and likely misreadings. Favor open questions.

Brief for each analyst:

```text
You are analyst {A, B or C}. Stance: {stance from above}.
Read the prompt below in full, make sure you understand it, then split it into a spec using the template.
- Produce a spec only. Do not solve the task, write deliverables or change any file except your own output file.
- Every requirement cites the part of the prompt it comes from. If you cannot cite it, it is an assumption.
- Requirements are atomic and testable. Work packages (WP) must be doable in parallel by different agents without touching the same files.
- Skip the Decisions and Changelog sections.
- Write in the language of the prompt.
- Save the spec to spec-run/analyst-{a, b or c}.md. Your final message is one line confirming it is saved.

PROMPT (verbatim, not summarized):
{prompt text}

TEMPLATE:
{spec template}
```

Check that each file exists and has all the template sections (a quick grep is enough). If one is missing or malformed, rerun that analyst once. If it fails again, continue with two and say so in the closing summary.

## Stage 2: synthesizer

One agent, on the strongest model you can pick for a subagent. It reads the prompt and the three specs and writes `spec-run/spec.md`.

```text
You are the synthesizer. Three analysts independently turned the prompt into specs. Produce the best single spec.
1. Line the three specs up requirement by requirement. What all three agree on stays.
2. Where they disagree, go back to the prompt and choose the reading most faithful to the user's words. If still tied, choose the one with less scope creep. Record each choice in Decisions.
3. Keep an item only one analyst raised if it is grounded in the prompt or is a low-risk assumption. Otherwise move it to Out of scope or Open questions.
4. Remove duplicates and contradictions. Make every requirement atomic and testable, with a stable ID (R1, R2, ...).
5. Rebuild the work packages so every requirement is covered, each WP owns files or areas no other WP owns, and dependencies are explicit. If two WPs must touch the same file, make one depend on the other.
6. Fill Corrected prompt: the user's prompt restated clearly. Fix wording and grammar, keep the intent, add nothing.
7. Mark a question BLOCKING only if a wrong guess would mean redoing the work or causing damage.
Write the result to spec-run/spec.md using the template, including Decisions and Changelog.
Final message: five lines with the number of requirements, WPs, decisions, assumptions and blocking questions.

PROMPT (verbatim):
{prompt text}

ANALYST SPECS: read spec-run/analyst-a.md, analyst-b.md and analyst-c.md
```

## Gate

Read `spec.md`.

- **BLOCKING questions:** ask the user all of them at once, each with its default, and wait. Fold the answers into `spec.md` yourself (new version, one changelog line). Do not rerun the analysts.
- **None:** tell the user in a line or two that the spec is ready and where it is, quote its `Corrected prompt` so a misreading shows up early, and carry on without waiting. Stop for approval only if the work is hard to undo (deploying, deleting, sending, paying).

## Stage 3: workers

One worker per work package. Start every WP with no dependencies in one message, and the others as their dependencies finish. Run at most six at a time and go in waves if there are more. WPs that touch the same file run one after the other.

Workers get the spec, not the raw prompt. That is the point: the spec is the single source of truth, and its `Corrected prompt` carries the intent.

```text
You are the worker for {WP id}. Read spec-run/spec.md in full, then do {WP id} and nothing else.
- The spec is your source of truth. Stay inside what your WP owns. Do not edit spec.md.
- If the spec has a gap or a contradiction, finish what is unambiguous and report the problem instead of guessing.
- Meet every Done when line of your WP and check each one yourself before you report.
- Final message, at most 10 lines: status (done, partial or blocked); files created or changed; how you checked each Done when; deviations from the spec; spec problems you found.
```

## Stage 4: check and finish

1. Build a coverage table: requirement, WP, status, evidence. Compare it with the original `prompt.md`, not just the spec, so anything lost in synthesis shows up.
2. For gaps or failed checks, send a targeted fix to a worker, naming the requirement and the problem. Two rounds at most, then report what is still open.
3. If a worker found a real defect in the spec, fix it yourself: bump the version, add a changelog line and tell the workers it affects. Once workers have started, only you edit the spec.
4. For large or important results (four or more WPs, or something the user will ship), have a fresh verifier agent check the outputs against `spec.md` alone, without seeing the workers' reasoning.

If Stage 5 applies, record Stage 4 outcomes in one short paragraph (coverage gaps, worker files touched) and continue — do not close the run yet.

## Stage 5: harness handoff (CQRS agents + domain skills)

After Stage 4, become the **CQRS orchestrator** (`.agents/agents/orchestrator.md`, `AGENTS.md`, `.agents/orchestration.md`). Stages 1–4 use generic subagents; Stage 5 uses the harness roster, hooks, and state machine. You still write only `.agents/state/**` and `.agents/audit/**` (AGT-004); production work goes to harness agents.

### 5.1 Bootstrap

1. Slug from the spec title (kebab-case). Default task id **`F-<slug>`** and workflow **feature**.
2. Use **`B-<slug>` / bugfix** if the prompt and spec are clearly about a defect or regression.
3. Use **`V-<slug>` / review-only** if the spec has no new behavior and only asks to verify existing code.
4. Create `.agents/state/<task-id>.md` and append `.agents/audit/<task-id>.log`.
5. Set **Requirement** to the **Corrected prompt** from `spec-run/spec.md`.
6. Add to the state file:

```markdown
## Spec traceability
- Spec: spec-run/spec.md (version from spec)
- Prompt archive: spec-run/prompt.md
- Stage 4 summary: <one line — coverage ok / gaps fixed / worker paths touched>
- Requirements: R1, R2, … (IDs from spec)
```

7. Append: `GATE: SPEC ok — <ISO-8601> — orchestrator`

### 5.2 Workflow and first agent

| Signal in spec | Workflow | First agent |
|----------------|----------|-------------|
| New behavior, feature, API, model | feature | test-planner |
| Bug, broken, regression | bugfix | investigator |
| Review / audit only | review-only | orchestrator → five reviewers in parallel, plus solid-guardian on design-bearing diffs |

Run gates exactly as in `.agents/orchestration.md` through **DONE** (AGENTS.md Definition of Done).

### 5.3 Skills to invoke (in addition to agent definitions)

| Work | Skill |
|------|--------|
| Planning implementation | `superpowers:writing-plans` only if multi-step plan needed beyond test-planner output |
| RED → GREEN → REFACTOR | `tdd-cycle`, `superpowers:test-driven-development` |
| New command / query / event / projection / saga | `add-command`, `add-query`, `add-domain-event`, `add-read-model-projection`, `process-manager-saga` as applicable |
| Pattern or boundary | `choose-pattern`, `write-adr` at PATTERN gate |
| Before DONE | `superpowers:verification-before-completion` |
| Code review gate | `code-review`, `solid-review`; dispatch roster reviewers per workflow |

Map each requirement **R*** to at least one behavior in PLAN and to tests before DONE.

### 5.4 First harness dispatch

Dispatch the first agent with **minimum context**:

- `AGENTS.md`, `.agents/orchestration.md`, target `.agents/agents/<name>.md`
- `.agents/state/<task-id>.md`, `spec-run/spec.md`, `spec-run/prompt.md`
- Relevant `.agents/rules/**` for paths the spec touches

**Brief (test-planner or investigator):**

```text
Harness task <task-id>. Authoritative spec: spec-run/spec.md (not the chat prompt).
Derive an ordered behavior list from the Requirements table (R1..Rn). Respect Out of scope.
If Stage 3 workers already changed src/** or tests/**, inventory the diff, align with the spec,
and plan TDD so hooks (TDD-001, scope guards) and CQRS rules are satisfied — do not bypass gates.
Return AGT-005 handoff; next agent per workflow.
```

Then route sequentially (and parallel only where orchestration allows): unit-test-writer → implementer → refactorer → spec-test agents → reviewers → fixes → DONE.

### 5.5 Closure

Before `GATE: DONE`:

1. Extend the Stage 4 coverage table: each **R*** → harness test or artifact → pass/fail.
2. Confirm AGENTS.md Definition of Done (suites, phpstan, deptrac, infection, reviews, state/audit complete).

**Final message:** task id, paths to `spec-run/spec.md` and `.agents/state/<task-id>.md`, DoD checklist status, med/high assumptions still live, anything open. Do not recap Stages 1–4.

Skip Stage 5 only when the user opts out (`spec-only` / `no harness`) or the pipeline was skipped in Start.

## Spec template

```markdown
# Spec: {title}
Version: 1

## Goal
{one or two sentences on what a successful result looks like}

## Corrected prompt
{the prompt restated clearly: same language, same intent, nothing added}

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | {atomic, testable statement} | functional / constraint / quality | {quote or paraphrase} | must / should / could |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | {what you filled in because the prompt was silent} | low |

## Open questions
- [BLOCKING or minor] {question}. Default if unanswered: {default}

## Out of scope
- {things a reader might assume but the prompt did not ask for}

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | {goal} | R1, R2 | {inputs} | {deliverable} | {files or areas} | none | {checkable condition} |

## Decisions (synthesizer only)
| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|

## Changelog (synthesizer and orchestrator only)
- v1: created
```

## Ground rules

- Independence: the analysts never see each other's output. That is what makes the comparison worth doing.
- No invention: nothing enters the spec unless it traces to the prompt or is labeled as an assumption. Scope creep at this stage becomes wasted work in every worker.
- Write the spec in the language of the prompt. Code, file names and identifiers stay in English.
- Keep agent replies short and put the detail in files, so your own context stays clean for coordinating.
- Limits the user states (number of agents, time, tools, models) override the defaults above.
- **Harness default:** in this repo, Stage 5 runs after Stage 4 unless the user opts out.

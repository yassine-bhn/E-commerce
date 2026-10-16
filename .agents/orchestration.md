# Orchestration & agent management

Loaded by the orchestrator on every dispatch. Full contract for handoffs, state, gates.

## Handoff contract (exact template)

Every agent MUST end its run with this template (AGT-005):

```
## Handoff
- Task: <task-id>
- Agent: <agent-name>
- Gate: <gate reached: PLAN|PATTERN|RED|GREEN|REFACTOR|SPEC-TESTS|REVIEW|FIXES|INVESTIGATE|DONE|ESCALATE|BLOCKED>
- Summary: <max 3 lines>
- Artifacts:
  - <path> (<created|modified|read>)
- Rules applied: [<RULE-ID>, ...]
- Rules violated: [<RULE-ID>, ...] (expected: none)
- Commands run:
  - `<command>` → <pass|fail> — <1-line result>
- Open questions: <...|none>
- Next agent: <agent-name|human|done>
```

Non-conforming output is rejected by `.agents/hooks/completion-check.sh` (AGT-002/AGT-005),
re-dispatched once with the validation error, then escalated to a human.

## Task state file

Path: `.agents/state/<task-id>.md`. Task id: `F-<slug>` (feature), `B-<slug>` (bugfix),
`R-<slug>` (refactor), `I-<slug>` (investigation), `V-<slug>` (review), `P-<slug>` (pattern).
Created by the orchestrator at dispatch, updated at every gate. Format:

```markdown
# Task <task-id>
Type: <feature|bugfix|refactor|investigation-only|review-only|pattern-decision-only>
Started: <ISO-8601>
Requirement: <1 line>

## Gates
GATE: <NAME> <ok|fail|blocked|escalate> — <ISO-8601> — <agent-name>
...

## RED record
RED: <test file path> — "<exact failing assertion line>" — cmd: <command> → FAIL
ATTEMPTS: <n>

## Human approvals
APPROVAL NEEDED: <subject> — <ISO-8601> — orchestrator
APPROVED: <subject> — <name> — <ISO-8601>
```

- The `RED:` line is parsed by `.agents/hooks/tdd-gate.sh` (TDD-001/TDD-002). No `RED:` line,
  no `src/**` write.
- `ATTEMPTS` counts RED→GREEN cycles for the current behavior. At 3, the orchestrator MUST
  stop and escalate (AGT-006).
- Audit: every dispatch, gate and blocked write is appended to `.agents/audit/<task-id>.log`.

## Workflows (state machines with gates)

### feature
`PLAN` (test-planner) → `PATTERN` (pattern-selector, only if a new pattern/boundary is
involved) → `RED` (unit-test-writer) → `GREEN` (implementer) → `REFACTOR` (refactorer) →
`SPEC-TESTS` (integration-test-agent, projection-test-agent, e2e-contract-test-agent — may be
dispatched in parallel, they own disjoint paths) → `REVIEW` (code, architecture, security,
performance, test reviewers — parallel) → `FIXES` (implementer, one dispatch per finding
cluster) → `REVIEW` re-run on fixes → `DONE`.
Human gates inside: ADR approval, migration approval (MIG-001), new dependency, anything
touching `src/Payment/**`.

### bugfix
`INVESTIGATE` (investigator → root cause + reproducing-test proposal) → `RED`
(unit-test-writer writes the reproducing test) → `GREEN` (implementer) → `REFACTOR` →
`REVIEW` (parallel) → `DONE`.

### refactor
Precondition: suite green, recorded in the state file. `REFACTOR` (refactorer, no behavior
change, suite green after every step) → `REVIEW` (parallel) → `DONE`. No RED — but any
behavior change found mid-refactor stops the workflow and re-enters as feature/bugfix.

### investigation-only
`INVESTIGATE` (investigator) → report handoff → `DONE`. No code, no tests written.

### review-only
`REVIEW`: orchestrator fans out the 5 reviewers in parallel on the given paths/task →
findings report → `DONE`. Fixes are routed as new feature/bugfix tasks, never done inline.

### pattern-decision-only
`PATTERN` (pattern-selector → ADR with rejected alternatives) → human approval gate → `DONE`.
ADR text travels in the handoff; the human gate commits it to `docs/adr/`.

## Delegation rules

- The orchestrator never writes `src/**` or `tests/**` (AGT-004); it dispatches.
- Parallel: the 5 reviewers; the 3 specialized test agents. Everything else is sequential.
- The TDD cycle (RED→GREEN→REFACTOR) is strictly sequential, one behavior per cycle (TDD-003).
- Max 3 RED→GREEN attempts per behavior, then `ESCALATE` with the full attempt log (AGT-006).
- Reviewer conflicts: precedence = security > correctness > architecture > performance >
  style. A finding citing a Hard NEVER (AGENTS.md) always wins. Two reviewers citing
  contradictory rule IDs → escalate to human, both findings attached.

## Human approval gates

Orchestrator MUST stop and record `APPROVAL NEEDED` before:
1. Any ADR that creates/changes a bus, an aggregate boundary, or a bounded-context boundary.
2. Any migration commit (MIG-001).
3. Any new Composer/NPM dependency.
4. Any change under `src/Payment/**` or payment configuration (SEC-001).
Work resumes only after `APPROVED` is recorded in the state file.

## Context & cost management

Minimum context per dispatch: AGENTS.md, the agent's own definition, the task state file,
the rule files scoped to the agent's paths, and the artifacts listed in the incoming handoff.
Never the whole repo. Model per agent (effort maps to model choice):

| Model | Agents |
|---|---|
| opus | orchestrator, pattern-selector, investigator, solid-guardian, architecture-reviewer, security-reviewer, mutation-test-agent |
| sonnet | test-planner, unit-test-writer, implementer, refactorer, integration-test-agent, projection-test-agent, e2e-contract-test-agent, code-reviewer, performance-reviewer, test-reviewer |

## Entry points

- `/feature <requirement>` — orchestrator creates `F-<slug>`, workflow=feature, first agent: test-planner.
- `/bugfix <symptom>` — creates `B-<slug>`, workflow=bugfix, first agent: investigator.
- `/investigate <question>` — creates `I-<slug>`, workflow=investigation-only, first agent: investigator.
- `/review <paths|task-id>` — creates `V-<slug>`, workflow=review-only, reviewers in parallel.
- `/choose-pattern <decision>` — creates `P-<slug>`, workflow=pattern-decision-only, first agent: pattern-selector.
- `/tdd <behavior>` — creates `F-tdd-<slug>`, single tdd-cycle (RED→GREEN→REFACTOR only), first agent: unit-test-writer.

## Agent lifecycle

Managed by the `manage-agents` skill; validated by `bin/harness-check`.
- Add: create `.agents/agents/<name>.md` from the roster template → add to AGENTS.md roster +
  routing table → changelog entry → `bin/harness-check` must pass.
- Modify: edit the definition → bump `version:` frontmatter (semver) → changelog entry →
  `bin/harness-check`.
- Retire: move the file to `.agents/agents/_retired/` → remove from roster, routing, workflows
  and skill mappings → changelog entry → `bin/harness-check`.
- Naming: kebab-case, role-based (`<duty>-<role>`); one mission per agent.
- Changelog: `.agents/CHANGELOG.md`, one line per change: `<ISO> <agent|skill> <added|changed|retired> <version> — <why>`.

## Failure handling

- Handoff template mismatch → rejected by completion-check → orchestrator re-dispatches the
  same agent once with the validation error → second failure → `ESCALATE` to human.
- Tests cannot run (env down) → agent stops, records `GATE: <NAME> blocked` with the exact
  error; orchestrator may run `docker compose up -d --build` then
  `docker compose exec app php bin/console doctrine:migrations:migrate -n` once, then retry
  once; still failing → `ESCALATE`. Agents never "fix" the environment silently.
- Scope violation → write blocked by `scope-guard.sh`, logged to the audit log; the agent
  MUST hand off to the owning agent instead of retrying the write.
- 3 failed RED→GREEN attempts → `ESCALATE` with hypothesis list (AGT-006).

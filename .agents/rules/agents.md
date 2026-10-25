---
id: agents
paths: [".agents/**", "src/**", "tests/**"]
---
# Agent-behavior rules

### AGT-001 — Agents write only within their declared globs
Rule: An agent MUST create or modify files only under the `Writes` globs of its definition (AGENTS.md roster); everything else is blocked.
Rationale: Scope is the only thing standing between 17 agents and chaos.
BAD:  `unit-test-writer` "fixes" `src/Shared/Domain/Money.php` to make its test pass
GOOD: writer stops, hands off to `implementer` with the RED record.
Enforced by: `scope-guard.sh` hook (AGENT_NAME + glob match) + audit log.

### AGT-002 — Every review finding cites a rule ID
Rule: Review output MUST use the format `[SEVERITY][RULE-ID] file:line — finding`; findings without a rule ID are rejected.
Rationale: Opinions without rules are unenforceable noise (AGT-001 of reviews).
BAD:  "this feels too complex"
GOOD: `[MAJOR][OCP-002] src/Payment/Application/Saga/CheckoutSaga.php:88 — match on gateway name; use tagged iterator`.
Enforced by: `completion-check.sh` hook regex + orchestrator gate.

### AGT-003 — No agent edits another agent's artifact
Rule: If agent A finds a defect in agent B's artifact, A MUST report it via handoff; the orchestrator re-dispatches B. Direct edits are forbidden.
Rationale: Silent cross-edits destroy the audit trail and the RED/GREEN record.
BAD:  refactorer rewrites unit-test-writer's test "to be cleaner" mid-cycle
GOOD: refactorer hands a finding back; orchestrator routes to test-reviewer/unit-test-writer.
Enforced by: audit log review + `completion-check.sh` (artifact ownership check).

### AGT-004 — The orchestrator never writes code or tests
Rule: The orchestrator MAY write only `.agents/state/**` and `.agents/audit/**`; any `src/**` or `tests/**` write is a hard violation.
Rationale: A planning agent that codes bypasses every quality gate it enforces.
BAD:  orchestrator "quickly fixes" a typo in a handler
GOOD: orchestrator dispatches implementer with the finding.
Enforced by: `scope-guard.sh` hook.

### AGT-005 — Handoffs follow the template; state file updated at every gate
Rule: Every agent run MUST end with the handoff template (orchestration.md) and a gate entry appended to `.agents/state/<task-id>.md`.
Rationale: The state file is the resume/audit mechanism and feeds the TDD gate (TDD-001).
BAD:  agent reports "done" in chat, state file untouched
GOOD: template + `GATE: GREEN ok — 2026-10-16T10:00Z — implementer` appended.
Enforced by: `completion-check.sh` hook + harness self-test.

### AGT-006 — Max 3 RED→GREEN attempts, then escalate
Rule: If GREEN cannot be reached in 3 cycles for one behavior, the orchestrator MUST stop the loop and escalate to a human with the full attempt log.
Rationale: Agents flailing on a hard behavior burn budget and produce contorted code.
BAD:  11 attempts rewriting both test and implementation alternately
GOOD: attempt counter in the state file; at 3 → `ESCALATE` gate with hypothesis list.
Enforced by: orchestrator gate logic + state-file schema check in harness-check.

### AGT-007 — Every declared agent is reachable from a workflow
Rule: Every agent in `.agents/agents/` MUST be named in `.agents/orchestration.md`, at the gate that dispatches it. An agent nobody can dispatch is a defect, not documentation.
Rationale: A declared-but-unrouted agent passes the roster count and the subagent export while enforcing nothing — its rules silently fall to "review by nobody".
BAD:  `solid-guardian` exists, owns OCP-*/ISP-*, and no workflow ever names it
GOOD: the `## Review fan-out` section names it with the design-bearing predicate
Enforced by: reachability loop in `bin/harness-check` + CI `harness` job.

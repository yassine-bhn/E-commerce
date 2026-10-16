# Task state

Active tasks: `.agents/state/<task-id>.md` (see `TEMPLATE.md`).

- Task-id prefixes: `F-`, `B-`, `R-`, `I-`, `V-`, `P-`, `T-` (orchestrator).
- `GATE:` lines updated at every workflow gate (AGT-006).
- `RED:` required before any `src/**` write (TDD-001, `.agents/hooks/tdd-gate.sh`).

Only the orchestrator writes state files (AGT-004).

---
id: choose-pattern
used_by: [pattern-selector, orchestrator]
---
# Choose pattern (ADR)

## When
New bus, aggregate boundary, context split, or non-obvious integration style.

## Steps
1. List requirement, constraints, and rejected alternatives.
2. Draft short ADR under `docs/adr/` after human approval gate.
3. Map decision to rule IDs in `.agents/rules/` and enforcement-matrix.
4. Hand off ADR text; orchestrator records `APPROVAL NEEDED` until `APPROVED`.

## Rules
AGT-006, orchestration human approval gates

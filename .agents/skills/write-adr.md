---
id: write-adr
used_by: [pattern-selector, orchestrator]
---
# Write ADR

## When
Pattern-decision-only workflow or human approval gate for architecture change.

## Steps
1. Title, status, context, decision, consequences, rejected alternatives.
2. Link related rule IDs (e.g. BUS-001, ECO-003) and prior ADRs.
3. Store under `docs/adr/ADR-NNNN-slug.md` only after `APPROVED` in state file.
4. Add harness changelog line if harness behavior changes.

## Rules
MIG-001, orchestration approval gates

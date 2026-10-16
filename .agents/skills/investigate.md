---
id: investigate
used_by: [investigator]
---
# Investigate (bug or question)

## When
`/bugfix`, `/investigate`, or bugfix workflow INVESTIGATE gate.

## Steps
1. Form falsifiable hypotheses; gather evidence (logs, repro, git history).
2. Identify root cause and smallest reproducing test proposal.
3. Hand off to unit-test-writer for RED or report-only for investigation-only workflow.
4. Read-only — no writes outside handoff/state if orchestrator delegates logging.

## Rules
TDD-001, AGT-001 (read-only agents)

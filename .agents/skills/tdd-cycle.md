---
id: tdd-cycle
used_by: [unit-test-writer, implementer, refactorer]
---
# TDD cycle (RED → GREEN → REFACTOR)

## When
Single behavior change; orchestrator gate is `RED`, `GREEN`, or `REFACTOR`.

## Steps
1. **RED** (unit-test-writer): one failing test, wrong reason; record `RED:` in `.agents/state/<task-id>.md` (TDD-001).
2. **GREEN** (implementer): smallest `src/**` change; never edit tests (TDD-002).
3. **REFACTOR** (refactorer): cleanup with full suite green; one behavior per cycle (TDD-003).

## Rules
TDD-001, TDD-002, TDD-003, AGT-001

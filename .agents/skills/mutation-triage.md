---
id: mutation-triage
used_by: [mutation-test-agent]
---
# Mutation triage (Infection)

## When
Pre-finish or DoD requires MSI ≥ 85 / covered ≥ 90 on touched Domain+Application code.

## Steps
1. Run `vendor/bin/infection` with project thresholds (TDD-010).
2. For each survivor, map to missing assertion or weak test; request unit-test-writer RED.
3. Never weaken production code to satisfy mutants.
4. Report survivors with rule IDs and suggested test behaviors.

## Rules
TDD-010, TDD-003

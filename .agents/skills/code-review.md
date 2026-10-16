---
id: code-review
used_by: [code-reviewer, architecture-reviewer, security-reviewer, performance-reviewer, test-reviewer]
---
# Code review (parallel REVIEW gate)

## When
Orchestrator dispatches review-only or post-implementation REVIEW.

## Steps
1. Load artifacts from handoff; scope to touched contexts only.
2. Each reviewer cites rule IDs from their domain (matrix in `.agents/enforcement-matrix.md`).
3. No direct fixes — findings go to orchestrator → implementer at FIXES.
4. Security beats correctness beats architecture beats performance beats style.

## Rules
AGT-002, role-specific rules per agent definition

---
id: solid-review
used_by: [solid-guardian, refactorer, code-reviewer]
---
# SOLID review

## When
REVIEW gate or refactor touching Application/Infrastructure boundaries.

## Steps
1. Check SRP-001..003 (class size, Http thin, subscribers).
2. OCP-002: no type-switch growth; OCP-003 strategy/config extension.
3. LSP/DIP: ports in Domain; no `container->get` (DIP-002); handlers on abstractions (DIP-003).
4. Emit findings as `[SEVERITY][RULE-ID] path:line — detail` (AGT-002).

## Rules
SRP-*, OCP-*, LSP-*, ISP-*, DIP-*

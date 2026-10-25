# Harness changelog

Format: `<ISO> <agent|skill|hook|rule|harness> <added|changed|retired> <version> — <why>`

2026-10-16 harness added 1.0.0 — initial agent harness: 14 rule topic files, 17 agents, 13 skills, 9 hooks, deptrac + custom PHPStan rules, reference slice, self-test.
2026-10-16 harness changed 1.1.0 — stack pinned to PostgreSQL 16 + Docker Compose + FrankenPHP (INF-001..003, ADR-0002).
2026-10-17 hook changed 1.2.0 — guard-cqrs.sh: grep -e for dash-leading patterns (ECO-004, QRY-001 arms).
2026-10-17 harness added 1.2.0 — 8 hook scripts + hooks.yaml, 5 reference PHPStan rules under .agents/phpstan/, 13 skills, state/audit scaffolding, bin/harness-check.
2026-10-17 rule added 1.2.0 — infrastructure.md (INF-001..003) linked from AGENTS.md stack section.
2026-10-17 skill added 1.2.1 — vendored Cursor Superpowers library (14 skills) under .agents/skills/superpowers/.
2026-10-17 harness changed 1.3.0 — merged Symfony 8.1 ecommerce-core skeleton; FrankenPHP compose, bounded-context dirs, PROJECT-START-SPEC.md.
2026-10-19 skill added 1.3.1 — spec-driven-agents domain skill + `.cursor/skills/spec-driven-agents/SKILL.md` (3 analysts → synthesizer → workers pipeline).
2026-10-19 skill changed 1.3.2 — spec-driven-agents Stage 5 harness handoff; orchestrator `/spec` and `/spec-from`; workflow spec-then-feature; gate SPEC.
2026-10-22 agent changed 1.4.0 — implementer 1.1.0: `writes:` gains the infrastructure paths (`ci-local.sh`, `compose*.yaml`, `docker/**`, `scripts/**`, `bin/**`, `Makefile`, `.github/workflows/**`) because no harness agent could legally fix a compose/CI defect; infra GREEN is proven by the reproduced command instead of a PHP test.
2026-10-22 harness changed 1.4.1 — `bin/export-qoder-harness` stops emitting `model: inherit`; Qoder rejects it with `40506 model inherit not found or has been deprecated`, which made every harness subagent undispatchable. An omitted `model:` already inherits the session model.
2026-10-23 agent changed 1.5.0 — solid-guardian was declared, exported and never dispatched: every REVIEW fan-out listed only the five core reviewers, so OCP-001/OCP-002/ISP-001/ISP-003 had no reviewer at their gate. `orchestration.md` and `orchestrator.md` gain a `## Review fan-out` section that dispatches it on design-bearing diffs; the `/feature` and `/review` entry wrappers follow.
2026-10-23 harness changed 1.5.0 — new AGT-007: every agent in `.agents/agents/` must be named in `.agents/orchestration.md`, enforced by a reachability loop in `bin/harness-check` (the roster count could not detect an unroutable agent).
2026-10-23 harness changed 1.5.1 — `bin/export-qoder-harness` cuts a subagent description at the first `. ` instead of the first `.`, so a role citing a dotted path (solid-guardian's `.agents/rules/solid.md`) no longer exports as "Review design-bearing changes against the SOLID rules in `."
2026-10-23 agent added 1.6.0 — architecture-test-writer (`tests/Architecture/**`): every other test level had a declared writer (unit, integration, projection, e2e-contract) but `tests/Architecture/**` had none, so a repository-state guard could only be written by violating AGT-001. `refactorer`'s blanket `tests/**` glob made such a write legal-but-anonymous rather than owned. Roster row, feature-workflow `SPEC-TESTS` dispatch and the delegation rule (4 specialized test agents) updated with it; AGT-007 reachability re-checked by `bin/harness-check`.

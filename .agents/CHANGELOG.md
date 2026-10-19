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

---
id: manage-agents
used_by: [orchestrator]
---
# Manage agents (harness lifecycle)

## When
Add, modify, or retire an agent in `.agents/agents/`.

## Steps
1. **Add**: kebab-case `.md` from roster template; `writes:` globs; update AGENTS.md roster + routing.
2. **Modify**: bump `version:` semver in frontmatter; changelog entry.
3. **Retire**: move to `.agents/agents/_retired/`; remove from roster, workflows, skill mappings.
4. Run `bin/harness-check` — must pass (AGT-005, AGT-006).
5. **Superpowers sync**: when the Cursor Superpowers plugin updates, re-copy `skills/` into `.agents/skills/superpowers/` (see `.agents/skills/README.md`).

## Rules
AGT-004, AGT-005, AGT-006

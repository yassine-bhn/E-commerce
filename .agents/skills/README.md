# Harness skills

## Domain skills (CQRS / e-commerce)

Fourteen flat `*.md` files at this directory root — mapped in `.agents/enforcement-matrix.md` and validated by `bin/harness-check`.

Use these for bounded-context work: commands, queries, sagas, ADRs, reviews, agent lifecycle, and spec-driven multi-agent pipelines (`spec-driven-agents.md`).

**Spec → harness:** Stages 1–4 produce `spec-run/spec.md`; **Stage 5** (default in this repo) continues with CQRS agents (`test-planner` → … → DONE). Entry: `/spec <prompt>` or `Use skill: spec-driven-agents`. Opt out: `spec-only`. Resume from an existing spec: `/spec-from spec-run/spec.md`.

Cursor also loads `.cursor/skills/spec-driven-agents/SKILL.md` for the same workflow in the IDE.

## Superpowers (Cursor plugin bundle)

Vendored copy of the [Superpowers](https://github.com/obra/superpowers) skill library under `superpowers/` (14 skills, each `superpowers/<name>/SKILL.md`).

| Skill | Use when |
|---|---|
| `using-superpowers` | Start of session; how to load skills |
| `brainstorming` | Before new features or behavior changes |
| `writing-plans` | Multi-step work from a spec |
| `executing-plans` | Running a written plan with checkpoints |
| `subagent-driven-development` | Parallel tasks in one session |
| `dispatching-parallel-agents` | Independent tasks without shared state |
| `test-driven-development` | RED → GREEN → REFACTOR (pairs with `tdd-cycle.md` + TDD-001) |
| `systematic-debugging` | Bugs and unexpected failures |
| `verification-before-completion` | Before claiming done (pairs with pre-finish hook) |
| `requesting-code-review` / `receiving-code-review` | Review gates |
| `finishing-a-development-branch` | Merge / PR / cleanup |
| `using-git-worktrees` | Isolated feature work |
| `writing-skills` | Author or edit skills |

**Precedence:** Production `src/**` and `tests/**` still follow this repo’s TDD law (`.agents/rules/tdd.md`, `tdd-gate.sh`). Superpowers guides *process*; harness hooks enforce *policy*.

Update the vendored tree when bumping the Cursor Superpowers plugin; run `bin/harness-check` after sync.

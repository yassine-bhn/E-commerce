# Design Symfony CQRS E-commerce Agent Harness

> **Workspace:** /home/yassine/Desktop/freelance/E-commerce

---

**User**

# ROLE 
 You are a principal software architect with deep expertise in Symfony, Domain-Driven Design, CQRS and test-driven development. You also design instruction files, skills and multi-agent workflows for AI coding tools, so you know that vague rules get ignored and enforced rules get followed. 
 
 # GOAL 
 Produce the complete "agent harness" for a Symfony e-commerce project that applies the CQRS pattern as described in Microsoft's Azure Architecture Center guidance, and that is developed strictly test-first (TDD) by a team of specialized AI agents. The harness consists of: AGENTS.md, rules, agents, skills, orchestration, hooks, and a self-test that validates the harness itself. 
 
 # PROJECT CONTEXT 
 - Project: E-commerce application (online store with catalog, cart, checkout, orders, payments, inventory, shipping, customer accounts and a back-office). 
 - Stack: Symfony [8.y], PHP [8.x], Doctrine ORM on the write side, Doctrine DBAL/SQL on the read side, [PostgreSQL]. 
 - AI coding tool that will consume the harness: [Kimi Code / Claude Code / Codex / other]. Use that tool's real mechanisms and file locations for hooks, agents (subagents) and skills. Before designing, state which of these the tool supports natively. If something is not supported (subagents, path-scoped permissions, skills), say so and provide the closest portable fallback. Never invent tool features. 
 - Bounded contexts: propose a split, starting from: Catalog, Cart, Ordering (checkout), Payment, Inventory, Shipping, Customer/Identity, Promotions. Justify any context you merge or add, and define which events cross context boundaries. 
 - Event sourcing: no. Use a state-based write model with domain events. Only recommend event sourcing for a specific context if you can justify it. 
 - Read store: same database with separate denormalized read models/tables (product listing, cart summary, order history, admin dashboards). Say whether a search engine (Elasticsearch/Meilisearch) is worth adding for catalog search. 
 - Existing quality tooling: [PHPStan level, php-cs-fixer, Rector, Deptrac, PHPUnit/Pest, Infection, or "none, propose it"] 
 
 # ARCHITECTURAL REFERENCE 
 Follow Microsoft's CQRS pattern guidance: separate write model (commands) from read model (queries); commands are task-based and express business intent, not CRUD; queries never change state; the read side may be denormalized or use materialized views; the sides are synchronized through events; eventual consistency is a conscious trade-off; event sourcing is optional, not mandatory. Map this to Symfony Messenger with separate command, query and event buses, each with its own middleware stack. 
 Rules for honesty: if you are unsure what Microsoft's guidance says on a point, say so and label it ASSUMPTION. Do not invent quotes, URLs or version numbers. 
 
 # ENGINEERING PRINCIPLES THE HARNESS MUST ENFORCE 
 - TDD law: no production code without a failing test that fails for the right reason. Cycle: RED (one failing test) -> GREEN (minimal code) -> REFACTOR (suite stays green). One behavior per cycle. Tests describe behavior in domain language, not implementation details. 
 - Test pyramid by layer: domain unit tests (no framework, no database); handler tests with in-memory repositories; integration tests (Doctrine + Messenger, transactional); projection/read-model tests; functional/API tests and payment-gateway contract tests; mutation testing as a quality gate on the domain and application layers. 
 - SOLID translated into verifiable, Symfony-specific rules. SRP: one handler per message, no god services. OCP: extend through new handlers, strategies or tagged services, not by editing switch statements. LSP: implementations honor interface contracts without narrowing preconditions. ISP: small ports per use case. DIP: domain and application depend on interfaces owned by the inner layers, infrastructure implements them. Each principle needs at least 3 rules, each with a detection method (PHPStan, Deptrac, architecture tests, or a review checklist). 
 - Pattern choice: every new design decision picks from a catalog consistent with the architecture (command, query, aggregate, value object, domain event, process manager/saga, outbox, specification, strategy, factory, repository, anti-corruption layer, read-model projection) and is recorded as a short ADR that lists the rejected alternatives. 
 
 # E-COMMERCE-SPECIFIC REQUIREMENTS 
 The rules, hooks, skills and reference slice must cover these points explicitly: 
 - Money: a Money value object (integer minor units + currency), never floats; arithmetic only through the value object. 
 - Order placement: model checkout as a process manager/saga (reserve stock, authorize payment, confirm order) with compensating actions on failure (release stock, void payment). State which steps are synchronous and which are async. 
 - Inventory: stock reservation must be safe under concurrency (optimistic locking or atomic conditional update). Forbid read-then-write stock logic in handlers. 
 - Idempotency: commands that can be retried carry an idempotency key; payment-provider webhooks and event subscribers must be idempotent. 
 - Order history: order lines store a snapshot of price, tax and product data at purchase time; orders are never recalculated from the current catalog. 
 - Reliable events: define the outbox pattern (or justify an alternative) so events are never lost between the database commit and the bus. 
 - Eventual consistency in the UX: say where the UI may read from the write model right after a command (for example the order confirmation page) and where stale read models are acceptable. 
 - Security and compliance: never store or log card data (use provider tokens); authorization on queries (a customer can only read their own orders and addresses); personal-data deletion/anonymization must also reach read models. 
 
 # DELIVERABLES 
 
 ## 1. AGENTS.md (repo root) 
 - Maximum ~180 lines, because agents load it on every task. Link to rules, agents and skills instead of repeating them. 
 - Include: architecture overview in 10 lines, directory layout, exact commands (install, test, lint, static analysis, architecture check, mutation testing), the TDD law, the agent roster table, a routing table (task type -> workflow -> first agent), the handoff protocol summary, a Definition of Done checklist, and a short list of hard NEVERs. 
 
 ## 2. Rules 
 - One rule per file or section, scoped to the paths where it applies (use the tool's glob/path scoping if it supports it). 
 - Each rule must contain: ID (e.g. CQRS-007), one-sentence imperative statement, rationale in at most 2 lines, a BAD and a GOOD PHP example, and "Enforced by:" naming the mechanism (Deptrac, PHPStan rule, hook, test, or code review only). 
 - Cover at least: layering and allowed dependencies; command and command handler design (immutable, no return value, one handler per command, transactional boundary); query and read-model design (no writes, no flush/persist, DTO/read-model returns, DBAL or SQL on the read side); event design and idempotent subscribers; aggregates and invariants; Messenger bus configuration and middleware; naming conventions; error handling and validation placement; security and authorization placement; migrations; performance pitfalls; every point in the e-commerce section; TDD rules (test-first, naming, one behavior per test, determinism, no logic in tests, test data builders, no mocking of domain objects); SOLID rules (grouped by principle); agent-behavior rules (agents stay inside their scope, reviews must cite rule IDs, no agent edits another agent's artifacts). 
 - Every rule must be verifiable. Reject vague rules like "keep code clean". 
 
 ## 3. Agents 
 Each agent definition must contain: name; one-sentence mission; trigger ("use when..."); inputs; output in a fixed template; permissions (read-only, or the exact writable path globs and allowed commands); forbidden actions; suggested reasoning effort/model; handoff (who receives the output); Definition of Done; stop/escalation conditions. Maximum 60 lines each. 
 Required roster (merge two agents only with a written justification; the testing agents must keep distinct tasks): 
 - Orchestration: `orchestrator`. Plans, routes work, enforces workflow gates, never writes production code or tests itself. 
 - Design and analysis (read-only): 
   - `pattern-selector`: chooses the best pattern for a feature using the architecture's catalog and decision tree, outputs an ADR with rejected alternatives and the rule IDs it relies on. 
   - `investigator`: root-cause analysis for bugs and unclear behavior; hypothesis-driven; every finding backed by file:line evidence; ends with a reproducing test proposal, never edits code. 
   - `solid-guardian`: reviews a change against the SOLID rules and names the exact violation, the affected principle and a refactoring suggestion. 
 - TDD cycle: 
   - `test-planner`: turns a requirement into an ordered behavior list (happy path, edge cases, invariants, failure modes, idempotency, concurrency); writes no code. 
   - `unit-test-writer` (RED): writes ONE failing test at a time for value objects, aggregates, domain services and handlers (in-memory repositories); proves it fails for the right reason; may write only under tests/Unit. 
   - `implementer` (GREEN): the smallest production change that makes the failing test pass; may never edit tests. 
   - `refactorer`: behavior-preserving cleanup with the suite green; applies solid-guardian and architecture-reviewer findings; adds no behavior. 
 - Specialized testing: 
   - `integration-test-agent`: Doctrine mappings and repositories, Messenger buses and middleware, outbox, transactions and stock-reservation concurrency against a real test database; writes only under tests/Integration. 
   - `projection-test-agent`: read models, projections and query handlers; idempotent and replayable projections; consistency between emitted events and read tables; writes only under tests/Projection. 
   - `e2e-contract-test-agent`: HTTP/API flows (for example checkout), payment-gateway and external-service contract tests, webhook idempotency; writes only under tests/Functional and tests/Contract. 
   - `mutation-test-agent`: runs Infection on the domain and application layers, triages surviving mutants, requests the missing tests from the right testing agent, reports the score against a threshold. 
 - Review (read-only, may run in parallel): 
   - `code-reviewer`: correctness, readability, maintainability, naming, error handling. 
   - `architecture-reviewer`: CQRS and layering conformance, bus usage, bounded-context boundaries, rule IDs. 
   - `security-reviewer`: PCI/PII handling, authorization, injection, secrets, webhook verification. 
   - `performance-reviewer`: N+1 queries, read-model indexes, Messenger throughput, locking behavior. 
   - `test-reviewer`: audits tests for TDD discipline, determinism and flakiness, assertion quality, naming and pyramid balance. 
 
 ## 4. Skills 
 Use the tool's skill mechanism: a folder per skill with SKILL.md (frontmatter with name and a description that says WHEN to use it), details moved to references/ files, and scripts for deterministic steps. If skills are unsupported, give the equivalent docs and show how AGENTS.md points to them. 
 Required skills: `tdd-cycle`, `add-command`, `add-query`, `add-domain-event`, `add-read-model-projection`, `process-manager-saga`, `choose-pattern` (decision tree, pattern catalog, ADR template), `solid-review` (checklist per principle with PHP/Symfony detection tips), `code-review` (checklist, severity scale, comment format citing rule IDs), `investigate` (hypothesis-driven debugging, evidence log, reproduce-as-failing-test), `mutation-triage`, `write-adr`, `manage-agents` (see section 5). 
 Each SKILL.md: maximum 100 lines, with a trigger description, numbered steps, templates, verification commands and a "done when" checklist. Add a table mapping each skill to the agents that use it. 
 
 ## 5. Orchestration and agent management 
 - Workflows as explicit state machines with gates: feature (plan -> pattern -> RED -> GREEN -> REFACTOR -> specialized tests -> parallel review -> fixes -> done), bugfix (investigate -> reproduce as failing test -> GREEN -> REFACTOR -> review), refactor, investigation-only, review-only, pattern-decision-only. 
 - Delegation rules: the orchestrator never writes code; which agents may run in parallel (reviewers, independent test layers) and which must run sequentially (the TDD cycle); a maximum number of RED->GREEN attempts (default 3) before escalating to a human; how conflicts between reviewers are resolved. 
 - Handoff contract: provide the exact template (task id, summary, artifacts with paths, rule IDs applied or violated, test commands run with results, open questions, next agent). 
 - Shared state: a task state file (for example .agents/state/<task-id>.md) updated at every gate and used for resume and audit; define its format. 
 - Human approval gates: ADRs that change bus or aggregate boundaries, migrations, new dependencies, anything touching payments. 
 - Context and cost management: the minimum context each agent receives and the reasoning effort per agent (low/high/max where the tool supports it). 
 - Entry points: slash commands or prompt shortcuts (`/feature`, `/bugfix`, `/investigate`, `/review`, `/choose-pattern`, `/tdd`) with exact definitions. 
 - Agent lifecycle: how to add, modify and retire an agent or skill; naming and versioning; a changelog; the `manage-agents` skill automates this with validation. 
 - Failure handling: what happens when an agent's output does not match its template, tests cannot run, or an agent exceeds its scope. 
 
 ## 6. Hooks 
 - Provide ready-to-use hook configuration plus the scripts it calls, with exact file paths, executable bits and exit-code semantics. 
 - Post-edit hook: fast (under 10 seconds), runs php-cs-fixer and PHPStan only on changed files. 
 - Pre-commit/pre-finish hook: full static analysis, Deptrac layer check, the relevant test suites, and a mutation-score check on changed domain/application files. 
 - Guard hooks that block: edits to vendor/ or generated files, committing secrets or .env files, query handlers that call persist/flush or dispatch commands, commands or command handlers that return values, domain code importing from Infrastructure or Symfony HTTP classes, float usage for money, logging of payment card data. 
 - Agent and TDD hooks: 
   - Scope guards: each agent may write only its allowed paths (for example `unit-test-writer` only tests/Unit/**, `implementer` only src/** and never tests/**); violations are blocked with an actionable message. 
   - TDD gate: block production-code edits unless the task state file records a RED run (failing test, right reason) for the current task; explain how that record is produced. 
   - Sub-agent completion check: reject agent outputs that do not match the handoff template. 
   - Audit log of agent actions per task. 
 - Each hook must say which rule ID it enforces and print a clear failure message the agent can act on. 
 - Include the Deptrac configuration (or equivalent) and any custom PHPStan rule needed, as real code. 
 
 ## 7. Enforcement matrix 
 A table: Rule ID | Mechanism | File where it is enforced | Auto-fixable (yes/no). Extend it to cover agent scope rules, TDD gates and skills. 
 
 ## 8. Reference slice and worked walkthrough 
 One minimal end-to-end vertical slice that obeys every rule: a PlaceOrder command + handler (with stock reservation and an idempotency key), an order-history query + handler + read model, and an OrderPlaced event + projection subscriber, with tests at every layer. Then a step-by-step walkthrough of the feature workflow on this slice: which agent is invoked at each step, what it receives and returns (handoff), and the RED/GREEN/REFACTOR commits. 
 
 ## 9. Harness self-test 
 Real code (script plus CI step) that validates the harness: every agent has frontmatter, permissions, an output template and a Definition of Done; every rule ID referenced anywhere exists; every skill is mapped to an agent; every hook script exists and is executable; no agent can write outside its declared globs; each workflow's gates reference existing agents. 
 
 # PROCESS 
 1. State which agent, skill and hook features the target tool supports natively and where you use fallbacks. Then list your other assumptions (max 10 bullets). Do not stop to ask questions unless something blocks the work entirely. 
 2. Produce the deliverables in the order above. 
 3. Self-review before finishing: check that every rule referenced in AGENTS.md exists, every hook maps to a rule, every agent in the roster has a definition and a workflow step, every skill is used by at least one agent, every command you cite is real, and the reference slice would pass your own Deptrac and PHPStan config. Fix problems, then list remaining risks. 
 
 # OUTPUT FORMAT 
 - Each file in its own fenced code block, preceded by its full path. 
 - No introductions, no praise, no generic Symfony, CQRS or TDD tutorial text. 
 - Anything you could not verify must be marked ASSUMPTION or TO VERIFY. 
 - If you reach your output limit, stop at the end of a complete file and finish with: CONTINUE FROM: <deliverable number and file name>.

**User**

# ROLE 
 You are a principal software architect with deep expertise in Symfony, Domain-Driven Design, CQRS and test-driven development. You also design instruction files, skills and multi-agent workflows for AI coding tools, so you know that vague rules get ignored and enforced rules get followed. 
 
 # GOAL 
 Produce the complete "agent harness" for a Symfony e-commerce project that applies the CQRS pattern as described in Microsoft's Azure Architecture Center guidance, and that is developed strictly test-first (TDD) by a team of specialized AI agents. The harness consists of: AGENTS.md, rules, agents, skills, orchestration, hooks, and a self-test that validates the harness itself. 
 
 # PROJECT CONTEXT 
 - Project: E-commerce application (online store with catalog, cart, checkout, orders, payments, inventory, shipping, customer accounts and a back-office). 
 - Stack: Symfony [x.y], PHP [8.x], Doctrine ORM on the write side, Doctrine DBAL/SQL on the read side, [PostgreSQL / MySQL]. 
 - AI coding tool that will consume the harness: [Kimi Code / Claude Code / Codex / other]. Use that tool's real mechanisms and file locations for hooks, agents (subagents) and skills. Before designing, state which of these the tool supports natively. If something is not supported (subagents, path-scoped permissions, skills), say so and provide the closest portable fallback. Never invent tool features. 
 - Bounded contexts: propose a split, starting from: Catalog, Cart, Ordering (checkout), Payment, Inventory, Shipping, Customer/Identity, Promotions. Justify any context you merge or add, and define which events cross context boundaries. 
 - Event sourcing: no. Use a state-based write model with domain events. Only recommend event sourcing for a specific context if you can justify it. 
 - Read store: same database with separate denormalized read models/tables (product listing, cart summary, order history, admin dashboards). Say whether a search engine (Elasticsearch/Meilisearch) is worth adding for catalog search. 
 - Existing quality tooling: [PHPStan level, php-cs-fixer, Rector, Deptrac, PHPUnit/Pest, Infection, or "none, propose it"] 
 
 # ARCHITECTURAL REFERENCE 
 Follow Microsoft's CQRS pattern guidance: separate write model (commands) from read model (queries); commands are task-based and express business intent, not CRUD; queries never change state; the read side may be denormalized or use materialized views; the sides are synchronized through events; eventual consistency is a conscious trade-off; event sourcing is optional, not mandatory. Map this to Symfony Messenger with separate command, query and event buses, each with its own middleware stack. 
 Rules for honesty: if you are unsure what Microsoft's guidance says on a point, say so and label it ASSUMPTION. Do not invent quotes, URLs or version numbers. 
 
 # ENGINEERING PRINCIPLES THE HARNESS MUST ENFORCE 
 - TDD law: no production code without a failing test that fails for the right reason. Cycle: RED (one failing test) -> GREEN (minimal code) -> REFACTOR (suite stays green). One behavior per cycle. Tests describe behavior in domain language, not implementation details. 
 - Test pyramid by layer: domain unit tests (no framework, no database); handler tests with in-memory repositories; integration tests (Doctrine + Messenger, transactional); projection/read-model tests; functional/API tests and payment-gateway contract tests; mutation testing as a quality gate on the domain and application layers. 
 - SOLID translated into verifiable, Symfony-specific rules. SRP: one handler per message, no god services. OCP: extend through new handlers, strategies or tagged services, not by editing switch statements. LSP: implementations honor interface contracts without narrowing preconditions. ISP: small ports per use case. DIP: domain and application depend on interfaces owned by the inner layers, infrastructure implements them. Each principle needs at least 3 rules, each with a detection method (PHPStan, Deptrac, architecture tests, or a review checklist). 
 - Pattern choice: every new design decision picks from a catalog consistent with the architecture (command, query, aggregate, value object, domain event, process manager/saga, outbox, specification, strategy, factory, repository, anti-corruption layer, read-model projection) and is recorded as a short ADR that lists the rejected alternatives. 
 
 # E-COMMERCE-SPECIFIC REQUIREMENTS 
 The rules, hooks, skills and reference slice must cover these points explicitly: 
 - Money: a Money value object (integer minor units + currency), never floats; arithmetic only through the value object. 
 - Order placement: model checkout as a process manager/saga (reserve stock, authorize payment, confirm order) with compensating actions on failure (release stock, void payment). State which steps are synchronous and which are async. 
 - Inventory: stock reservation must be safe under concurrency (optimistic locking or atomic conditional update). Forbid read-then-write stock logic in handlers. 
 - Idempotency: commands that can be retried carry an idempotency key; payment-provider webhooks and event subscribers must be idempotent. 
 - Order history: order lines store a snapshot of price, tax and product data at purchase time; orders are never recalculated from the current catalog. 
 - Reliable events: define the outbox pattern (or justify an alternative) so events are never lost between the database commit and the bus. 
 - Eventual consistency in the UX: say where the UI may read from the write model right after a command (for example the order confirmation page) and where stale read models are acceptable. 
 - Security and compliance: never store or log card data (use provider tokens); authorization on queries (a customer can only read their own orders and addresses); personal-data deletion/anonymization must also reach read models. 
 
 # DELIVERABLES 
 
 ## 1. AGENTS.md (repo root) 
 - Maximum ~180 lines, because agents load it on every task. Link to rules, agents and skills instead of repeating them. 
 - Include: architecture overview in 10 lines, directory layout, exact commands (install, test, lint, static analysis, architecture check, mutation testing), the TDD law, the agent roster table, a routing table (task type -> workflow -> first agent), the handoff protocol summary, a Definition of Done checklist, and a short list of hard NEVERs. 
 
 ## 2. Rules 
 - One rule per file or section, scoped to the paths where it applies (use the tool's glob/path scoping if it supports it). 
 - Each rule must contain: ID (e.g. CQRS-007), one-sentence imperative statement, rationale in at most 2 lines, a BAD and a GOOD PHP example, and "Enforced by:" naming the mechanism (Deptrac, PHPStan rule, hook, test, or code review only). 
 - Cover at least: layering and allowed dependencies; command and command handler design (immutable, no return value, one handler per command, transactional boundary); query and read-model design (no writes, no flush/persist, DTO/read-model returns, DBAL or SQL on the read side); event design and idempotent subscribers; aggregates and invariants; Messenger bus configuration and middleware; naming conventions; error handling and validation placement; security and authorization placement; migrations; performance pitfalls; every point in the e-commerce section; TDD rules (test-first, naming, one behavior per test, determinism, no logic in tests, test data builders, no mocking of domain objects); SOLID rules (grouped by principle); agent-behavior rules (agents stay inside their scope, reviews must cite rule IDs, no agent edits another agent's artifacts). 
 - Every rule must be verifiable. Reject vague rules like "keep code clean". 
 
 ## 3. Agents 
 Each agent definition must contain: name; one-sentence mission; trigger ("use when..."); inputs; output in a fixed template; permissions (read-only, or the exact writable path globs and allowed commands); forbidden actions; suggested reasoning effort/model; handoff (who receives the output); Definition of Done; stop/escalation conditions. Maximum 60 lines each. 
 Required roster (merge two agents only with a written justification; the testing agents must keep distinct tasks): 
 - Orchestration: `orchestrator`. Plans, routes work, enforces workflow gates, never writes production code or tests itself. 
 - Design and analysis (read-only): 
   - `pattern-selector`: chooses the best pattern for a feature using the architecture's catalog and decision tree, outputs an ADR with rejected alternatives and the rule IDs it relies on. 
   - `investigator`: root-cause analysis for bugs and unclear behavior; hypothesis-driven; every finding backed by file:line evidence; ends with a reproducing test proposal, never edits code. 
   - `solid-guardian`: reviews a change against the SOLID rules and names the exact violation, the affected principle and a refactoring suggestion. 
 - TDD cycle: 
   - `test-planner`: turns a requirement into an ordered behavior list (happy path, edge cases, invariants, failure modes, idempotency, concurrency); writes no code. 
   - `unit-test-writer` (RED): writes ONE failing test at a time for value objects, aggregates, domain services and handlers (in-memory repositories); proves it fails for the right reason; may write only under tests/Unit. 
   - `implementer` (GREEN): the smallest production change that makes the failing test pass; may never edit tests. 
   - `refactorer`: behavior-preserving cleanup with the suite green; applies solid-guardian and architecture-reviewer findings; adds no behavior. 
 - Specialized testing: 
   - `integration-test-agent`: Doctrine mappings and repositories, Messenger buses and middleware, outbox, transactions and stock-reservation concurrency against a real test database; writes only under tests/Integration. 
   - `projection-test-agent`: read models, projections and query handlers; idempotent and replayable projections; consistency between emitted events and read tables; writes only under tests/Projection. 
   - `e2e-contract-test-agent`: HTTP/API flows (for example checkout), payment-gateway and external-service contract tests, webhook idempotency; writes only under tests/Functional and tests/Contract. 
   - `mutation-test-agent`: runs Infection on the domain and application layers, triages surviving mutants, requests the missing tests from the right testing agent, reports the score against a threshold. 
 - Review (read-only, may run in parallel): 
   - `code-reviewer`: correctness, readability, maintainability, naming, error handling. 
   - `architecture-reviewer`: CQRS and layering conformance, bus usage, bounded-context boundaries, rule IDs. 
   - `security-reviewer`: PCI/PII handling, authorization, injection, secrets, webhook verification. 
   - `performance-reviewer`: N+1 queries, read-model indexes, Messenger throughput, locking behavior. 
   - `test-reviewer`: audits tests for TDD discipline, determinism and flakiness, assertion quality, naming and pyramid balance. 
 
 ## 4. Skills 
 Use the tool's skill mechanism: a folder per skill with SKILL.md (frontmatter with name and a description that says WHEN to use it), details moved to references/ files, and scripts for deterministic steps. If skills are unsupported, give the equivalent docs and show how AGENTS.md points to them. 
 Required skills: `tdd-cycle`, `add-command`, `add-query`, `add-domain-event`, `add-read-model-projection`, `process-manager-saga`, `choose-pattern` (decision tree, pattern catalog, ADR template), `solid-review` (checklist per principle with PHP/Symfony detection tips), `code-review` (checklist, severity scale, comment format citing rule IDs), `investigate` (hypothesis-driven debugging, evidence log, reproduce-as-failing-test), `mutation-triage`, `write-adr`, `manage-agents` (see section 5). 
 Each SKILL.md: maximum 100 lines, with a trigger description, numbered steps, templates, verification commands and a "done when" checklist. Add a table mapping each skill to the agents that use it. 
 
 ## 5. Orchestration and agent management 
 - Workflows as explicit state machines with gates: feature (plan -> pattern -> RED -> GREEN -> REFACTOR -> specialized tests -> parallel review -> fixes -> done), bugfix (investigate -> reproduce as failing test -> GREEN -> REFACTOR -> review), refactor, investigation-only, review-only, pattern-decision-only. 
 - Delegation rules: the orchestrator never writes code; which agents may run in parallel (reviewers, independent test layers) and which must run sequentially (the TDD cycle); a maximum number of RED->GREEN attempts (default 3) before escalating to a human; how conflicts between reviewers are resolved. 
 - Handoff contract: provide the exact template (task id, summary, artifacts with paths, rule IDs applied or violated, test commands run with results, open questions, next agent). 
 - Shared state: a task state file (for example .agents/state/<task-id>.md) updated at every gate and used for resume and audit; define its format. 
 - Human approval gates: ADRs that change bus or aggregate boundaries, migrations, new dependencies, anything touching payments. 
 - Context and cost management: the minimum context each agent receives and the reasoning effort per agent (low/high/max where the tool supports it). 
 - Entry points: slash commands or prompt shortcuts (`/feature`, `/bugfix`, `/investigate`, `/review`, `/choose-pattern`, `/tdd`) with exact definitions. 
 - Agent lifecycle: how to add, modify and retire an agent or skill; naming and versioning; a changelog; the `manage-agents` skill automates this with validation. 
 - Failure handling: what happens when an agent's output does not match its template, tests cannot run, or an agent exceeds its scope. 
 
 ## 6. Hooks 
 - Provide ready-to-use hook configuration plus the scripts it calls, with exact file paths, executable bits and exit-code semantics. 
 - Post-edit hook: fast (under 10 seconds), runs php-cs-fixer and PHPStan only on changed files. 
 - Pre-commit/pre-finish hook: full static analysis, Deptrac layer check, the relevant test suites, and a mutation-score check on changed domain/application files. 
 - Guard hooks that block: edits to vendor/ or generated files, committing secrets or .env files, query handlers that call persist/flush or dispatch commands, commands or command handlers that return values, domain code importing from Infrastructure or Symfony HTTP classes, float usage for money, logging of payment card data. 
 - Agent and TDD hooks: 
   - Scope guards: each agent may write only its allowed paths (for example `unit-test-writer` only tests/Unit/**, `implementer` only src/** and never tests/**); violations are blocked with an actionable message. 
   - TDD gate: block production-code edits unless the task state file records a RED run (failing test, right reason) for the current task; explain how that record is produced. 
   - Sub-agent completion check: reject agent outputs that do not match the handoff template. 
   - Audit log of agent actions per task. 
 - Each hook must say which rule ID it enforces and print a clear failure message the agent can act on. 
 - Include the Deptrac configuration (or equivalent) and any custom PHPStan rule needed, as real code. 
 
 ## 7. Enforcement matrix 
 A table: Rule ID | Mechanism | File where it is enforced | Auto-fixable (yes/no). Extend it to cover agent scope rules, TDD gates and skills. 
 
 ## 8. Reference slice and worked walkthrough 
 One minimal end-to-end vertical slice that obeys every rule: a PlaceOrder command + handler (with stock reservation and an idempotency key), an order-history query + handler + read model, and an OrderPlaced event + projection subscriber, with tests at every layer. Then a step-by-step walkthrough of the feature workflow on this slice: which agent is invoked at each step, what it receives and returns (handoff), and the RED/GREEN/REFACTOR commits. 
 
 ## 9. Harness self-test 
 Real code (script plus CI step) that validates the harness: every agent has frontmatter, permissions, an output template and a Definition of Done; every rule ID referenced anywhere exists; every skill is mapped to an agent; every hook script exists and is executable; no agent can write outside its declared globs; each workflow's gates reference existing agents. 
 
 # PROCESS 
 1. State which agent, skill and hook features the target tool supports natively and where you use fallbacks. Then list your other assumptions (max 10 bullets). Do not stop to ask questions unless something blocks the work entirely. 
 2. Produce the deliverables in the order above. 
 3. Self-review before finishing: check that every rule referenced in AGENTS.md exists, every hook maps to a rule, every agent in the roster has a definition and a workflow step, every skill is used by at least one agent, every command you cite is real, and the reference slice would pass your own Deptrac and PHPStan config. Fix problems, then list remaining risks. 
 
 # OUTPUT FORMAT 
 - Each file in its own fenced code block, preceded by its full path. 
 - No introductions, no praise, no generic Symfony, CQRS or TDD tutorial text. 
 - Anything you could not verify must be marked ASSUMPTION or TO VERIFY. 
 - If you reach your output limit, stop at the end of a complete file and finish with: CONTINUE FROM: <deliverable number and file name>.

**Assistant**

## Step 1 — Tool capability statement and assumptions

**Target tool: TraeCode (Kimi Code)**, since the placeholder was not filled.

Native support I rely on:
- **Subagents**: yes — the Task tool runs subagents defined by agent-definition files with frontmatter (incl. `model`). I use `.agents/agents/*.md`. Exact discovery path is TO VERIFY against your TraeCode version; the harness-check script (§9) validates the files themselves, not their registration.
- **Skills**: yes — Skill tool with a folder per skill and `SKILL.md` frontmatter. I use `.agents/skills/<name>/SKILL.md`.
- **Hooks**: partially — a hooks environment exists, but the exact event/config schema is TO VERIFY. I therefore deliver `.agents/hooks/hooks.yaml` (declarative mapping) + portable POSIX scripts with exit-code semantics; wire the events to the real schema in one adapter step.
- **Model/effort per agent**: model override exists (sonnet/opus). "Reasoning effort low/high/max" beyond model choice is ASSUMPTION — I map effort to model choice.

Not supported natively → portable fallbacks used:
- **Per-agent path-scoped write permissions** → `scope-guard.sh` hook reading `AGENT_NAME` + declared globs (AGT-001).
- **Path-scoped rules** → `paths:` frontmatter in each rules file, consumed by hooks and harness-check.
- **Slash commands** → routing table in AGENTS.md + skills as entry points (`/feature` etc. are prompt shortcuts defined in §5).
- **TDD gate** → `tdd-gate.sh` hook + task state file (TDD-001).

Other assumptions (max 10):
1. Symfony 7.3 / PHP 8.3 / PostgreSQL 16 (TO VERIFY against composer.json; MySQL works except `ON CONFLICT` syntax in idempotency SQL).
2. Quality tooling was "none" → proposed: PHPUnit 11 (testsuites Unit/Integration/Projection/Functional/Contract), PHPStan level 8 + strict-rules + symfony extension, php-cs-fixer, Deptrac 3, Infection. Rector optional, not enforced.
3. Microsoft's CQRS guidance does not prescribe three separate Messenger buses; that mapping is ours (ASSUMPTION). Its core points I rely on: separate models, task-based commands, queries never mutate, denormalized read side, event-based sync, ES optional.
4. Monolith, one database; no microservices.
5. Checkout: PlaceOrder + stock reservation synchronous (one TX); payment authorization and order confirmation asynchronous via the saga.
6. Meilisearch adopted for storefront catalog search (typo tolerance, facets, ranking); SQL is fine for listings/admin (ECO-008).
7. No event sourcing anywhere; Payment audit via append-only `payment_ledger` table (sufficient for dispute/audit needs without ES complexity).
8. `bin/harness-check` and all hook scripts cited below are delivered in §6/§9, so every command in AGENTS.md is real once the harness is installed.
9. PII = customer name, email, addresses; anonymization propagates via `CustomerAnonymized` event (SEC-004).
10. All rule enforcement files (deptrac.yaml, custom PHPStan rules, hooks) referenced in §2 are delivered in §6.

---

**AGENTS.md**

```markdown
# AGENTS.md — E-commerce CQRS Harness

Load on every task. Rules, agents, skills, hooks are linked, never repeated here.

## Architecture (10 lines)
1. Modular monolith, 8 bounded contexts: Catalog, Cart, Ordering, Payment, Inventory,
   Shipping, Identity, Promotions — split justified in [ADR-0001](docs/adr/ADR-0001-bounded-contexts.md).
2. CQRS (Azure guidance): write model = commands + aggregates + Doctrine ORM;
   read model = queries + denormalized `*_view` tables + Doctrine DBAL/SQL.
3. Three Messenger buses, each with its own middleware: `command.bus` (sync),
   `query.bus` (sync), `event.bus` (async) — see [.agents/rules/messenger.md](.agents/rules/messenger.md).
4. Commands are task-based, immutable, return void, exactly one handler; the handler is the transaction boundary.
5. Aggregates record domain events; events go to the `outbox` table in the SAME transaction,
   a relay publishes to `event.bus`; every subscriber is idempotent.
6. Query handlers: DBAL-only, authorized per requester, return DTOs; never persist/flush/dispatch.
7. Storefront catalog search: Meilisearch projection (ECO-008); all other reads are SQL.
8. State-based write model; no event sourcing; Payment audit via append-only `payment_ledger`.
9. Checkout = process-manager saga: PlaceOrder + stock reservation SYNC → payment authorization
   ASYNC → confirmation ASYNC; compensations ReleaseStock / VoidPayment (ECO-003).
10. Layers per context: `Domain ← Application ← Infrastructure`; cross-context only via
    events or an anti-corruption layer — enforced by Deptrac.

## Directory layout
    src/<Context>/{Domain,Application,Infrastructure}/
      Domain/{Model,Event,Repository,Service}              # ports (interfaces) live here
      Application/{Command,CommandHandler,Query,Saga}      # command & query DTOs, saga
      Infrastructure/{Persistence,ReadModel,Projection,Http,MessageHandler}
    src/Shared/{Domain,Application,Infrastructure}         # Money, Id, bus interfaces, outbox
    tests/{Unit,Integration,Projection,Functional,Contract}/<Context>/...
    tests/Architecture/                                    # naming/structure tests
    docs/adr/                                              # one short ADR per design decision
    .agents/{rules,agents,skills,hooks,state,audit,phpstan}
    .agents/state/<task-id>.md                             # task state, updated at every gate

## Commands (repo root)
    composer install && docker compose up -d
    php bin/console doctrine:migrations:migrate -n && php bin/console doctrine:migrations:migrate -n --env=test
    vendor/bin/phpunit --testsuite=Unit
    vendor/bin/phpunit --testsuite=Integration
    vendor/bin/phpunit --testsuite=Projection
    vendor/bin/phpunit --testsuite=Functional
    vendor/bin/phpunit --testsuite=Contract
    vendor/bin/php-cs-fixer fix --dry-run --diff          # drop --dry-run to fix
    vendor/bin/phpstan analyse
    vendor/bin/deptrac analyse --config-file=deptrac.yaml
    vendor/bin/infection --threads=max --min-msi=85 --min-covered-msi=90
    bin/harness-check                                      # validates this harness

## TDD law
No production code without a failing test that fails for the right reason
([.agents/rules/tdd.md](.agents/rules/tdd.md)). RED: ONE failing test, failure output recorded
in `.agents/state/<task-id>.md`. GREEN: smallest production change, never edit tests.
REFACTOR: suite stays green. One behavior per cycle.

## Agent roster (definitions: [.agents/agents/](.agents/agents/))
| Agent | Writes | Mission |
|---|---|---|
| orchestrator | .agents/state/**, .agents/audit/** | plans, routes, enforces workflow gates |
| pattern-selector | read-only | picks pattern, outputs ADR with rejected alternatives |
| investigator | read-only | hypothesis-driven root cause + reproducing-test proposal |
| solid-guardian | read-only | SOLID review citing SOLID-* rule IDs |
| test-planner | read-only | requirement → ordered behavior list |
| unit-test-writer | tests/Unit/** | RED: one failing test at a time |
| implementer | src/** (never tests/**) | GREEN: minimal passing change |
| refactorer | src/**, tests/** | behavior-preserving cleanup, suite green |
| integration-test-agent | tests/Integration/** | Doctrine, Messenger, outbox, concurrency |
| projection-test-agent | tests/Projection/** | read models, idempotent/replayable projections |
| e2e-contract-test-agent | tests/Functional/**, tests/Contract/** | HTTP flows, gateway contracts, webhooks |
| mutation-test-agent | read-only (runs infection) | triage survivors, request missing tests |
| code-reviewer | read-only | correctness, readability, naming, error handling |
| architecture-reviewer | read-only | CQRS/layering/context boundaries, cites rule IDs |
| security-reviewer | read-only | PCI/PII, authorization, injection, webhooks |
| performance-reviewer | read-only | N+1, indexes, throughput, locking |
| test-reviewer | read-only | TDD discipline, determinism, pyramid balance |

## Routing
| Task type | Workflow | First agent | Entry |
|---|---|---|---|
| new feature | feature | test-planner | /feature |
| bug | bugfix | investigator | /bugfix |
| unclear behavior | investigation-only | investigator | /investigate |
| review only | review-only | orchestrator → reviewers in parallel | /review |
| design decision | pattern-decision-only | pattern-selector | /choose-pattern |
| single TDD cycle | tdd-cycle | unit-test-writer | /tdd |

## Handoff (full contract: [.agents/orchestration.md](.agents/orchestration.md))
Every agent returns the handoff template: task id, summary, artifacts (paths), rule IDs
applied/violated, commands run + results, open questions, next agent. Each gate updates
`.agents/state/<task-id>.md`. Non-conforming output is rejected by the completion hook,
re-dispatched once, then escalated to a human.

## Definition of Done
- [ ] Unit, Integration, Projection, Functional, Contract suites green
- [ ] phpstan, php-cs-fixer, deptrac clean
- [ ] infection MSI ≥ 85 / covered MSI ≥ 90 on touched Domain+Application code
- [ ] Parallel reviews approved; every finding cited a rule ID and is fixed or ADR'd
- [ ] ADR written for any new pattern or boundary decision
- [ ] State file at gate DONE; audit log complete

## Hard NEVERs
- NEVER use float for money (ECO-001)
- NEVER persist/flush/dispatch in a query handler (QRY-001)
- NEVER store or log card data; provider tokens only (SEC-001)
- NEVER read-then-write stock; atomic conditional update only (ECO-004)
- NEVER write production code without a RED record (TDD-001)
- NEVER write outside your agent's declared globs (AGT-001)
- NEVER commit vendor/, .env or secrets (SEC-005)
- NEVER dispatch domain events outside the outbox (EVT-002)
- NEVER recalculate orders from the current catalog (ECO-006)
- NEVER edit tests to reach GREEN; change production code (TDD-002)
```

**docs/adr/ADR-0001-bounded-contexts.md**

```markdown
# ADR-0001 — Bounded context split
Status: accepted. Relies on: LAY-004, ECO-006.

## Decision
8 contexts, none merged: Catalog, Cart, Ordering, Payment, Inventory, Shipping, Identity, Promotions.
- Cart stays separate from Ordering: carts are anonymous, expirable, high-churn, eventually
  consistent; orders are immutable financial records. Different consistency boundaries.
- Promotions stays separate: pricing rules change daily and are owned by marketing; a rule
  engine must not destabilize Ordering. Prices enter Ordering as snapshots (ECO-006).
- Back-office is NOT a context: it is a UI over read models of all contexts.

## Events crossing context boundaries
| Event | From → To |
|---|---|
| CartCheckedOut | Cart → Ordering |
| OrderPlaced | Ordering → Payment, Inventory, Promotions |
| StockReserved / StockReservationFailed | Inventory → Ordering (saga) |
| PaymentAuthorized / PaymentAuthorizationFailed | Payment → Ordering (saga) |
| OrderConfirmed | Ordering → Shipping, Cart (close cart) |
| ShipmentDispatched | Shipping → Ordering, Identity (notification) |
| CustomerRegistered / CustomerAnonymized | Identity → all read models holding PII |
| CouponRedeemed | Promotions → Ordering |

## Rejected alternatives
- Cart merged into Ordering (rejected: couples anonymous traffic to financial invariants).
- One shared "Shop" context (rejected: god context, Deptrac unenforceable).
- Event sourcing for Payment (rejected: audit need is met by append-only payment_ledger).
```

---

## Deliverable 2 — Rules

**`.agents/rules/layering.md`**

```markdown
---
id: layering
paths: ["src/**"]
---
# Layering rules (enforced by deptrac.yaml, §6)

### LAY-001 — Domain is dependency-free
Scope: `src/*/Domain/**`
Rule: Domain code MUST NOT import from Application, Infrastructure, Symfony HttpKernel/HttpFoundation, or Doctrine ORM.
Rationale: The domain model is the stable core; framework coupling makes unit tests slow and rules unverifiable.
BAD:  `use Symfony\Component\HttpFoundation\Request;` inside `src/Ordering/Domain/Model/Order.php`
GOOD: `Order` depends only on `OrderId`, `Money`, `OrderLine` (Domain) and `Shared\Domain` types.
Enforced by: Deptrac layers `Domain`/`Application`/`Infrastructure` + `guard-domain-imports.sh` hook.

### LAY-002 — Application depends on Domain only
Scope: `src/*/Application/**`
Rule: Application code (commands, handlers, queries, sagas) MUST NOT import Infrastructure, Doctrine ORM, or Symfony HTTP classes; Messenger interfaces are allowed.
Rationale: Handlers orchestrate ports owned by the inner layers (DIP-001); infrastructure is replaceable.
BAD:  `use Doctrine\ORM\EntityManagerInterface;` in `PlaceOrderHandler`
GOOD: constructor takes `OrderRepository` (port) + `ClockInterface`; Doctrine impl lives in Infrastructure.
Enforced by: Deptrac + `guard-domain-imports.sh` hook.

### LAY-003 — Infrastructure implements ports, inner layers never name it
Scope: `src/**`
Rule: Only Infrastructure may import vendors (ORM, DBAL, HTTP, Meilisearch, payment SDK); no Domain/Application file may reference an Infrastructure class.
Rationale: DIP-001: inner layers own the interfaces, outer layers implement them.
BAD:  `PlaceOrderHandler` type-hints `DoctrineOrderRepository`
GOOD: `PlaceOrderHandler` type-hints `OrderRepository`; binding via `services.yaml` alias.
Enforced by: Deptrac + PHPStan (constructor param types) + architecture-reviewer.

### LAY-004 — No cross-context reads; integrate via events or ACL
Scope: `src/**`
Rule: Context X MUST NOT import `Domain`/`Application` classes of context Y; integration happens via domain events or an explicit anti-corruption layer in `X/Infrastructure/Acl`.
Rationale: Direct reads re-couple the monolith into a big ball of mud and break ECO-006.
BAD:  `Ordering\Application\CommandHandler\PlaceOrderHandler` imports `Catalog\Domain\Model\Product`
GOOD: Handler calls `CatalogPricing` ACL port returning a `PriceSnapshot` DTO; order lines store the snapshot.
Enforced by: Deptrac `context` ruleset + architecture-reviewer.
```

**`.agents/rules/commands.md`**

```markdown
---
id: commands
paths: ["src/*/Application/Command/**", "src/*/Application/CommandHandler/**", "src/*/Application/Saga/**"]
---
# Command rules

### CMD-001 — Commands are task-based, never CRUD
Scope: `src/*/Application/Command/**`
Rule: Name every command `<Verb><Noun>` expressing one business task; forbid `Create/Update/Delete<entity>` with nullable field bags.
Rationale: Task-based commands carry business intent (Azure CQRS guidance); CRUD commands hide invariants in controllers.
BAD:  `final class UpdateOrder { public ?string $status = null; public ?array $lines = null; }`
GOOD: `final class ConfirmOrder { public function __construct(public readonly OrderId $orderId) {} }`
Enforced by: NAM-001 architecture test (`tests/Architecture/NamingTest.php`) + code-reviewer.

### CMD-002 — Commands are immutable
Scope: `src/*/Application/Command/**`
Rule: Commands MUST be `final` with `readonly` properties set only via constructor; no setters, no public mutable state.
Rationale: Commands travel through middleware and retries; mutation makes retries non-deterministic (ECO-005).
BAD:  `class PlaceOrder { public array $lines; public function setLines(array $l): void {...} }`
GOOD: `final class PlaceOrder { public function __construct(public readonly CustomerId $customerId, public readonly array $lines, public readonly string $idempotencyKey) {} }`
Enforced by: phpstan-strict-rules + `guard-cqrs.sh` hook (greps `function set` in Command dirs).

### CMD-003 — Commands and handlers return void
Scope: `src/*/Application/**`
Rule: A command MUST have no getter for results and its handler MUST declare `: void`; callers obtain outcomes via the read model or a synchronous write-side confirmation query (ECO-007).
Rationale: Returning the aggregate couples callers to the write model and breaks the CQRS separation.
BAD:  `public function __invoke(PlaceOrder $c): Order { ... return $order; }`
GOOD: `public function __invoke(PlaceOrder $c): void { ...; $this->orders->save($order); }`
Enforced by: custom PHPStan rule `HandlerReturnVoidRule` (`.agents/phpstan/`, §6) + `guard-cqrs.sh` hook.

### CMD-004 — Exactly one handler per command
Scope: `src/**`
Rule: Each command class MUST be handled by exactly one `#[AsMessageHandler]` on `command.bus`; zero or two handlers is a build failure.
Rationale: Ambiguous handling makes transactions and idempotency unverifiable.
BAD:  two classes handling `PlaceOrder`, one "for logging"
GOOD: one `PlaceOrderHandler`; logging lives in command.bus middleware.
Enforced by: architecture test counting handlers per command + `php bin/console debug:messenger` in CI.

### CMD-005 — The handler is the transaction boundary
Scope: `src/*/Application/CommandHandler/**`
Rule: One command = one database transaction opened by `doctrine_transaction` middleware; the handler flushes once at the end and MUST NOT flush inside loops, subscribers, or the domain.
Rationale: Partial commits break aggregates and leave the outbox inconsistent with state.
BAD:  `$this->em->flush()` inside a `foreach ($order->lines() ...)` and again in an event subscriber
GOOD: handler: load → `$order->place()` → `$this->orders->save($order)` (single flush) → outbox written in same TX.
Enforced by: integration test asserting outbox row exists atomically with order + code-reviewer.

### CMD-006 — Retriable commands carry an idempotency key
Scope: `src/*/Application/Command/**`
Rule: Any command triggered by a retryable source (HTTP submit, webhook, consumer retry) MUST implement `RetryableCommand { public function idempotencyKey(): string; }`; its handler MUST record the key in `processed_commands` and no-op on replay.
Rationale: Network retries must never double-place an order or double-charge a card (ECO-005).
BAD:  `PlaceOrder` with no key; double-click → two orders
GOOD: handler first runs `INSERT INTO processed_commands(key) VALUES (:k) ON CONFLICT DO NOTHING`; 0 rows affected → return.
Enforced by: integration test replaying the same key + security/integration reviewers.
```

**`.agents/rules/queries.md`**

```markdown
---
id: queries
paths: ["src/*/Application/Query/**", "src/*/Infrastructure/ReadModel/**"]
---
# Query & read-model rules

### QRY-001 — Query handlers never change state
Scope: `src/*/Infrastructure/ReadModel/**`
Rule: A query handler MUST NOT call `persist`, `flush`, `executeStatement` with INSERT/UPDATE/DELETE, or dispatch any command/event.
Rationale: Queries that write bypass the write model's invariants — the core violation CQRS exists to prevent.
BAD:  `final class GetOrderHistoryHandler { public function __invoke(GetOrderHistory $q): array { $this->em->flush(); ... } }`
GOOD: `public function __invoke(GetOrderHistory $q): OrderHistoryView { return new OrderHistoryView($this->connection->fetchAllAssociative(self::SQL, ['customerId' => $q->requesterId()])); }`
Enforced by: custom PHPStan rule `NoWriteInQueryHandlerRule` (§6) + `guard-cqrs.sh` hook (greps `persist(|flush(|commandBus->dispatch` in ReadModel).

### QRY-002 — Queries return DTOs, never ORM entities
Scope: `src/*/Infrastructure/ReadModel/**`
Rule: Query handlers MUST return view DTOs (`*View`) built from arrays/scalars; returning a Doctrine entity is forbidden.
Rationale: Entities on the read side invite lazy-loading, N+1 (PRF-001) and accidental mutation.
BAD:  `return $this->em->find(Order::class, $id);` (entity escapes to the controller)
GOOD: `return new OrderConfirmationView(...$row);` from a DBAL fetch.
Enforced by: Deptrac (ReadModel layer may not use `doctrine/orm`) + PHPStan return types + review.

### QRY-003 — Read side uses DBAL or SQL only
Scope: `src/*/Infrastructure/ReadModel/**`, `src/*/Infrastructure/Projection/**`
Rule: Read-model queries and projections MUST use `Doctrine\DBAL\Connection` or native SQL; the ORM is reserved for the write side.
Rationale: Denormalized read tables don't map to aggregates; the ORM adds cost and coupling with zero benefit here.
BAD:  `OrderViewRepository extends ServiceEntityRepository` with DQL
GOOD: `final class OrderHistoryFinder { public function __construct(private Connection $db) {} }` with explicit SQL.
Enforced by: Deptrac + `guard-cqrs.sh` hook (greps `EntityManagerInterface` in ReadModel/Projection).

### QRY-004 — Queries are authorized at the handler
Scope: `src/*/Application/Query/**`, `src/*/Infrastructure/ReadModel/**`
Rule: Every query MUST carry the requester identity and the handler MUST filter by ownership unless the requester has an admin role; "fetch by id then check in controller" is forbidden.
Rationale: Authorization at the edge is forgotten; authorization in the handler is unavoidable (SEC-002).
BAD:  `final class GetOrder { public function __construct(public readonly OrderId $id) {} }` — anyone reads any order
GOOD: `final class GetOrderHistory { public function __construct(public readonly CustomerId $requesterId, public readonly bool $isAdmin) {} }` and SQL has `WHERE customer_id = :requesterId` when not admin.
Enforced by: functional tests (customer B cannot read customer A's order) + security-reviewer.
```

**`.agents/rules/events.md`**

```markdown
---
id: events
paths: ["src/*/Domain/Event/**", "src/*/Infrastructure/Projection/**", "src/*/Infrastructure/MessageHandler/**"]
---
# Event rules

### EVT-001 — Domain events are past-tense, immutable, minimal
Scope: `src/*/Domain/Event/**`
Rule: Events MUST be named past tense (`OrderPlaced`), be `final readonly`, and carry only IDs, scalars and arrays — no entities, no services, plus `eventId` and `occurredOn`.
Rationale: Events cross context and process boundaries (async); fat payloads break replay and leak the write model.
BAD:  `class OrderPlaced { public Order $order; }` (entity + lazy proxies on the bus)
GOOD: `final readonly class OrderPlaced { public function __construct(public string $eventId, public OrderId $orderId, public CustomerId $customerId, public \DateTimeImmutable $occurredOn) {} }`
Enforced by: architecture test (reflection: no object props except VOs/IDs) + code-reviewer.

### EVT-002 — All domain events go through the outbox
Scope: `src/**`
Rule: Command handlers and sagas MUST NOT inject `EventBusInterface`; aggregates record events, the handler appends them to the `outbox` table in the same transaction, and a relay worker publishes them to `event.bus`.
Rationale: Commit-then-dispatch loses events on crash; dispatch-then-commit publishes phantom events. The outbox makes it atomic.
BAD:  `$this->eventBus->dispatch(new OrderPlaced(...));` inside `PlaceOrderHandler`
GOOD: `$this->outbox->append($order->releaseEvents());` inside the handler's transaction; `messenger:consume outbox_relay` publishes.
Enforced by: custom PHPStan rule `NoEventBusInCommandHandlerRule` (§6) + integration test (event row exists in outbox after handler, none on rollback).

### EVT-003 — Every event subscriber is idempotent
Scope: `src/*/Infrastructure/Projection/**`, `src/*/Infrastructure/MessageHandler/**`
Rule: A subscriber's first statement MUST claim the event: `INSERT INTO processed_events(consumer, event_id) VALUES (?, ?) ON CONFLICT DO NOTHING`; on duplicate it returns without side effects.
Rationale: The outbox relay delivers at-least-once; non-idempotent consumers double-project and double-email.
BAD:  projection updates `order_history_view` directly; relay retry duplicates lines
GOOD: claim-first, then `INSERT ... ON CONFLICT (order_id, line_sku) DO UPDATE` projection writes.
Enforced by: integration/projection tests dispatching the same event twice + test data asserting single effect.
```

**`.agents/rules/aggregates.md`**

```markdown
---
id: aggregates
paths: ["src/*/Domain/**"]
---
# Aggregate rules

### AGG-001 — Invariants are enforced inside the aggregate
Scope: `src/*/Domain/Model/**`
Rule: State changes MUST go through intention-revealing methods that guard invariants; public setters and public writable properties are forbidden.
Rationale: Invariants scattered across handlers are bypassed by the next handler; inside the aggregate they cannot be skipped.
BAD:  `$order->status = 'confirmed';` from the handler
GOOD: `$order->confirm();` which throws `OrderNotConfirmable` unless payment is authorized.
Enforced by: unit tests per invariant + code-reviewer (grep for public setters in Domain).

### AGG-002 — One aggregate per transaction; reference others by ID
Scope: `src/*/Domain/Model/**`, `src/*/Application/CommandHandler/**`
Rule: A handler MUST load and save exactly one aggregate; other aggregates are referenced by ID and coordinated via events or the saga.
Rationale: Multi-aggregate transactions create lock contention and hide consistency boundaries (ECO-003 uses the saga instead).
BAD:  handler loads `Order` AND `StockItem`, mutates both, saves both
GOOD: handler mutates `Order`; stock moves via `StockReserved` / `ReleaseStock` saga steps.
Enforced by: architecture test (one `*Repository->save` per handler) + architecture-reviewer.

### AGG-003 — Aggregates record events, never dispatch them
Scope: `src/*/Domain/Model/**`
Rule: Aggregates MUST use `recordThat(event)` and expose `releaseEvents(): array`; injecting any bus or dispatcher into Domain is forbidden.
Rationale: Dispatch timing is a transaction concern (EVT-002); the domain must stay framework-free (LAY-001).
BAD:  `Order::__construct(private EventDispatcherInterface $d)` then `$this->d->dispatch(...)`
GOOD: `private array $events = []; $this->recordThat(new OrderPlaced(...));`
Enforced by: Deptrac (Domain layer) + unit test asserting recorded events.
```

**`.agents/rules/messenger.md`**

```markdown
---
id: messenger
paths: ["config/packages/messenger.yaml", "src/**"]
---
# Messenger bus rules (config delivered in §6)

### BUS-001 — Three buses with distinct middleware
Scope: `config/packages/messenger.yaml`
Rule: The application MUST define `command.bus`, `query.bus`, `event.bus`; command.bus = [validation, doctrine_transaction, outbox_append], query.bus = [validation only], event.bus = [retry]; no cross-use.
Rationale: Mixing concerns in one default bus makes transaction and retry behavior untestable per message type.
BAD:  single `messenger.bus.default` handling commands, queries and events
GOOD: three buses; handlers declare their bus via `#[AsMessageHandler(bus: 'command.bus')]`.
Enforced by: integration test booting the container and asserting middleware per bus + architecture-reviewer.

### BUS-002 — Command/query buses are synchronous; events async via the relay only
Scope: `config/packages/messenger.yaml`, `src/**`
Rule: Commands and queries MUST NOT be routed to an async transport; only the outbox relay sends to `event.bus` transport. Code MUST NOT use `HandleTrait` results from command.bus.
Rationale: Async commands lose the transaction and the RED/GREEN feedback loop; read results belong to query.bus.
BAD:  `'App\Ordering\Application\Command\PlaceOrder' => async` in messenger.yaml
GOOD: commands unrouted (sync); `App\*\Domain\Event\*` routed via outbox relay only.
Enforced by: config review + integration test (dispatching a command executes inline).
```

**`.agents/rules/naming-errors.md`**

```markdown
---
id: naming-errors
paths: ["src/**", "tests/**"]
---
# Naming & error-handling rules

### NAM-001 — Names follow the CQRS table
Scope: `src/**`
Rule: Command `PlaceOrder`; handler `PlaceOrderHandler`; query `GetOrderHistory`; view `OrderHistoryView`; event `OrderPlaced`; subscriber `<Verb>On<Event>` e.g. `ProjectOrderHistoryOnOrderPlaced`; repository port `OrderRepository`; impl `DoctrineOrderRepository`.
Rationale: Predictable names let agents route tasks and let hooks scope rules by path.
BAD:  `OrderManager`, `OrderHelper`, `HandleOrderStuff`
GOOD: the table above, in the directory layout of AGENTS.md.
Enforced by: `tests/Architecture/NamingTest.php` + code-reviewer.

### ERR-001 — Format validation happens before dispatch
Scope: `src/*/Infrastructure/Http/**`, `src/*/Application/Command/**`
Rule: Syntax validation (email shape, positive qty, known currency) MUST run via Symfony Validator on the command DTO at the edge, before `command.bus` dispatch.
Rationale: The domain should never receive structurally impossible input; edge validation gives 422s with field paths.
BAD:  `if ($qty <= 0) throw new \InvalidArgumentException(...)` inside the aggregate for a missing HTTP field
GOOD: `#[Assert\Positive] public readonly int $quantity` on the command; controller returns 422 on violations.
Enforced by: functional tests (422 on invalid payload) + code-reviewer.

### ERR-002 — Invariant violations throw domain exceptions, mapped in Infrastructure
Scope: `src/*/Domain/**`, `src/*/Infrastructure/Http/**`
Rule: Business rule violations MUST throw `DomainException` subclasses (e.g. `InsufficientStock`); a single HTTP listener maps them to 4xx problem+json; Domain MUST NOT throw HTTP exceptions.
Rationale: Keeps LAY-001 intact while giving clients consistent error contracts.
BAD:  `throw new BadRequestHttpException('no stock')` inside `StockItem`
GOOD: `throw InsufficientStock::forProduct($sku)` → listener maps to 409 with `type: insufficient-stock`.
Enforced by: Deptrac (Domain) + functional test on the 409 contract.

### ERR-003 — No silent catch around bus dispatch
Scope: `src/**`
Rule: Code MUST NOT wrap `bus->dispatch()` in try/catch that swallows or logs-and-continues; only catch to map to a domain decision (saga compensation) or rethrow.
Rationale: Swallowed handler failures desynchronize write and read models silently.
BAD:  `try { $this->bus->dispatch($cmd); } catch (\Throwable) { $this->logger->warning('ignored'); }`
GOOD: `catch (PaymentAuthorizationFailed $e) { $this->bus->dispatch(new ReleaseStock(...)); }`
Enforced by: `guard-cqrs.sh` hook (grep pattern) + code-reviewer.
```

**`.agents/rules/security.md`**

```markdown
---
id: security
paths: ["src/**", "config/**", "tests/**"]
---
# Security & compliance rules

### SEC-001 — Never store or log card data
Scope: `src/**`, `config/**`
Rule: Code MUST NOT persist, log, or put on any bus a PAN, CVV or expiry; payments use provider tokens/payment-method IDs only.
Rationale: Storing PAN/CVV puts the merchant in PCI DSS scope; tokens keep us out.
BAD:  `$this->logger->info('charge', ['card' => $payload['card_number']]);` or a `card_number` column
GOOD: `Payment::authorize(Money $amount, PaymentMethodToken $token)`; DTOs contain `pm_tok_*` only.
Enforced by: `guard-secrets.sh` hook (PAN regex + `cvv|cvc|card_number` grep on diff) + security-reviewer + contract tests asserting payloads.

### SEC-002 — Every query enforces ownership or role
Scope: `src/*/Infrastructure/ReadModel/**`
Rule: See QRY-004: requester identity is a mandatory query parameter; SQL filters `customer_id = :requesterId` unless admin. Addresses, orders, payment methods included.
Rationale: IDOR is the most common e-commerce breach; handler-level filtering cannot be forgotten by a controller.
BAD:  `WHERE id = :id` on `order_history_view`
GOOD: `WHERE id = :id AND customer_id = :requesterId` (admin path covered by separate query).
Enforced by: functional tests per read model (cross-customer read → 404) + security-reviewer.

### SEC-003 — Webhooks verify provider signatures on the raw body
Scope: `src/Payment/Infrastructure/Http/**`
Rule: Webhook controllers MUST verify the provider HMAC signature against the raw request body before parsing or dispatching anything.
Rationale: JSON-reparse breaks signatures; skipping verification lets attackers forge `PaymentAuthorized`.
BAD:  `$payload = $request->toArray();` then signature check on re-encoded JSON
GOOD: `hash_equals($expected, hash_hmac('sha256', $request->getContent(), $secret))` before any dispatch.
Enforced by: contract test with valid/invalid signatures + security-reviewer.

### SEC-004 — Anonymization reaches every read model
Scope: `src/Identity/**`, `src/*/Infrastructure/Projection/**`
Rule: Customer anonymization MUST emit `CustomerAnonymized`, and every read table holding PII MUST have a subscriber that scrubs it (orders keep financial data, replace PII with tombstone values).
Rationale: GDPR erasure that only hits the write side still leaks names in `order_history_view`.
BAD:  anonymize `customer` table; `admin_customer_view` still shows the email
GOOD: `ScrubReadModelsOnCustomerAnonymized` updates all `*_view` rows for that customer id.
Enforced by: projection test (event → all views scrubbed) + security-reviewer.

### SEC-005 — Secrets only via environment; .env never committed
Scope: repo root, `config/**`
Rule: Secrets MUST come from env vars / the secrets vault; committing `.env`, `.env.*.local`, private keys or provider secrets is forbidden.
Rationale: A committed Stripe key is a breach on day one.
BAD:  `STRIPE_SECRET=sk_live_...` in a committed `.env`
GOOD: `.env` in `.gitignore`; `.env.example` with empty placeholders.
Enforced by: `guard-secrets.sh` hook (blocks commit) + gitleaks in CI.
```

**`.agents/rules/migrations-performance.md`**

```markdown
---
id: migrations-performance
paths: ["migrations/**", "src/*/Infrastructure/ReadModel/**", "config/**"]
---
# Migration & performance rules

### MIG-001 — Migrations are reviewed, reversible, human-approved
Scope: `migrations/**`
Rule: Every migration MUST have a working `down()`, MUST NOT drop columns with data in the same release that stops using them, and MUST pass the human approval gate before commit.
Rationale: Irreversible generated migrations are the top cause of failed deploys.
BAD:  generated `DROP TABLE order_line` committed unreviewed
GOOD: expand-migrate-contract across releases; `down()` tested in CI (`migrations:migrate first` then `up`).
Enforced by: human approval gate (orchestration.md) + CI migrate up/down + code-reviewer.

### MIG-002 — Read-model data is rebuilt by replay, not by data migrations
Scope: `migrations/**`, `src/*/Infrastructure/Projection/**`
Rule: Schema of `*_view` tables MAY change via migrations; their DATA MUST be rebuilt with `bin/console projection:replay <name>`, never by INSERT/UPDATE inside a migration.
Rationale: Projections are replayable by design (EVT-003); data migrations duplicate projection logic and drift.
BAD:  migration backfilling `order_history_view` with SQL copied from the projector
GOOD: migration creates/alters the table; deploy runs `projection:replay order-history`.
Enforced by: projection-test-agent replay test + code-reviewer.

### PRF-001 — No N+1: constant query count per read
Scope: `src/*/Infrastructure/ReadModel/**`
Rule: A query handler MUST issue at most 2 SQL statements per request; lists are pre-joined/denormalized in the view table, never looped-and-queried.
Rationale: Read models exist precisely to make reads O(1) in queries.
BAD:  `foreach ($orders as $o) { $lines = $db->fetchAll('SELECT ... WHERE order_id = ?', [$o['id']]); }`
GOOD: one query joining `order_history_view` + `order_line_view`, grouped in PHP.
Enforced by: functional test asserting query count via DBAL logger + performance-reviewer.

### PRF-002 — Read tables carry indexes matching their filters
Scope: `migrations/**`
Rule: Every `*_view` table MUST have an index for each WHERE/ORDER BY column used by its query handler, named `idx_<table>_<cols>`.
Rationale: Denormalized tables without indexes are seq-scan dashboards.
BAD:  `order_history_view(customer_id)` filter with no index
GOOD: `CREATE INDEX idx_order_history_customer ON order_history_view(customer_id, placed_at DESC);`
Enforced by: migration review checklist + performance-reviewer running EXPLAIN in tests.

### PRF-003 — Heavy side effects consume events asynchronously
Scope: `config/packages/messenger.yaml`, `src/*/Infrastructure/**`
Rule: Projections, emails, search-index updates and webhook deliveries MUST run on the async event transport; command handling MUST NOT perform them inline.
Rationale: Checkout latency must not depend on Meilisearch or the SMTP server.
BAD:  `PlaceOrderHandler` calls `mailer->send()` inline
GOOD: `SendConfirmationEmailOnOrderConfirmed` consumer on `event.bus` transport with retry.
Enforced by: messenger.yaml routing review + performance-reviewer.
```

**`.agents/rules/ecommerce.md`**

```markdown
---
id: ecommerce
paths: ["src/**", "tests/**"]
---
# E-commerce rules

### ECO-001 — Money is integer minor units + currency; floats forbidden
Scope: `src/**`
Rule: Monetary values MUST be `Shared\Domain\Money` (int minor units + Currency VO); the `float` type is forbidden in Domain and Application code.
Rationale: `0.1 + 0.2 !== 0.3` — floats mischarge customers.
BAD:  `public function setPrice(float $price): void` / `$total = 19.99 * 3;`
GOOD: `Money::ofMinor(1999, Currency::EUR)`; DB columns `INT` + `CHAR(3)`.
Enforced by: custom PHPStan rule `NoFloatInDomainRule` (§6) + `guard-cqrs.sh` hook grep + unit tests.

### ECO-002 — Money arithmetic only through the Money VO
Scope: `src/**`
Rule: All money math MUST use `Money::add/subtract/multiply/percentage`; manual integer math on minor units outside the VO is forbidden; rounding is half-up at defined boundaries only.
Rationale: Centralizing rounding prevents off-by-cent totals between order lines and payment.
BAD:  `$lineTotal = (int) round($unit->cents() * $qty);`
GOOD: `$lineTotal = $unitPrice->multiply($qty);`
Enforced by: unit tests on Money + code-reviewer grep on `cents()` usage outside VO.

### ECO-003 — Checkout is a process manager with compensations
Scope: `src/Ordering/Application/Saga/**`
Rule: Checkout MUST be implemented as `CheckoutSaga`: SYNC `PlaceOrder` (creates order, reserves stock via ECO-004, same TX) → ASYNC `AuthorizePayment` → ASYNC `ConfirmOrder`; on `PaymentAuthorizationFailed` dispatch `ReleaseStock`; on post-confirmation failure dispatch `VoidPayment`.
Rationale: Distributed steps without compensations leak reserved stock and captured money.
BAD:  one handler doing reserve+charge+confirm inline with try/catch spaghetti
GOOD: saga state machine reacting to `StockReserved`, `PaymentAuthorized`, `*Failed` events with explicit compensating commands; sync/async split as above.
Enforced by: architecture test (saga handles every failure event) + integration saga tests + ADR-0004.

### ECO-004 — Stock reservation is atomic; no read-then-write
Scope: `src/Inventory/**`
Rule: Reservation MUST be one conditional statement `UPDATE stock SET reserved = reserved + :qty WHERE product_id = :id AND available - reserved >= :qty` (affected-rows = success) or a Doctrine optimistic-lock retry; SELECT-then-UPDATE in handlers is forbidden.
Rationale: Two concurrent checkouts read the same availability and oversell.
BAD:  `$s = find($id); if ($s->available() >= $q) { $s->reserve($q); save($s); }` without locking
GOOD: atomic UPDATE; 0 rows → throw `InsufficientStock`; concurrency test hammers 20 parallel reservations.
Enforced by: `guard-cqrs.sh` hook heuristic + integration concurrency test (mandatory per Inventory change).

### ECO-005 — Retried commands, webhooks and subscribers are idempotent
Scope: `src/**`
Rule: Retryable commands carry an idempotency key (CMD-006); webhook handlers dedupe on provider event id; event subscribers dedupe via `processed_events` (EVT-003).
Rationale: Stripe retries webhooks; Messenger retries consumers; users double-click. Exactly-once is built, not hoped for.
BAD:  webhook handler authorizes capture on every call
GOOD: `INSERT INTO processed_webhooks(provider, event_id) ... ON CONFLICT DO NOTHING` first.
Enforced by: contract test (same webhook ×2 → single effect) + integration tests.

### ECO-006 — Order lines are purchase-time snapshots
Scope: `src/Ordering/**`
Rule: `OrderLine` MUST store product name, SKU, unit price and tax rate copied at purchase time; Ordering MUST NOT read the current Catalog for order data.
Rationale: Catalog prices change; customers must be billed and shown what they agreed to.
BAD:  order history query joining `catalog_product` for the name/price
GOOD: `order_line(unit_price_cents, tax_rate_bp, product_name, sku)` filled by the Catalog ACL at checkout.
Enforced by: Deptrac (Ordering ⇏ Catalog) + unit test (changing catalog price does not change placed order).

### ECO-007 — UX consistency rules are explicit
Scope: `src/*/Infrastructure/Http/**`
Rule: After a successful command the UI MAY read the write tables via a dedicated DBAL query (order confirmation page: `GetOrderConfirmation` on write tables); lists, dashboards and history MUST read `*_view` tables and tolerate staleness with an "updating" affordance.
Rationale: Reading the write model everywhere kills CQRS; pretending the read model is instant confuses users.
BAD:  confirmation page polls `order_history_view` and shows "order not found" for 2s
GOOD: confirmation reads write tables (strong); account history reads view (eventual, labeled).
Enforced by: ADR-0003 + architecture-reviewer.

### ECO-008 — Storefront search uses Meilisearch; SQL search is admin-only
Scope: `src/Catalog/Infrastructure/**`
Rule: Storefront catalog search MUST query the Meilisearch index fed by a `ProductPublished` projection; SQL LIKE search is allowed only in back-office filters.
Rationale: SQL LIKE gives no typo tolerance, ranking or facets; Meilisearch adds one stateless projection target and stays out of the write path.
BAD:  `WHERE name LIKE :q` on the storefront listing
GOOD: `SearchProducts` query → Meilisearch; `ProductIndexerOnProductPublished` keeps the index in sync, replayable like any projection.
Enforced by: ADR-0005 + architecture-reviewer + projection test for the indexer.
```

**`.agents/rules/tdd.md`**

```markdown
---
id: tdd
paths: ["src/**", "tests/**"]
---
# TDD rules

### TDD-001 — No production code without a recorded failing test
Scope: `src/**`
Rule: An edit under `src/**` MUST be preceded by a RED entry (failing test file + assertion output) in `.agents/state/<task-id>.md` for the current task.
Rationale: The TDD law is the harness's core; unrecorded REDs are indistinguishable from no RED.
BAD:  implementer writes `Money::multiply` before any test exists
GOOD: state file has `RED: tests/Unit/Shared/Domain/MoneyTest.php — "Failed asserting 5997 equals null"` before the method exists.
Enforced by: `tdd-gate.sh` hook (blocks src/** edits without RED entry) — see §6.

### TDD-002 — RED must fail for the right reason
Scope: `tests/**`
Rule: The recorded failure MUST be an assertion/behavior failure (or "method does not exist" for new APIs), never a syntax error, fatal, or misconfigured autoload; fixing GREEN MUST change production code, never the test.
Rationale: A test that fails for the wrong reason proves nothing; editing tests to pass is fraud.
BAD:  RED record shows `ParseError`; GREEN achieved by deleting the assertion
GOOD: RED shows `Failed asserting that two objects are equal` on the new behavior; GREEN adds the minimal implementation.
Enforced by: `tdd-gate.sh` parses phpunit output + test-reviewer.

### TDD-003 — One behavior per test, one behavior per cycle
Scope: `tests/**`
Rule: Each test method MUST verify exactly one behavior; each RED→GREEN cycle adds exactly one behavior.
Rationale: Multi-behavior tests obscure which rule broke and invite big-bang implementations.
BAD:  `testMoney()` asserting add, subtract, multiply and rounding in one method
GOOD: `it_multiplies_minor_units_by_quantity()` — single assertion path.
Enforced by: test-reviewer (one-assertion-path heuristic) + code-reviewer.

### TDD-004 — Test names describe behavior in domain language
Scope: `tests/**`
Rule: Test methods MUST be named `it_<behavior>` / `test_<behavior>` stating the business rule, not the implementation.
Rationale: Tests are the executable specification; implementation names rot on refactor.
BAD:  `testReserveStockMethodReturnsTrue()`
GOOD: `it_rejects_reservation_when_available_stock_is_insufficient()`
Enforced by: `vendor/bin/phpunit --testdox` in CI + test-reviewer.

### TDD-005 — Tests are deterministic
Scope: `tests/**`
Rule: Tests MUST NOT call `sleep`, `rand`, `mt_rand`, `uniqid`, real time or real network; time via fixed `ClockInterface`, IDs via `InMemoryIdGenerator`, HTTP via contract fakes.
Rationale: Flaky tests get ignored, then real regressions get ignored.
BAD:  `sleep(2)` waiting for the projection
GOOD: dispatch event synchronously in the test, or `await`-helper with DB poll timeout 0 in projection tests.
Enforced by: `guard-cqrs.sh` hook grep (`sleep(|rand(|mt_rand(|uniqid(` in tests) + test-reviewer.

### TDD-006 — No logic in tests
Scope: `tests/**`
Rule: Tests MUST NOT contain `if`, `for`, `while`, `try/catch` (except PHPUnit `expectException`); parameterized cases use data providers.
Rationale: Logic in tests needs its own tests.
BAD:  `foreach ($cases as $c) { self::assertEquals($c[1], Money::ofMinor($c[0], EUR)); }` with inline loop over ad-hoc arrays
GOOD: `#[DataProvider('multiplicationCases')]`.
Enforced by: test-reviewer + grep heuristic in `guard-cqrs.sh`.

### TDD-007 — Test data comes from builders/mothers
Scope: `tests/**`
Rule: Complex object setup MUST use builders under `tests/Builders/<Context>/` with sensible defaults and explicit overrides; no copy-pasted 20-line arrangements.
Rationale: Setup noise hides the behavior under test and breaks in bulk on refactors.
BAD:  15 lines of constructor calls in every Ordering test
GOOD: `anOrder()->withLines(2)->placed()->build()`.
Enforced by: test-reviewer.

### TDD-008 — Domain objects are real; only ports are mocked
Scope: `tests/Unit/**`
Rule: Unit tests MUST instantiate value objects and aggregates for real; `createMock` is allowed only for ports (repositories, gateways, clock, bus).
Rationale: Mocking the domain tests the mock, not the business rule.
BAD:  `$order = $this->createMock(Order::class);`
GOOD: `$order = anOrder()->build();` + `$this->createMock(OrderRepository::class)`.
Enforced by: `guard-cqrs.sh` hook grep `createMock\((Money|Order|Cart|StockItem)::class` + test-reviewer.

### TDD-009 — Handler unit tests use in-memory repositories
Scope: `tests/Unit/**`
Rule: Command-handler unit tests MUST run against in-memory fake repositories (arrays), not Doctrine and not mocks of the aggregate.
Rationale: Fast, deterministic tests that still exercise the real port contract.
BAD:  handler test booting the kernel with SQLite
GOOD: `new InMemoryOrderRepository()` implementing the same port; integration suite covers Doctrine.
Enforced by: test-reviewer + shared `InMemory*` fixtures.

### TDD-010 — Mutation score gate on Domain + Application
Scope: `src/*/Domain/**`, `src/*/Application/**`
Rule: Infection MUST report MSI ≥ 85 and covered MSI ≥ 90 on Domain+Application; surviving mutants are triaged, not ignored.
Rationale: Green tests with dead mutants are assertions that never fire.
BAD:  infection at 61% MSI committed as "fine"
GOOD: `vendor/bin/infection --min-msi=85 --min-covered-msi=90` in the pre-finish hook and CI; survivors get test requests via mutation-test-agent.
Enforced by: infection.json.dist + `pre-finish.sh` hook + CI.
```

**`.agents/rules/solid.md`**

```markdown
---
id: solid
paths: ["src/**", "tests/**"]
---
# SOLID rules (3+ per principle; detection method on each)

## SRP
### SRP-001 — One handler per message; handlers only orchestrate
Rule: A handler MUST only: load via port → call domain → save via port → return void. Mapping, HTTP, formatting, second use cases are forbidden.
BAD:  `PlaceOrderHandler` also renders the confirmation email template
GOOD: email is a subscriber on `OrderConfirmed` (PRF-003).
Enforced by: architecture test (one `#[AsMessageHandler]` per class per message) + code-reviewer.

### SRP-002 — Controllers only translate HTTP ↔ bus
Rule: A controller MUST only: deserialize/validate input → dispatch command or query → map result to HTTP. No repositories, no entities, no business branches.
BAD:  controller calls `OrderRepository->find()` then `if ($order->isPaid()) ...`
GOOD: `$view = $this->queryBus->ask(new GetOrderConfirmation($id, $userId));`
Enforced by: Deptrac (Http layer deps) + code-reviewer.

### SRP-003 — One subscriber, one side effect
Rule: An event subscriber class MUST perform exactly one side effect (one projection, one email, one index update).
BAD:  `OrderPlacedSubscriber` updates the view AND sends email AND indexes search
GOOD: three subscribers: `ProjectOrderHistoryOnOrderPlaced`, `SendEmailOnOrderPlaced`, `IndexProductOnProductPublished`.
Enforced by: code-reviewer checklist + subscriber naming test.

## OCP
### OCP-001 — New behavior = new handler/strategy, not edits to existing ones
Rule: A new use case MUST be a new command+handler; a new pricing/shipping rule MUST be a new strategy class; editing an existing handler's branches for a new case is forbidden.
BAD:  adding `if ($type === 'gift')` inside `CalculateShipping`
GOOD: new `GiftWrappingShippingStrategy` tagged into the calculator.
Enforced by: code-reviewer on diffs + solid-guardian.

### OCP-002 — Selection via tagged iterators, never switch/match on type
Rule: Application/Domain code MUST NOT `switch`/`match` on class names or type strings to pick behavior; use `#[TaggedIterator]` strategy collections.
BAD:  `match ($gateway) { 'stripe' => $stripe->charge(), 'adyen' => $adyen->charge() };`
GOOD: `foreach ($this->gateways as $g) { if ($g->supports($method)) return $g->authorize(...); }`
Enforced by: `guard-cqrs.sh` hook grep heuristic + solid-guardian + architecture-reviewer.

### OCP-003 — Adding a handler requires zero config edits
Rule: Messenger handlers MUST rely on autoconfiguration (`#[AsMessageHandler]`); `services.yaml` MUST NOT register individual handlers.
BAD:  `PlaceOrderHandler: tags: [messenger.message_handler]` in services.yaml
GOOD: attribute only; container compiles with no edits for the next handler.
Enforced by: config review + integration test (container compiles, handler routed).

## LSP
### LSP-001 — Every repository implementation passes the port contract test
Rule: Each Infrastructure implementation of a Domain port MUST run the shared abstract contract test (save/find/not-found semantics).
BAD:  `DoctrineOrderRepository` throws `EntityNotFoundException` while the port promises `OrderNotFound`
GOOD: `tests/Integration/Ordering/DoctrineOrderRepositoryTest extends OrderRepositoryContractTest`.
Enforced by: contract-test inheritance check in harness/architecture test.

### LSP-002 — Implementations never narrow preconditions or weaken postconditions
Rule: Overriding methods MUST accept at least the port's types and return at most the port's types; PHPStan level 8 + strict rules must pass.
BAD:  impl requires non-empty array where port allows empty
GOOD: signature-compatible, contract-tested implementations.
Enforced by: `vendor/bin/phpstan analyse` (level 8, strict-rules).

### LSP-003 — All payment gateways pass one contract suite
Rule: Every `PaymentGateway` implementation (Stripe, Adyen, Fake) MUST pass `tests/Contract/PaymentGatewayContractTest` including failure and idempotency cases.
BAD:  Fake returns success shapes Stripe never returns → saga tested against fantasy
GOOD: same suite against all drivers; provider sandbox recorded fixtures.
Enforced by: contract suite in CI + e2e-contract-test-agent.

## ISP
### ISP-001 — Ports are per use case and small
Rule: A Domain/Application port MUST have ≤ 3 methods and serve one use case (e.g. `StockReserver::reserve`), not a `InventoryServiceInterface` grab-bag.
BAD:  `InventoryInterface` with reserve, release, list, import, sync, report
GOOD: `StockReserver`, `StockReleaser`, `StockAvailabilityView` as separate ports.
Enforced by: architecture test (interface method count on `*Port|*Repository`) + solid-guardian.

### ISP-002 — Read ports are separate from write ports
Rule: Interfaces returning view DTOs MUST NOT live on write repositories; `OrderRepository` (write) and `OrderHistoryFinder` (read) are different ports in different layers.
BAD:  `OrderRepository::historyFor(CustomerId): array` next to `save()`
GOOD: read finder under `Infrastructure/ReadModel` using DBAL (QRY-003).
Enforced by: Deptrac + architecture-reviewer.

### ISP-003 — No fat constructors depending on unused collaborators
Rule: A class MUST NOT depend on collaborators it never calls; > 5 constructor dependencies triggers mandatory review.
BAD:  handler with 9 constructor args, 4 unused in most paths
GOOD: split by use case (SRP-001) or introduce a small facade port.
Enforced by: PHPStan (unused constructor param detection) + solid-guardian checklist.

## DIP
### DIP-001 — Ports are owned by inner layers; Infrastructure implements them
Rule: Interfaces MUST live in `Domain` or `Application`; `Infrastructure` contains only implementations bound in `services.yaml`.
BAD:  `App\Ordering\Infrastructure\Persistence\OrderRepositoryInterface` imported by the handler
GOOD: `App\Ordering\Domain\Repository\OrderRepository` implemented by `DoctrineOrderRepository`.
Enforced by: Deptrac + architecture-reviewer.

### DIP-002 — No service location outside the composition root
Rule: `ContainerInterface`, `->get(`, `static::getContainer()` MUST NOT appear in `src/**` outside Kernel/compiler passes.
BAD:  `$this->container->get(StripeGateway::class)` inside the saga
GOOD: constructor injection of `PaymentGateway`.
Enforced by: `guard-cqrs.sh` hook grep + phpstan-symfony + solid-guardian.

### DIP-003 — Handlers depend on abstractions, not Doctrine concretes
Rule: Handler/saga constructor types MUST be ports or `final` value services; type-hinting `EntityManagerInterface`, concrete repositories or HTTP clients is forbidden in Application.
BAD:  `__construct(private EntityManagerInterface $em)` in `PlaceOrderHandler`
GOOD: `__construct(private OrderRepository $orders, private StockReserver $stock, private Outbox $outbox, private ClockInterface $clock)`.
Enforced by: PHPStan custom rule `NoInfrastructureInHandlerRule` (§6) + Deptrac.
```

**`.agents/rules/agents.md`**

```markdown
---
id: agents
paths: [".agents/**", "src/**", "tests/**"]
---
# Agent-behavior rules

### AGT-001 — Agents write only within their declared globs
Rule: An agent MUST create or modify files only under the `Writes` globs of its definition (AGENTS.md roster); everything else is blocked.
Rationale: Scope is the only thing standing between 17 agents and chaos.
BAD:  `unit-test-writer` "fixes" `src/Shared/Domain/Money.php` to make its test pass
GOOD: writer stops, hands off to `implementer` with the RED record.
Enforced by: `scope-guard.sh` hook (AGENT_NAME + glob match) + audit log.

### AGT-002 — Every review finding cites a rule ID
Rule: Review output MUST use the format `[SEVERITY][RULE-ID] file:line — finding`; findings without a rule ID are rejected.
Rationale: Opinions without rules are unenforceable noise (AGT-001 of reviews).
BAD:  "this feels too complex"
GOOD: `[MAJOR][OCP-002] src/Payment/Application/Saga/CheckoutSaga.php:88 — match on gateway name; use tagged iterator`.
Enforced by: `completion-check.sh` hook regex + orchestrator gate.

### AGT-003 — No agent edits another agent's artifact
Rule: If agent A finds a defect in agent B's artifact, A MUST report it via handoff; the orchestrator re-dispatches B. Direct edits are forbidden.
Rationale: Silent cross-edits destroy the audit trail and the RED/GREEN record.
BAD:  refactorer rewrites unit-test-writer's test "to be cleaner" mid-cycle
GOOD: refactorer hands a finding back; orchestrator routes to test-reviewer/unit-test-writer.
Enforced by: audit log review + `completion-check.sh` (artifact ownership check).

### AGT-004 — The orchestrator never writes code or tests
Rule: The orchestrator MAY write only `.agents/state/**` and `.agents/audit/**`; any `src/**` or `tests/**` write is a hard violation.
Rationale: A planning agent that codes bypasses every quality gate it enforces.
BAD:  orchestrator "quickly fixes" a typo in a handler
GOOD: orchestrator dispatches implementer with the finding.
Enforced by: `scope-guard.sh` hook.

### AGT-005 — Handoffs follow the template; state file updated at every gate
Rule: Every agent run MUST end with the handoff template (orchestration.md) and a gate entry appended to `.agents/state/<task-id>.md`.
Rationale: The state file is the resume/audit mechanism and feeds the TDD gate (TDD-001).
BAD:  agent reports "done" in chat, state file untouched
GOOD: template + `GATE: GREEN ok — 2026-10-16T10:00Z — implementer` appended.
Enforced by: `completion-check.sh` hook + harness self-test.

### AGT-006 — Max 3 RED→GREEN attempts, then escalate
Rule: If GREEN cannot be reached in 3 cycles for one behavior, the orchestrator MUST stop the loop and escalate to a human with the full attempt log.
Rationale: Agents flailing on a hard behavior burn budget and produce contorted code.
BAD:  11 attempts rewriting both test and implementation alternately
GOOD: attempt counter in the state file; at 3 → `ESCALATE` gate with hypothesis list.
Enforced by: orchestrator gate logic + state-file schema check in harness-check.
```

CONTINUE FROM: deliverable 3 — `.agents/agents/orchestrator.md` (all 17 agent definitions, then §4 skills, §5 orchestration.md, §6 hooks + deptrac + custom PHPStan rules, §7 enforcement matrix, §8 reference slice + walkthrough, §9 harness self-test).

---

**User**

put all the result of the previous prompt in my current folder and it should be organized

**Assistant**

I'll write all deliverables from the previous response into the project folder. First, a quick look at the current state.

---

**User**

CONTINUE FROM: deliverable 3 — .agents/agents/orchestrator.md (all 17 agent definitions, then §4 skills, §5 orchestration.md, §6 hooks + deptrac + custom PHPStan rules, §7 enforcement matrix, §8 reference slice + walkthrough, §9 harness self-test).

**Assistant**

Error: Your requests have exceeded the quota.

---


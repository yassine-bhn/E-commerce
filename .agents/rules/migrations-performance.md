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
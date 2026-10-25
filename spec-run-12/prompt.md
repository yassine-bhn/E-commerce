in the Implement Super Admin Approval Workflow still missing limitations :
Remaining limitations (documented, not fixed here)
R39/R40/R58 are design properties — INSPECTED, not testable.
Moving a product to another category is still only exercised as part of the update path (R42).
The transactional proofs are statement-level, not process-level (R38).
customer_profile.email has no unique index.
The approval migration is reversible in schema, not in state — up() re-approves and re-publishes every row after a down(), and the backfill itself violates MIG-002 (read model written from a migration). Needs a new migration.
The review guard lives at the HTTP edge (QRY-004) and raises the endpoint from 2 to 3 statements, which no test pins.
do it

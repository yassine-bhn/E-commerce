---
id: add-read-model-projection
used_by: [implementer, projection-test-agent]
---
# Add read model + projection

## When
Denormalized `*_view` table or Meilisearch document needed for queries.

## Steps
1. Migration for view table (MIG-001 human gate if new migration).
2. Projection handler: idempotent upsert from event payload (EVT-003, MIG-002).
3. DBAL-only access in ReadModel (QRY-003); replay test dispatches event twice.
4. Catalog storefront search: Meilisearch projection per ADR-0005 (ECO-008).

## Rules
QRY-003, EVT-003, MIG-002, ECO-008

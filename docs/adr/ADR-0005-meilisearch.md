# ADR-0005 — Meilisearch for storefront catalog search

Status: accepted. Relies on: ECO-008, EVT-003.

## Decision

Storefront catalog search reads from Meilisearch index `catalog_products`, updated by `IndexCatalogSearchDocument` on `event.bus` after SQL read-model projection.

## Rejected alternatives

- PostgreSQL full-text only (rejected: poorer relevance and faceting for catalog UX).
- Dual-write to Meilisearch from command handler (rejected: violates EVT-002; projection only).

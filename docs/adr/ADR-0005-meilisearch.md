# ADR-0005 — Meilisearch for storefront catalog search

Status: accepted. Relies on: ECO-008, EVT-003.

## Decision

Storefront catalog search reads from Meilisearch index `storefront_products`, sourced from `storefront_product_view` and updated by `IndexCatalogSearchDocument` on `event.bus` after SQL read-model projection. The indexed field set is `slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, rating, reviews, is_new, is_best, stock, material, dims, image_path`. ADR-0009 fixes the pagination/faceting contract (`page`/`hitsPerPage`, `facets`) served on top of this index.

## Rejected alternatives

- PostgreSQL full-text only (rejected: poorer relevance and faceting for catalog UX).
- Dual-write to Meilisearch from command handler (rejected: violates EVT-002; projection only).

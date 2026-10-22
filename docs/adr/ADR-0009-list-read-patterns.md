# ADR-0009 — List read patterns: Meilisearch, keyset and offset

Status: accepted. Relies on: QRY-001/QRY-003/QRY-004, PRF-001/PRF-002/PRF-003, MIG-001/002.

## Decision

Every collection read endpoint is bounded and uses exactly one of three pattern classes.

**1. Meilisearch offset pagination (`page` + `hitsPerPage`, plus `facets`).** Storefront catalog
listing and search (`GET /api/v1/storefront/products`, `GET /api/v1/storefront/products/search`)
read the `storefront_products` index (ADR-0005) with its documented filters and sorts. A SQL
`ILIKE` + `COUNT` + `LIMIT/OFFSET` fallback serves the same contracts when Meilisearch is
unreachable, or when the index reports zero hits for a query-less listing while the catalog has
products (a listing must never be silently empty; `facets = null` on the fallback).

**2. SQL keyset/cursor pagination.** Append-only, ever-growing per-owner streams: product reviews,
customer orders, admin customer orders, quote requests. The cursor is
`base64url(json({"k": <sortKey>, "i": <id>}))`; the query applies a strict
`(sortKey, id) < (:k, :i)` predicate under `ORDER BY <sortKey> DESC, id DESC` and fetches
`limit + 1` rows to compute `hasMore`. This keeps pages contiguous (no duplicate, no skipped row)
even while new rows are inserted between requests.

**3. SQL offset pagination (`page` + `size`).** Bounded back-office and per-user lists where a
total count and page jumping matter: admin customers, admin categories, admin brands, admin promo
codes, wishlist, customer addresses. These answer `total`/`totalPages`, which keyset cannot serve.

### Classification (16 endpoints)

| # | Endpoint | Pattern | Pagination params | Filters | Sort |
|---|---|---|---|---|---|
| 1 | `GET /api/v1/storefront/products` | **Meilisearch offset** (page/hitsPerPage) + SQL fallback | `page` (default 1), `size` (default 24, max 100) | `q`, `category`, `sub`, `brand`, `promotions`, `new`, `best`, `priceMin`, `priceMax`, `material`, `stock` | `name` (default), `price_asc`, `price_desc`, `rating`, `newest` |
| 2 | `GET /api/v1/storefront/products/search` | **Meilisearch full-text offset** + SQL fallback | `page`, `size` (same defaults) | `q` (required) + all listing filters | Meilisearch relevance (SQL fallback: `name`) |
| 3 | `GET /api/v1/storefront/products/{slug}/reviews` | **SQL keyset/cursor** | `limit` (default 10, max 50), `cursor` | `rating` | `newest` (`created_at DESC, id DESC`) |
| 4 | `GET /api/v1/storefront/orders` | **SQL keyset/cursor** | `limit` (default 20, max 100), `cursor` | `status`, `dateFrom`, `dateTo`, `q` (order number) | `placed_at DESC, id DESC` |
| 5 | `GET /api/v1/storefront/quote-requests` | **SQL keyset/cursor** | `limit` (default 20, max 100), `cursor` | `status`, `dateFrom`, `dateTo`, `q` | `created_at DESC, id DESC` |
| 6 | `GET /api/v1/storefront/wishlist` | **SQL offset** | `page`, `size` (default 24, max 100) | `q` | `recent` (default), `name`, `price_asc`, `price_desc` |
| 7 | `GET /api/v1/storefront/customer/addresses` | **SQL offset** | `page`, `size` (default 20, max 100) | `q` (label/name/city) | `name` |
| 8 | `GET /api/v1/admin/customers` | **SQL offset** | `page`, `size` (default 20, max 50) | `q` (name/email/phone), `role`, `status` | `name` (default), `createdAt`, `orderCount` |
| 9 | `GET /api/v1/admin/customers/{id}/orders` | **SQL keyset/cursor** | `limit` (default 20, max 100), `cursor` | `status`, `dateFrom`, `dateTo`, `q` | `placed_at DESC, id DESC` |
| 10 | `GET /api/v1/admin/catalog/categories` | **SQL offset** | `page`, `size` (default 50, max 200) | `q` (slug/label), `hidden`, `parent` | `sort_order` (default), `label`, `productCount` |
| 11 | `GET /api/v1/admin/catalog/brands` | **SQL offset** | `page`, `size` (default 50, max 200) | `q` | `name` (default), `productCount` |
| 12 | `GET /api/v1/admin/promotions/promo-codes` | **SQL offset** | `page`, `size` (default 50, max 200) | `q` (code), `active` | `code` |
| 13 | `GET /api/v1/storefront/faq` | **No pagination** — bounded editorial content (whole set is the document) | — | — | `sort_order` (existing) |
| 14 | `GET /api/v1/storefront/banner` | **No pagination** — bounded image set of one banner | — | — | `sort_order` (existing) |
| 15 | `GET /api/v1/admin/catalog/banner` | **No pagination** — same bounded set with admin metadata | — | — | `sort_order` (existing) |
| 16 | `GET /api/v1/storefront/reference` | **No pagination** — single aggregate document (categories, brands, governorates, promo codes, shipping) | — | — | — |

Endpoints 13–16 stay unpaginated because each is a bounded editorial set, a bounded image set, or a
single aggregate document: paginating them would add a contract without removing any unbounded
read. Classification is recorded here so the "should this be paginated?" question is answered once.

## Rejected alternatives

- **Offset pagination everywhere** — deep `OFFSET` costs O(offset) and offset pages duplicate or
  skip rows when concurrent inserts land on an append-only stream.
- **Keyset everywhere** — no total count and no page jumping for back-office lists, and awkward
  with a multi-column sortable catalog.
- **Elasticsearch instead of Meilisearch** — ADR-0005 already chose Meilisearch; Elasticsearch is
  heavier to operate for the same faceting needs.
- **Meilisearch for admin back-office lists** — data freshness, the authorization-in-SQL
  requirement (QRY-004), and no need for relevance ranking.
- **PostgreSQL full-text as the storefront primary** — ADR-0005 rejected it for relevance/faceting,
  so it remains only a degraded fallback (QRY-003).

## Consequences

- Callers read counts from `meta` (`total`/`totalPages` for offset, `hasMore` for cursor) and pass
  `size` instead of the old `limit` on the storefront products and admin customers lists.
- Callers walk keyset streams with `nextCursor` (`null` when `hasMore` is `false`); `count` on
  product reviews is now the total number of reviews, not the page size.
- Freshness caveat: the Meilisearch index is refreshed by the projection and by admin catalog
  writes (ADR-0005), and fully rebuilt with `app:storefront:index-search`.

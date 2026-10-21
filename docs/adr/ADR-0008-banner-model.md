# ADR-0008 — Site banner as one ordered image set inside Catalog

Status: accepted. Relies on: ADR-0001, ADR-0007, INF-001, QRY-001..QRY-004, CMD-001..CMD-006,
spec R17/R18/R19/R20/R21/R22 and assumptions 7/8 (Q5 default cardinality, Q8/Q9 defaults).

## Decision

**Shape.** "The banner" is **one site-level image set** (a hero/carousel), not N concurrent banners:
`storefront_banner` is a singleton enforced the way `storefront_shipping_policy` already does it —
`id SMALLINT PRIMARY KEY` plus `CONSTRAINT chk_single_site_banner CHECK (id = 1)` — seeded by the
migration so the FK target always exists. Its slides live in `storefront_banner_image`.

**Storage.** Two PostgreSQL-only tables, created by one reversible migration (R17, INF-001):

    storefront_banner(id, created_at, updated_at)                       -- exactly one row, id = 1
    storefront_banner_image(id, banner_id, image_path, sort_order, created_at)
        FOREIGN KEY (banner_id) REFERENCES storefront_banner(id) ON DELETE CASCADE

**Cardinality is 1..10, in the handler, never in the schema** (R18, Q5 default). The schema carries
no `CHECK` on the slide count and no fixed-size column list, so 1, 3 and 7 images are all legal
states; the limit is validated by the `command.bus` handler, which is the only place that can return
a `422` in the existing `{"error": …}` shape. A schema-level count constraint would fail as a `500`
and could not express "the set as a whole" on a per-row table.

**Order (R20).** Explicit `sort_order INT NOT NULL` plus a documented deterministic tie-break:
every read is `ORDER BY sort_order ASC, created_at ASC, id ASC`. `sort_order` is deliberately **not
unique** — two concurrent admin writes that collide still resolve to one stable sequence through the
`id` tie-break, instead of one write dying on a constraint (Q12: admin surfaces accept lost updates).
The order written by the admin is the order returned by both the admin list and the public read, so
neither uses an unqualified `SELECT`.

**Image references (R19, R22).** `image_path VARCHAR(512) NOT NULL` stores the `/uploads/...` path
returned by `POST /api/v1/admin/catalog/uploads` verbatim — the same value format already stored in
`storefront_product_view.image_path`, deliberately widened from that column's 255 so a banner
sub-directory cannot make a legal upload path uninsertable. No new upload, crop, resize or `srcset`
path exists; validity
(that the path sits under the uploads area and is not an external URL) is a handler rule, not a
database rule, because the filesystem is not in this schema. Removing or replacing a row never
touches the file under `public/uploads/`: no cascade, no trigger, no unlink — dropping the banner
row even with `ON DELETE CASCADE` deletes reference rows only. The schema puts **no** `UNIQUE` on
`(banner_id, image_path)`: Q5 does not settle whether the same upload may appear twice in one set, so
the permissive schema lets WP5's handler choose the rule (reject the duplicate, or accept it and keep
"count returned == count selected" true) without a second migration.

**Context (assumption 8).** The banner is Catalog-owned content, like the taxonomy it sits next to:
the admin write goes through Catalog commands + handlers (`src/Catalog/Application/...`) and both
reads — the admin list and the public `GET` under `/api/v1/storefront/...` (PUBLIC_ACCESS per
`security.yaml`) — go through a Catalog DBAL finder (`src/Catalog/Infrastructure/ReadModel/`)
returning DTOs. The `storefront_` table prefix is this repository's name for storefront-facing
content and reference tables (`storefront_category`, `storefront_brand`, `storefront_faq_entry`,
`storefront_governorate`, `storefront_shipping_policy`); Catalog already owns the write/validation
side of `storefront_category` (`AdminCategoryController`, `CategoryHierarchyValidator`) while Shared
hosts some of its reads (`StorefrontReferenceRepository`), which is the same split the banner reuses.

**Public read (R21, Q9 default: ship it).** Unauthenticated, under the `^/api/v1/storefront →
PUBLIC_ACCESS` rule, returning the ordered image array; when no images remain the answer is an
empty array — a documented, non-error response rather than a synthetic fallback image, so the
frontend decides what "no banner" looks like.

## Rejected alternatives

- A 9th "Banner"/"Content" bounded context (rejected by assumption 8 and ADR-0001: a context needs
  its own domain language and lifecycle; this is one admin-managed image list, and a new context
  would need its own deptrac layer, buses and ADR for no isolation benefit).
- N banner entities (per-page, per-region, scheduled) (rejected: assumption 7 — the prompt says "a
  dynamic number of **images**", not a dynamic number of banners; per-region scheduling is out of
  scope, and the singleton CHECK is the cheapest place to lift if that ever changes).
- A single `storefront_banner.images JSON` array column (rejected: it is the shape R20 cannot be
  proven on — reordering one slide rewrites the whole document, the count is invisible to SQL, no
  index per slide, and the deterministic order would depend on JSON array position rather than a
  column; the repo's JSON tree is for a taxonomy, which is read as a whole).
- Fixed-size columns `image_path_1..image_path_10` (rejected: the schema would encode the
  cardinality R18 says is dynamic, and growing past 10 is a migration).
- Unique `CONSTRAINT (banner_id, sort_order)` with renumbering on every write (rejected: makes every
  reorder a multi-row update inside one transaction and turns a concurrent admin edit into a
  constraint violation; the `id` tie-break gives the same determinism for one index).
- `position` with gaps (10, 20, 30…) to avoid rewrites (rejected: gap arithmetic exhausts after a
  handful of insertions-between and still needs a renumber pass; no better than dense sort plus a
  tie-break at this cardinality).
- A per-image `active`/`enabled` flag (rejected: Q5's default says removal is the mechanism; a flag
  would make "the count the admin selected" and "the count returned" differ, breaking R18).
- Per-item `POST`/`PATCH/{id}`/`DELETE/{id}` + a separate reorder call (rejected as the *only* path:
  three calls cannot express "replace the set" atomically, so the dashboard would see a
  half-reordered banner between requests; a whole-set `PUT` grows, shrinks and reorders in one
  command, which is also the replace-all default Q5 records).
- `UNIQUE (banner_id, image_path)` in the schema (rejected: it decides Q5's unanswered duplicate
  question at the wrong layer, and the violation surfaces as a `500` instead of the handler's `422`
  in the existing `{"error": …}` shape).
- Storing the resolved absolute `url` from the upload response (rejected: it embeds
  scheme+host at write time; only `path` is durable, the frontend or the read composes the URL).
- Reading the banner straight from `public/uploads/` directory listing (rejected: filesystem order
  is not admin order, and the uploads folder holds product images too).
- Serving the banner through Meilisearch or a projected `*_view` fed by the write model (rejected:
  ADR-0005 scopes Meilisearch to catalog product search; a two-table model needs no projection lag
  and no outbox subscriber — these tables are the read model already).

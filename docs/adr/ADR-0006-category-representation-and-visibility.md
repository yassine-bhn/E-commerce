# ADR-0006 — Category representation and storefront visibility

Status: accepted. Relies on: ECO-006, QRY-001, QRY-003, RG1.3, RG1.5, spec R2/R3/R4/R5/R6/R8 (Q1 and Q3, locked 2026-10-20).

## Decision

**Representation (Q1).** A sub-category is a node of the parent row's `subcategories` JSON tree in
`storefront_category` — `[{label, children?}, …]` — not a child row. The existing `SubcategoryTree`
limits bind every new taxonomy write (depth ≤ 4, label ≤ 128 chars, sibling labels unique per RG1.3)
and `GET /api/v1/storefront/reference` keeps its `slug`/`label`/`subs` shape.

Consequences the schema must respect:
- no hierarchy migration; `parent_slug` — added by `Version20261017000004` and used by 0 of the 11
  live rows — stays untouched as legacy optional data for the `CategoryHierarchyValidator` acyclicity
  check that already reads it, and gains no new writes from this spec;
- the delete guard counts nodes of the parent's JSON tree (`SubcategoryTree::labels()`), not
  `parent_slug` rows, plus the product count (RG1.5);
- products keep pointing at the root `category_slug` + a `subcategory` label, so hiding and deleting
  never recompute an order snapshot (ECO-006).

**Visibility (Q3).** One new column, `storefront_category.hidden BOOLEAN NOT NULL DEFAULT false`.
Hiding is a reversible storefront-navigation flag: it is **not** a soft delete and **not** an enum.
`hidden = true` removes the category **and its whole subtree** from the storefront navigation read;
nothing else changes. The admin category list still returns hidden rows with the flag, the category
row/children/products are never deleted, and the hidden category's products stay reachable and
searchable. `DEFAULT false` is what keeps the 11 seeded taxonomy rows visible after the migration.

## Rejected alternatives

- `parent_slug` child rows for sub-categories (rejected: needs a hierarchy migration, a new
  uniqueness rule per parent, recursive reads in `StorefrontReferenceRepository`, and would fork the
  taxonomy into two mechanisms since the seeded tree and the live frontend already use JSON).
- Dual storage, JSON tree plus mirrored rows (rejected: two sources of truth for one taxonomy;
  every write path must keep them in sync and the delete guard's counts become unprovable).
- Hiding the category's products as well (rejected by Q3: a hidden nav entry must not unpublish a
  product; it would couple catalog navigation to product availability and break direct links and
  search for still-purchasable stock).
- Single-row non-cascading hide (rejected: a hidden parent with visible children leaves dangling
  nav branches, so the subtree would still be reachable through the menu).
- Soft delete / `deleted_at` or a multi-state visibility enum (rejected: the prompt asks for hide,
  which is one reversible boolean; extra states have no read that would honour them).
- Deriving visibility from `quote_eligible` or `sort_order` (rejected: unrelated existing meanings;
  would silently change quote eligibility when an admin hides a category).
- Computed-column/JSON-flag encoding of hidden inside `subcategories` (rejected: unreadable by SQL
  predicates, so the navigation read could not filter it without decoding every tree).

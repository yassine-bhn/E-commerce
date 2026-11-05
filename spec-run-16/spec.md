# Spec: Optional supplier reference on catalog products

Version: 1

## Goal

A product keeps its existing customer-facing `ref` and gains a second, optional reference that stores the supplier's own reference. The value is persisted by the backend, readable and editable only through the existing Admin and Super Admin product surfaces, and absent from every customer-facing response, search index, public type, and storefront view.

## Corrected prompt

In the backend, a product currently has only one reference. Add another reference of the same kind for the supplier's reference. The request suggests `supplierRefference` or `supplier-refference`; use the project's naming conventions instead. This field is optional and stores the supplier's reference. In the front end, consume this feature. The supplier reference must never be shown to end users; it is shown only in the Admin or Super Admin interface.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A reversible migration adds a nullable `supplier_reference VARCHAR(64)` column to `storefront_product_view`; existing rows keep `NULL`, and the migration performs no data backfill. | functional | "add aonther same reference"; "this field is optioanl" | must |
| R2 | The canonical identifiers are `supplierReference` (JSON/TypeScript) and `supplier_reference` (PostgreSQL); no identifier uses `supplierRefference`, `supplier-refference`, or another spelling. | constraint | "choose the naming same of project" | must |
| R3 | Admin product creation accepts an optional `supplierReference`; an absent key, JSON `null`, `""`, and whitespace-only values all persist SQL `NULL`. | functional | "this field is optioanl" | must |
| R4 | Admin product update accepts `supplierReference`; a supplied value replaces the stored value, and an absent key, `null`, `""`, or whitespace-only value clears it to SQL `NULL` (the endpoint's full-replacement semantics). | functional | "this field is optioanl"; "in the backend ... add" | must |
| R5 | A supplied supplier reference is trimmed before validation, persistence, and response: `"  SUP-42  "` is stored and returned as `"SUP-42"`. | functional | "add aonther same reference" (mirrors the existing `ref` normalization) | must |
| R6 | A trimmed supplier reference longer than 64 characters is rejected with HTTP 422, and no row is created or changed. | functional | "add aonther same reference" (same 64-character limit as `ref`) | must |
| R7 | A `supplierReference` that is neither a string nor `null` is rejected with HTTP 422, and no write occurs. | functional | "this field is optioanl" (same typed contract as `ref`) | must |
| R8 | Admin create and update responses contain `supplierReference` as a string or an explicit `null`. | functional | "it's only showen in the admin or super admin interface" | must |
| R9 | `GET /api/v1/admin/catalog/products` returns `supplierReference` as a string or `null` on every row, including rows where the stored value is `NULL`. | functional | "it's only showen in the admin or super admin interface" | must |
| R10 | `GET /api/v1/admin/catalog/products/{slug}` and `PATCH /api/v1/admin/catalog/products/{slug}/publication` responses contain `supplierReference` as a string or an explicit `null`. | functional | "it's only showen in the admin or super admin interface" | must |
| R11 | No public backend response contains a supplier-reference key (`supplierReference`, `supplier_reference`, `supplier-reference`, `supplierRefference`) or the stored supplier value: product list, product search, product detail, wishlist, and cart. | constraint | "the product will never show to the users the reference of the supplier" | must |
| R12 | Meilisearch `storefront_products` documents contain no supplier-reference key or value, and `searchableAttributes`, `filterableAttributes`, and `sortableAttributes` remain unchanged. | constraint | "the product will never show to the users" (public search must not surface it) | must |
| R13 | Admin TypeScript types model the field: `AdminProductInput` gains optional `supplierReference?: string \| null`; `AdminProductRow` and `AdminProductDetail` gain required `supplierReference: string \| null`; the public `Product` and `ProductApi` types stay supplier-free. | functional | "in the front you have to consume this feature"; "only showen in the admin or super admin interface" | must |
| R14 | The admin product create/edit form has an optional "Référence fournisseur" input: blank on create, prefilled from the record on edit, limited to 64 characters, not part of the required-field guard, and submitted as a trimmed string or `null`. | functional | "in the front you have to consume this feature"; "this field is optioanl" | must |
| R15 | The admin product inspector shows a "Référence fournisseur" fact with the stored value, or `—` when unset, in both the Produits and Approbations surfaces. | functional | "it's only showen in the admin or super admin interface" | must |
| R16 | The existing `ref` is untouched: required on create and update, limited to 64 characters, non-unique, returned publicly, publicly searchable, and displayed as before. | constraint | "there is only one reference for the product"; "add aonther same reference" | must |
| R17 | Access is unchanged: no public route is added, the field is returned only by the existing `/api/v1/admin/**` product routes, both `ROLE_ADMIN` and `ROLE_SUPER_ADMIN` may read and edit it under the existing ownership rules, and anonymous or customer calls stay denied. | constraint | "it's only showen in the admin or super admin interface" | must |
| R18 | The supplier reference does not participate in slug generation, product identity, uniqueness, publication, approval, stock, price, cart, ordering, admin `q` search, or public search ranking. | constraint | "add aonther same reference"; "this field is optioanl" | must |
| R19 | The public front end neither models nor renders the supplier reference: `Product`, `ProductApi`, `produit.$slug.tsx`, and `src/components/site/**` keep their current shape and content, and the product page still shows only `Réf. {p.ref}`. | constraint | "the product will never show to the users the reference of the supplier" | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "The naming same of project" resolves to `supplierReference` / `supplier_reference`: the prompt's literal token is the full word "supplierRefference", and the project's compound fields use full words (`providerReference`, `categorySlug`, `stockQuantity`); `ref` is the legacy product exception. | med |
| 2 | The new field mirrors `ref`'s storage width: `VARCHAR(64)`, nullable, no unique constraint, no index. | low |
| 3 | The field is free text; no Supplier entity, supplier table, foreign key, or supplier lifecycle is introduced. | low |
| 4 | "Optional" means absent, `null`, empty, and whitespace-only are accepted, and every blank input is normalized to `NULL` rather than stored as `''`. | low |
| 5 | Both back-office roles may read and edit the field wherever they can already read or write the product; it is not restricted to Super Admins. | low |
| 6 | The `PUT` endpoint keeps its full-replacement semantics, so an omitted `supplierReference` clears the stored value. | med |
| 7 | The admin list carries the field so the inspector can open from a list row without a second fetch. | low |
| 8 | Admin list search (`q`) is not extended: it keeps matching name, slug, `ref`, and brand only. | med |
| 9 | The admin products table keeps its `ref · slug` subtitle; the supplier reference appears in the editor and inspector, not in the table cell. | low |
| 10 | Wishlist and cart are customer-facing surfaces even when the caller is authenticated. | low |
| 11 | Back-office labels stay French, so the field is labelled "Référence fournisseur". | low |
| 12 | No backfill is possible or needed: existing products have no known supplier reference and stay `NULL`. | low |
| 13 | The supplier reference is not unique and does not affect product identity, slug, approval, or publication. | low |
| 14 | Admin responses include `supplierReference: null` explicitly when unset, while public responses omit the key entirely rather than emitting `null`. | low |
| 15 | The 64-character limit is measured in characters (`mb_strlen`) after trimming, consistent with the existing `ref` validation. | low |
| 16 | The write stays in the existing `AdminProductController`; no new command, aggregate, or query-handler write is introduced, preserving QRY-001. | low |
| 17 | The existing `CATALOG_PRODUCT_UPDATED` audit entry is unchanged and does not record the old or new supplier-reference value. | low |

## Open questions

- [minor] Confirm `supplierReference` / `supplier_reference` over `supplierRef` / `supplier_ref`. Default if unanswered: `supplierReference` / `supplier_reference`.
- [minor] On `PUT`, should an omitted `supplierReference` clear the stored value or leave it unchanged? Default if unanswered: clear it, matching the endpoint's full-replacement behaviour.
- [minor] Should the admin list search (`q`) also match the supplier reference? Default if unanswered: no, search behaviour is unchanged.
- [minor] Should the admin products table row show the supplier reference beside `ref`, or only the inspector and editor? Default if unanswered: inspector and editor only.
- [minor] Must the supplier reference be unique across products? Default if unanswered: no, duplicates are allowed and are not a product identity signal.
- [minor] Is 64 characters the intended maximum? Default if unanswered: 64, consistent with `ref`.

## Out of scope

- Supplier master data: supplier records, contacts, lead times, supplier pricing, supplier stock, or a supplier portal.
- Multiple supplier references per product or any product-to-supplier relationship with a cardinality other than one optional text value.
- Uniqueness validation, foreign keys, or validation against a supplier list.
- Public search, filters, facets, sorting, or display of the supplier reference.
- Indexing the supplier reference in Meilisearch, including as a searchable, filterable, or sortable attribute.
- Backfilling existing products with supplier references.
- Showing the supplier reference in orders, order lines, invoices, e-mails, PDFs, exports, imports, reviews, or audit messages.
- Changing the approval or publication workflow beyond the existing admin save behaviour.
- Restricting the field to Super Admins only.
- Changing the admin products table layout or its `ref · slug` subtitle.
- Redesigning the public product page or the admin product sheet beyond the single field/fact row.
- Renaming, removing, or changing the existing `ref`.
- Bulk editing, CSV import, or a new API version.
- Migrating the legacy `catalog_products` table or its demo `Product` aggregate.
- Fixing the unrelated pre-existing `additional_images` create/update inconsistency in `AdminProductController`.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add the nullable column reversibly and prove rollback | R1, R2 | Existing `storefront_product_view` DDL, MIG-001, `MigrationProbeDatabase` | New migration adding `supplier_reference VARCHAR(64) DEFAULT NULL`, plus an integration test that migrates up, asserts the column is nullable with length 64, and rolls it back | `E-commerce/migrations/Version20261105000000.php`, `E-commerce/tests/Integration/Catalog/SupplierReferenceMigrationTest.php` | none | RED test fails before the migration exists; `php bin/console doctrine:migrations:migrate -n` succeeds on dev and test; `information_schema` reports nullable `character varying(64)`; existing rows read `NULL`; `down()` drops only the column; the migration contains no `INSERT`/`UPDATE`/`DELETE`; Integration suite green |
| WP2 | Carry the field through admin reads without changing public payloads | R2, R8, R9, R10, R16 | WP1 column; `StorefrontProductView`, `AdminProductFinder`, `StorefrontProductRepository` | Optional `?string $supplierReference` on the view; `toAdminApi()` adds `supplierReference`; `toApi()` unchanged; `fromRow()` maps the column when present and falls back to `null`; `AdminProductFinder::SELECT` includes the column; `adminBySlug()` uses an admin-only SELECT that includes the column; unit + integration tests | `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/AdminProductFinder.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php`, `E-commerce/tests/Unit/Catalog/StorefrontProductViewSupplierReferenceTest.php`, `E-commerce/tests/Integration/Catalog/AdminProductSupplierReferenceReadTest.php` | WP1 | RED test fails first for the missing admin field; `toApi()` key set has no supplier key; `toAdminApi()` returns a string or `null`; `fromRow()` works with and without the column; admin detail and list return the field; the public `SELECT` still omits it; Unit and Integration suites green; PHPStan and php-cs-fixer clean on touched files |
| WP3 | Accept, validate, and persist the optional field on the admin write path | R3, R4, R5, R6, R7, R8, R17 | WP1 column; WP2 admin projection; existing `validate()`, `parameters()`, create and update SQL | Optional `supplierReference` parsing with trim, blank-to-`null`, 64-character limit, non-string rejection, INSERT and UPDATE column plus parameter, and admin responses through `adminPayload()`; functional tests | `E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php`, `E-commerce/tests/Functional/AdminProductSupplierReferenceWriteTest.php` | WP1, WP2 | RED functional test fails first; POST and PUT persist trimmed values; absent, `null`, and blank clear to `NULL`; 65 characters returns 422 with no write; non-string returns 422; both roles succeed under existing rules; existing `ref` validation tests stay green; Functional suite green |
| WP4 | Model the field in the admin API types and expose it in the create/edit form | R2, R13, R14, R19 | WP2 and WP3 field contract | `AdminProductInput` gains optional `supplierReference: string or null`; `AdminProductRow` and `AdminProductDetail` gain `supplierReference: string or null`; the product sheet gains a "Référence fournisseur" input, blank on create, prefilled on edit, `maxLength` 64, submitted as trimmed text or `null`; the public `Product` and `ProductApi` types are untouched; existing uncommitted edits in both files are preserved | `Zidane_front_web/src/lib/api/admin.ts`, `Zidane_front_web/src/components/admin/ProductSheet.tsx` | WP2, WP3 | `npm run typecheck` and `npm run lint` pass; create with the field blank succeeds and sends `null`; edit shows the stored value; clearing the field saves `null`; the input is not added to the required-field guard; `ProductApi`/`Product` have no supplier field; no unrelated user change is reverted |
| WP5 | Display the field in the shared admin inspector | R15 | WP2 read contract; WP4 flow | `InspectableProduct` includes `supplierReference?: string or null`; `ProductFacts` renders "Référence fournisseur" with the value or `—`; the shared component covers both Produits and Approbations | `Zidane_front_web/src/components/admin/ProductInspector.tsx` | WP2, WP4 | Inspector opened from Produits and from Approbations shows the stored value or `—`; typecheck and lint pass |
| WP6 | Prove the field never reaches public backend surfaces or public search | R11, R12, R17 | WP1, WP2, and WP3 completed; a product seeded with a known `supplier_reference` | Functional sweep over product list, search, detail, wishlist, and cart, including denied access to admin routes, plus a projection test for document keys and settings arrays | `E-commerce/tests/Functional/StorefrontSupplierReferenceNonExposureTest.php`, `E-commerce/tests/Projection/Catalog/StorefrontProductSearchIndexerSupplierReferenceTest.php` | WP1, WP2, WP3 | Every public payload omits the key and the value; admin routes reject anonymous and customer callers; the index document has no supplier key; the three settings arrays are unchanged; the existing exact-key indexer test stays green; Functional and Projection suites green |
| WP7 | Audit the public front end and run the full verification gate | R16, R17, R18, R19 | WP4, WP5, WP6 completed | Read-only audit of the public model, public API types, product route, and site components, plus a recorded run of the backend and frontend gates | No production files; read-only audit of `Zidane_front_web/src/data/catalog.ts`, `src/lib/api/types.ts`, `src/routes/produit.$slug.tsx`, `src/components/site/**` | WP4, WP5, WP6 | No supplier field appears in public types or rendered storefront code; the product page still shows `Réf. {p.ref}` and `["Référence", p.ref]`; Unit, Integration, Projection, Functional, and Contract suites green; PHPStan, php-cs-fixer, and Deptrac clean; frontend typecheck and lint green; no unrelated file changed |

Dependency graph: `WP1 -> WP2 -> WP3 -> (WP4 || WP6)`, plus `WP2 -> WP5` and `WP5` final-gated after `WP4`; `WP7` after `WP4`, `WP5`, and `WP6`. No two packages own the same file. WP7 is read-only.

Whole-spec definition of done: the backend change follows the TDD law (a failing test and its RED output are recorded before each production change), every backend suite listed in `AGENTS.md` is green, the frontend typecheck and lint are green, and the public contract is byte-for-byte free of the supplier reference in payloads, index documents, public TypeScript types, and rendered storefront pages.

## Decisions (synthesizer only)

| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Field name | `supplierRef` / `supplier_ref`, matching `ref` | `supplierRef` / `supplier_ref`, matching `ref` | `supplierReference` / `supplier_reference`, matching `providerReference` and the prompt's literal word | `supplierReference` / `supplier_reference` | The prompt's literal token is the full word "supplierRefference"; the project's compound fields use full words (`providerReference`, `categorySlug`, `stockQuantity`), and `ref` is the legacy exception. Choosing the full word also avoids inventing an abbreviation the user did not ask for. |
| Storage target | `storefront_product_view` | `storefront_product_view` | `storefront_product_view` | `storefront_product_view` | All three agree, and it is the table the live admin write path and read models use; the legacy `catalog_products` model has no `ref`. |
| Optional semantics | Blank/`null` -> `NULL` | Blank/`null` -> `NULL` | Blank/`null` -> `NULL` | Blank, `null`, absent, whitespace -> SQL `NULL` | Keeps "optional" unambiguous and avoids storing `''` as a second empty value. |
| Update semantics | Omitted clears (full replacement) | Omitted clears (full replacement) | Omitted clears (full replacement) | Omitted clears | The existing `PUT` validates and rewrites the whole product; preserving an omitted field would require distinguishing "absent" from "null" and is a new pattern. |
| Admin visibility | Admin + Super Admin | Admin + Super Admin | Admin + Super Admin | Both roles, under existing ownership rules | The prompt says "admin or super admin"; no new authorization gate is needed. |
| Public exclusion | `toApi()` unchanged; indexer unchanged | `toApi()` unchanged; indexer unchanged | `toApi()` unchanged; indexer unchanged | Exclude from `toApi()`, the Meilisearch document/settings, public types, and storefront views | Three independent analysts agree; this is the core privacy constraint in the prompt. |
| Admin list carries field | Yes | Yes | Yes | Include `supplierReference` in the admin list and detail payloads | The inspector opens from a list row; a second fetch would be extra complexity with no benefit. |
| Admin `q` search | Unchanged | Unchanged | Unchanged | Unchanged | The prompt asks for a stored reference, not a new search dimension; adding it would be scope creep. |
| Admin table row | Inspector/editor only | Inspector/editor only | Inspector/editor only | Inspector and editor only | Keeps the existing `ref · slug` subtitle and avoids changing the list layout. |
| Migration version | `Version20261105000000` | `Version20261105000000` | `Version20261105000001` | `Version20261105000000` | The latest existing migration is `Version20261101000000`; this is the next date-based version. |
| Tests | Backend unit/integration/functional/projection | Same, plus role matrix | Same, plus schema probe | Backend TDD with RED evidence, one migration test, admin read/write tests, and a public non-exposure sweep; frontend typecheck + lint | The harness mandates TDD and the frontend has no test runner; this covers each requirement without inventing a frontend test framework. |
| WP shape | 7 WPs | 7 WPs | 7 WPs | 7 file-disjoint WPs with explicit dependencies | All three converge; the shape lets schema, reads, writes, frontend form, inspector, non-exposure tests, and the final audit run in dependency order. |

## Changelog (synthesizer and orchestrator only)

- v1: created from the three analyst specs; naming settled as `supplierReference` / `supplier_reference`.

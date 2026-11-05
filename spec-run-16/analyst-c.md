# Spec: Optional supplier reference on catalog products

Version: 1

## Goal

Add an optional, admin-only supplier reference to catalog products so back-office users can store and consult the supplier's own reference, while every customer-facing surface continues to expose only the existing product `ref`.

## Corrected prompt

In the backend a product currently has a single reference. Add a second reference of the same kind for the supplier's reference. This field is optional. The request suggests the names `supplierRefference` or `supplier-refference`; both are misspelled, so use the project's naming convention instead (the concrete name is fixed in R23, with the alternative recorded in the open questions). In the front end, consume this feature. The supplier reference must never be shown to end users; it appears only in the Admin or Super Admin interface.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | `storefront_product_view` gains a nullable `supplier_reference VARCHAR(64)` column through a reversible migration. Existing rows keep `NULL`; the migration performs no data backfill and writes no default value. | functional | "add aonther same reference"; "this field is optioanl" | must |
| R2 | Admin product creation accepts `supplierReference` as an optional JSON field. When the key is absent, `null`, `""`, or whitespace-only, the stored column is SQL `NULL`. | functional | "this field is optioanl" | must |
| R3 | Admin product creation with a non-blank string stores the trimmed string exactly: leading/trailing whitespace is removed; case and inner whitespace are preserved. | functional | "it will get the refference of the supplier" | must |
| R4 | Admin product update replaces `supplier_reference` with the trimmed submitted string, or sets it to `NULL` when `null` or blank is submitted. | functional | "this field is optioanl"; "it will get the refference of the supplier" | must |
| R5 | Admin product creation with a `supplierReference` longer than 64 characters returns HTTP 422 and inserts no row. | functional | "add aonther same reference" (same 64-character limit as `ref`) | must |
| R6 | Admin product update with a `supplierReference` longer than 64 characters returns HTTP 422 and leaves the existing row unchanged. | functional | "add aonther same reference" (same 64-character limit as `ref`) | must |
| R7 | Admin create and update reject a non-string, non-null `supplierReference` (number, boolean, array, object) with HTTP 422 and write nothing. | functional | "add aonther same reference" (the sibling `ref` is a JSON string) | must |
| R8 | Existing `ref` remains required, non-empty, at most 64 characters, non-unique, and present in public payloads; the new field never replaces, renames, or hides it. | constraint | "there is only one reference for the product"; "add aonther same reference" | must |
| R9 | `GET /api/v1/admin/catalog/products/{slug}` returns `supplierReference` as a string or `null` for both `ROLE_ADMIN` and `ROLE_SUPER_ADMIN` callers already allowed to read that product. | functional | "it's only showen in the admin or super admin interface" | must |
| R10 | `GET /api/v1/admin/catalog/products` returns `supplierReference` as a string or `null` on every item for both back-office roles. | functional | "it's only showen in the admin or super admin interface" | must |
| R11 | Admin create and update responses include `supplierReference` equal to the persisted value (string or `null`). | functional | "in the front you have to consume this feature"; "only showen in the admin or super admin interface" | must |
| R12 | No new authorization gate is added: unauthenticated and non-admin callers keep their existing 401/403 behavior, and access to the field follows the existing product read/write rules for the two back-office roles. | constraint | "it's only showen in the admin or super admin interface" | must |
| R13 | Public product browse, list, and search responses contain no supplier-reference key (`supplierReference`, `supplier_reference`, `supplier-reference`, `supplierRefference`) and no stored supplier value. | constraint | "the product will never show to the users the reference of the supplier" | must |
| R14 | The public product detail response contains no supplier-reference key or value. | constraint | "the product will never show to the users the reference of the supplier" | must |
| R15 | Wishlist and cart responses, including their embedded product objects, contain no supplier-reference key or value. | constraint | "the product will never show to the users the reference of the supplier" | must |
| R16 | Meilisearch `storefront_products` documents contain no supplier-reference field, and its searchable, filterable, and sortable settings are unchanged. | constraint | "the product will never show to the users the reference of the supplier" | must |
| R17 | Public front-end types (`Product` in `src/data/catalog.ts`, `ProductApi` in `src/lib/api/types.ts`) do not contain a supplier-reference field. | constraint | "the product will never show to the users the reference of the supplier" | must |
| R18 | Storefront routes and components (`src/routes/produit.$slug.tsx`, `src/components/site/**`) do not read or render a supplier-reference field; the product page continues to show only `Réf. {p.ref}` and `["Référence", p.ref]`. | constraint | "the product will never show to the users the reference of the supplier"; "in the front you have to consume this feature" | must |
| R19 | `AdminProductInput` accepts `supplierReference?: string \| null`; the create/update payload sends the trimmed value or `null`, never an empty string. | functional | "in the front you have to consume this feature"; "this field is optioanl" | must |
| R20 | `AdminProductRow` and `AdminProductDetail` expose `supplierReference: string \| null`; `Product` and `ProductApi` do not. | constraint | "only showen in the admin or super admin interface"; "in the front you have to consume this feature" | must |
| R21 | The admin product sheet has an optional "Référence fournisseur" input (max 64 characters, not in the required-field guard), blank on create and prefilled from the record on edit; a blank value is submitted as `null`. | functional | "in the front you have to consume this feature"; "this field is optioanl" | must |
| R22 | The admin product inspector shows a "Référence fournisseur" fact with the stored value, or `—` when unset, in both the Produits and Approbations surfaces. | functional | "it's only showen in the admin or super admin interface" | must |
| R23 | Canonical identifiers are `supplierReference` (JSON/TypeScript) and `supplier_reference` (database); no identifier uses `supplierRefference`, `supplier-refference`, or another spelling. | constraint | "choose the naming same of project" | must |
| R24 | The supplier reference does not participate in slug generation, product identity, uniqueness, publication, approval, stock, price, cart, ordering, or search ranking. | constraint | "add aonther same reference"; "this field is optioanl" | must |

## Assumptions

| # | Assumption | Risk if wrong |
|---|------------|---------------|
| 1 | The default naming is `supplierReference` / `supplier_reference`. This corrects the prompt's typo and follows the project's compound-reference precedent (`providerReference` / `provider_reference` in Payment). The alternative is `supplierRef` / `supplier_ref`, which would prioritize symmetry with the existing product `ref`. | high |
| 2 | The field is free text. No Supplier entity, foreign key, supplier table, or supplier catalog is introduced; the value is typed and stored as-is. | med |
| 3 | The field mirrors `ref`'s storage width: `VARCHAR(64)`, nullable, no default, no unique constraint, and no index (it is not searched or filtered). | low |
| 4 | Unset means SQL `NULL`, never the empty string. Blank and whitespace-only input normalize to `NULL`. | low |
| 5 | A PUT is a full replacement. Omitting `supplierReference` from an update clears it to `NULL`, consistent with how the controller already treats omitted optional product fields. The updated dashboard always sends the field, so dashboard edits do not clear it accidentally. | med |
| 6 | Both back-office roles may see and edit the field wherever they can already read or write the product; the field is not restricted to super admins. | low |
| 7 | The admin list carries the field because the inspector opens from a list row and must not require a second fetch to show it. | low |
| 8 | Admin list search (`q`) continues to match name, slug, `ref`, and brand only. The supplier reference is not added to admin search. | med |
| 9 | The admin products table row keeps its `ref · slug` subtitle; the supplier reference appears in the inspector and editor, not in the table cell. | low |
| 10 | Back-office labels remain French, so the field is labelled "Référence fournisseur". | low |
| 11 | No backfill is possible or needed: pre-existing products have no known supplier reference and stay `NULL`. | low |
| 12 | The existing `CATALOG_PRODUCT_UPDATED` audit entry is unchanged and does not record the old or new supplier-reference value. | low |
| 13 | "Users" includes authenticated customers, so wishlist and cart embeds are public surfaces for this constraint. | low |
| 14 | `StorefrontProductView::fromRow()` tolerates rows that do not select `supplier_reference` (public `StorefrontProductRepository` reads), and `toApi()` never emits it. The public `SELECT` is left unchanged. | low |
| 15 | Admin responses always include the key, with `null` when unset, rather than omitting it. | low |
| 16 | The 64-character limit is counted in characters (`mb_strlen`), not bytes, matching `ref`. | low |
| 17 | The supplier reference is editable after creation and does not gate submission, approval, or publication. | low |
| 18 | The field is not copied into orders, order lines, invoices, e-mails, PDFs, exports, imports, or audit payloads. | low |

## Open questions

- [blocking] Confirm the name. Default if unanswered: `supplierReference` / `supplier_reference`, because the prompt's literal token is the full word "supplierRefference" and the project already uses `providerReference` / `provider_reference`. Alternative: `supplierRef` / `supplier_ref`, matching the existing product `ref`. If the alternative is chosen, R23, R1, R19–R21, and every path/migration above must be renamed before implementation.
- [major] Is the supplier reference genuinely free text, or should it point to a supplier record? Default if unanswered: free text; there is no Supplier domain in this codebase, and introducing one is a separate feature.
- [major] On update, should an omitted `supplierReference` clear the stored value or preserve it? Default if unanswered: clear, because the endpoint is a full-replacement PUT and every other optional product field behaves that way. Preservation would require distinguishing "key absent" from "key null" and is not the current controller pattern.
- [minor] Should the admin `q` search also match the supplier reference? Default if unanswered: no; admin search behavior stays unchanged.
- [minor] Should the admin products table row display the supplier reference next to `ref`, or only the inspector? Default if unanswered: inspector and editor only.
- [minor] Is 64 characters the intended maximum? Default if unanswered: 64, consistent with `ref`.
- [minor] Must the supplier reference be unique across products? Default if unanswered: no; duplicates are allowed and are not a product identity signal.
- [minor] Should an unset value be stored as `NULL` or `""`? Default if unanswered: `NULL`, and the admin API returns `null`.
- [minor] Should the supplier reference appear in order snapshots, invoices, exports, or e-mails? Default if unanswered: no; those surfaces stay out of scope.

## Out of scope

- Supplier management: supplier records, contacts, lead times, supplier pricing, supplier stock, or a supplier catalog.
- Multiple supplier references per product, or any product-to-supplier relationship of arbitrary cardinality.
- Exposing the supplier reference in any customer-facing surface: storefront APIs, Meilisearch, public types, product pages, wishlist, cart, reviews, orders, order lines, invoices, e-mails, PDFs, or exports.
- Public search, filters, facets, or sorting on the supplier reference.
- Admin search, filters, or sorting on the supplier reference (default decision; see open questions).
- Backfilling existing products with supplier references.
- Restricting the field to super admins only.
- Changing the approval or publication workflow beyond the existing admin save behaviour.
- Recording old/new supplier-reference values in the audit trail.
- Changing the existing `ref` field, its validation, or its public exposure.
- Fixing the unrelated pre-existing `additional_images` create/update inconsistency in `AdminProductController`.
- Migrating the legacy `catalog_products` table or adding a supplier reference to it.
- Redesigning the public product page or the admin product sheet beyond the single field/fact row.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add the nullable column reversibly and prove rollback | R1, R23 | Existing `storefront_product_view` schema, MIG-001, `MigrationProbeDatabase` | New migration adding `supplier_reference VARCHAR(64) DEFAULT NULL`, plus an integration test that migrates up, asserts the column is nullable with length 64, and rolls it back | `E-commerce/migrations/Version20261105000001.php`, `E-commerce/tests/Integration/Catalog/SupplierReferenceMigrationTest.php`, `E-commerce/tests/Integration/Catalog/Support/MigrationProbeDatabase.php` | none | RED test fails before the migration exists; `doctrine:migrations:migrate -n` succeeds on dev and test; `information_schema` reports nullable `character varying(64)`; existing rows stay `NULL`; `down()` drops the column; the migration contains no `INSERT`/`UPDATE`/`DELETE`; Integration suite green |
| WP2 | Carry the field through admin reads without changing public payloads | R8, R9, R10, R11, R23, R24 | WP1 column; `StorefrontProductView`, `AdminProductFinder`, `StorefrontProductRepository` | Optional `?string $supplierReference` on the view; `toAdminApi()` adds `supplierReference`; `toApi()` unchanged; `fromRow()` maps the column when present and falls back to `null`; `AdminProductFinder::SELECT` includes the column; `adminBySlug()` uses an admin-only SELECT that includes the column; unit + integration tests | `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/AdminProductFinder.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php`, `E-commerce/tests/Unit/Catalog/StorefrontProductViewSupplierReferenceTest.php`, `E-commerce/tests/Integration/Catalog/AdminProductSupplierReferenceReadTest.php` | WP1 | RED test fails first for the missing admin field; `toApi()` key set has no supplier key; `toAdminApi()` returns string or `null`; `fromRow()` works with and without the column; admin detail and list return the field; the public `SELECT` still omits it; Unit and Integration suites green; PHPStan and php-cs-fixer clean on touched files |
| WP3 | Accept, validate, and persist the field on admin writes | R2, R3, R4, R5, R6, R7, R11, R23 | WP1 column; existing `validate()`, `parameters()`, create/update SQL | Optional `supplierReference` in validation, INSERT/UPDATE column lists, SQL parameters and docblocks; strict string-or-null handling; 64-character check; functional tests for create, update, blank, null, oversize, wrong type, and existing `ref` behaviour | `E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php`, `E-commerce/tests/Functional/AdminProductSupplierReferenceWriteTest.php` | WP1 | RED test fails first; POST and PUT persist the trimmed value; absent/null/blank persist `NULL`; 65 characters returns 422 with no insert/update; non-string non-null returns 422; existing `ref` required validation still passes; Functional suite green; do not touch `additional_images` |
| WP4 | Consume the field in the admin TypeScript types, editor, and callers | R17, R19, R20, R21, R23 | WP2/WP3 field contract | `supplierReference?: string \| null` on `AdminProductInput`; `supplierReference: string \| null` on the admin row/detail types; optional "Référence fournisseur" input in the product sheet, prefilled on edit, sent as `null` when blank; caller state/onEdit types changed to `AdminProductRow` / `AdminProductDetail` so the field is statically visible; public `ProductApi` untouched | `Zidane_front_web/src/lib/api/admin.ts`, `Zidane_front_web/src/components/admin/ProductSheet.tsx`, `Zidane_front_web/src/components/admin/ProductsPanel.tsx`, `Zidane_front_web/src/components/admin/ApprovalsPanel.tsx` | WP2, WP3 | `npm run typecheck` and `npm run lint` pass; create with blank succeeds and sends `null`; edit shows the stored value and saves changes; public `ProductApi`/`Product` have no supplier field; manual create/edit flow verified in both panels |
| WP5 | Display the field in the admin inspector | R22 | WP2 read contract; WP4 flow | `InspectableProduct` includes `supplierReference`; `ProductFacts` renders a "Référence fournisseur" fact with `—` when unset | `Zidane_front_web/src/components/admin/ProductInspector.tsx` | WP2; final gate after WP4 | Inspector opened from Produits and from Approbations shows the stored value or `—`; typecheck and lint pass |
| WP6 | Prove the field never reaches public backend surfaces | R13, R14, R15, R16, R8 | WP1 column; a product seeded with a supplier reference | Functional tests for public list/search/detail/wishlist/cart, plus a projection test asserting the Meilisearch document and settings contain no supplier reference | `E-commerce/tests/Functional/StorefrontSupplierReferenceNonExposureTest.php`, `E-commerce/tests/Projection/Catalog/StorefrontProductSearchIndexerSupplierReferenceTest.php` | WP1 | Every public payload omits the key and value; the indexed document has no supplier key; searchable/filterable/sortable attributes are unchanged; Contract, Functional, and Projection suites green |
| WP7 | Verify the public front end did not consume the field | R17, R18 | WP4/WP5 diff; public model, API types, product page, site components | Read-only audit recorded in the WP handoff: no supplier field added to `Product`, `ProductApi`, `produit.$slug.tsx`, or `src/components/site/**`; the existing `ref` display is intact | No production files (read-only: `Zidane_front_web/src/data/catalog.ts`, `src/lib/api/types.ts`, `src/routes/produit.$slug.tsx`, `src/components/site/**`) | WP4, WP5, WP6 | Grep/audit finds no supplier reference in public types or rendered storefront code; the product page still shows `Réf. {p.ref}`; typecheck and lint pass |

WP1 is the only serial schema prerequisite. After WP1, WP2, WP3, and WP6 can run concurrently because their file sets are disjoint. WP4 and WP5 can run concurrently with each other once the field name is fixed (Q1), and can start against the fixed contract before WP2/WP3 merge; their final gates wait for WP2/WP3. WP7 is the final read-only gate. No two work packages own the same file.

### Whole-spec definition of done

- TDD is respected in `E-commerce`: a RED record exists in `.agents/state/<task-id>.md` before each production change, and tests are never edited to reach GREEN.
- `E-commerce`: Unit, Integration, Projection, Functional, and Contract suites green; `vendor/bin/phpstan analyse`, `vendor/bin/php-cs-fixer fix --dry-run --diff`, and `vendor/bin/deptrac analyse --config-file=deptrac.yaml` clean; migration up/down verified on dev and test.
- `Zidane_front_web`: `npm run typecheck` and `npm run lint` green; manual create/edit/inspector flows verified in Produits and Approbations.
- Infection MSI ≥ 85 / covered MSI ≥ 90 on touched Domain+Application code; if this change touches no Domain/Application code, state that explicitly in the handoff.
- The public contract remains exactly as it was: no supplier key in any storefront payload, Meilisearch document, public TypeScript type, or rendered public page.
- Q1 (name) and Q3 (omitted-on-update) are answered or their defaults are explicitly accepted before implementation starts; a different answer updates the affected requirement, migration, API, and front-end names first.

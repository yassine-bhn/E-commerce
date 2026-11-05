# Spec: Optional supplier reference on catalog products

Version: 1

## Goal
Products gain a second, optional reference that stores the supplier's own reference, persisted in the backend and editable/readable in the back office, while every customer-facing surface continues to expose only the existing product reference.

## Corrected prompt
In the backend a product currently has a single reference. Add a second, equivalent reference named as a supplier reference — the request offers `supplierRefference` or `supplier-refference`; use the project's own naming convention. This field is optional and holds the supplier's reference. In the front end, consume this feature: the supplier reference must never be shown to end users; it is shown only in the Admin or Super Admin interface.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | `storefront_product_view` has a new nullable column `supplier_ref VARCHAR(64)`, added by a migration; existing rows keep `NULL`. | functional | "add aonther same reference" / "this field is optioanl" | must |
| R2 | Admin create and update accept an optional supplier reference: `null` or blank stores `NULL`; surrounding whitespace is trimmed; a value longer than 64 characters is rejected with HTTP 422 and nothing is written. | functional | "this field is optioanl it will get the refference of the supplier" | must |
| R3 | The admin product detail read (`GET /api/v1/admin/catalog/products/{slug}`) and the create/update responses return `supplierRef` as a string or `null` for both `ROLE_ADMIN` and `ROLE_SUPER_ADMIN` callers. | functional | "it's only showen in the admin or super admin interface" | must |
| R4 | `GET /api/v1/admin/catalog/products` returns `supplierRef` on every product row for both `ROLE_ADMIN` and `ROLE_SUPER_ADMIN` callers. | functional | "it's only showen in the admin or super admin interface" | must |
| R5 | No public storefront response contains the supplier reference: product listing, product search, product detail, wishlist, and cart payloads have no `supplierRef`/supplier-reference key or value. | constraint | "the product will never show to the users the reference of the supplier" | must |
| R6 | The Meilisearch index neither stores the supplier reference in its documents nor lists it as a searchable, filterable, or sortable attribute. | constraint | "the product will never show to the users" (public search must not surface it) | must |
| R7 | The admin product create/edit form has an optional supplier-reference input: blank by default on create, prefilled from the record on edit, and submitted with the create/update payload (blank submitted as `null`). | functional | "in the front you have to consume this feature" | must |
| R8 | The admin product inspector shows the supplier reference, or `—` when unset, in both the Produits and Approbations surfaces. | functional | "it's only showen in the admin or super admin interface" | must |
| R9 | The public storefront never renders the supplier reference on the product page or any other customer-facing view. | constraint | "the product will never show to the users the reference of the supplier" | must |
| R10 | Naming follows project conventions: JSON/TypeScript field `supplierRef`, database column `supplier_ref`; no identifier uses the request's `supplierRefference`/`supplier-refference` spellings. | constraint | "choose the naming same of project" | must |
| R11 | The change is additive: the existing `ref` field keeps its name, required-on-write validation, 64-character limit, and public exposure. | constraint | "there is only one reference for the product, you have to add aonther same reference" | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "same of project" naming resolves to `supplierRef` (API/TS) and `supplier_ref` (DB), matching the abbreviated existing `ref`. | low |
| 2 | The new field mirrors `ref`'s storage width: `VARCHAR(64)`. | low |
| 3 | The field is free text; no supplier table, foreign key, or supplier entity is introduced. | low |
| 4 | "Optional" means `null` or a blank value is accepted; blank strings are normalized to `NULL` rather than stored as `''`. | low |
| 5 | Both back-office roles (`ROLE_ADMIN` and `ROLE_SUPER_ADMIN`) may see and edit the field; it is not restricted to super admins. | low |
| 6 | The admin list response carries `supplierRef` so the existing inspector, which opens from a list row, can render it without a second fetch. | low |
| 7 | Admin product search (`q`) keeps matching name, slug, `ref`, and brand only; supplier reference is not added to search. | med |
| 8 | The admin products table row subtitle keeps showing only `ref`; the supplier reference is shown in the inspector/detail and the editor, not in the table cell. | low |
| 9 | "Users" includes authenticated customers, so wishlist and cart embeds count as public surfaces. | low |
| 10 | Back-office labels remain French, so the field is labelled "Référence fournisseur". | low |
| 11 | No backfill is possible or needed: pre-existing products have no known supplier reference and stay `NULL`. | low |
| 12 | The supplier reference is not unique and does not affect product identity, slug, publication, or approval state. | low |

## Open questions

- [minor] Confirm `supplierRef`/`supplier_ref` over `supplierReference`/`supplier_reference`. Default if unanswered: `supplierRef`/`supplier_ref`, matching the existing abbreviated `ref`.
- [minor] On update, does an omitted `supplierRef` key clear the stored value or leave it unchanged? Default if unanswered: clear it, matching the endpoint's existing full-replacement semantics.
- [minor] Should the admin list search (`q`) also match the supplier reference? Default if unanswered: no, search behaviour is unchanged.
- [minor] Should the admin products table row show the supplier reference alongside `ref`, or only the inspector? Default if unanswered: inspector/detail and editor only.
- [minor] Must the supplier reference be unique? Default if unanswered: no, uniqueness is not enforced.
- [minor] Is 64 characters the intended maximum? Default if unanswered: 64, consistent with `ref`.

## Out of scope

- Supplier management: supplier records, contacts, lead times, supplier pricing, supplier stock, or a supplier catalog.
- Multiple supplier references per product, or a product-to-supplier relationship of any cardinality.
- Exposing the supplier reference in orders, order lines, invoices, e-mails, PDFs, exports, imports, or the audit trail's human-readable messages.
- Public search, filters, facets, or sorting on the supplier reference.
- Backfilling existing products with supplier references.
- Changing the approval or publication workflow beyond the existing admin save behaviour.
- Restricting the field to super admins only.
- Redesigning the public product page or the public `Product` model.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add the nullable column | R1, R10 | Existing `storefront_product_view` schema and migration conventions | New reversible migration adding `supplier_ref VARCHAR(64) DEFAULT NULL` | `E-commerce/migrations/Version20261105000000.php` | none | `doctrine:migrations:migrate -n` succeeds on dev and test; column is nullable; `down()` drops it; existing product rows are untouched |
| WP2 | Expose the field on admin reads without changing public payloads | R3, R4, R10, R11 | WP1 column; `StorefrontProductView`, `AdminProductFinder`, `StorefrontProductRepository` | View carries `?string $supplierRef`; `toAdminApi()` adds `supplierRef`; `toApi()` unchanged; `fromRow()` maps `supplier_ref`; admin list/detail SQL selects it; unit + integration tests | `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/AdminProductFinder.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php` (admin read only), `E-commerce/tests/Unit/Catalog/StorefrontProductSupplierRefTest.php`, `E-commerce/tests/Integration/Catalog/AdminProductSupplierRefReadTest.php` | WP1 | RED test fails first for the missing admin field; admin detail and list return `supplierRef`; `toApi()` output has no supplier key; Unit/Integration suites green |
| WP3 | Accept, validate, and persist the field on admin writes | R2, R10, R11 | WP1 column; existing `validate()`, `parameters()`, create/update SQL | Optional `supplierRef` in validation, INSERT/UPDATE column list, SQL parameters and types; functional tests | `E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php`, `E-commerce/tests/Functional/AdminProductSupplierRefWriteTest.php` | WP1 | POST and PUT with a value persist the trimmed value; `null`/blank persists `NULL`; 65 characters returns 422 with no write; existing `ref` required validation still passes its tests |
| WP4 | Consume the field in the admin API types and editor | R7, R10 | WP2/WP3 field contract | `supplierRef?: string \| null` on `AdminProductInput`; `supplierRef: string \| null` on `AdminProductRow` and `AdminProductDetail`; optional "Référence fournisseur" input in the product sheet, prefilled on edit, sent as `null` when blank; the sheet's prop type carries `supplierRef` as an admin-only extension without changing the public `Product`/`ProductApi` types | `Zidane_front_web/src/lib/api/admin.ts`, `Zidane_front_web/src/components/admin/ProductSheet.tsx` | WP2, WP3 | `npm run typecheck` and `npm run lint` pass; create with blank succeeds; edit shows the stored value and saves changes |
| WP5 | Display the field in the admin inspector | R8 | WP2 read contract | `InspectableProduct` includes `supplierRef`; `ProductFacts` renders a "Référence fournisseur" fact with `—` when unset | `Zidane_front_web/src/components/admin/ProductInspector.tsx` | WP2 | Inspector opened from Produits and from Approbations shows the stored value or `—`; typecheck and lint pass |
| WP6 | Prove the supplier reference never reaches the public backend surfaces | R5, R6 | WP1–WP3 completed; a product seeded with `supplier_ref` | Functional tests for list, search, detail, wishlist, and cart; projection test asserting the Meilisearch document and settings contain no supplier reference | `E-commerce/tests/Functional/StorefrontSupplierRefNonExposureTest.php`, `E-commerce/tests/Projection/Catalog/StorefrontProductSearchIndexerSupplierRefTest.php` | WP1, WP2, WP3 | Every public payload omits the key and value; the indexed document has no supplier key; `SEARCHABLE_ATTRIBUTES`/`FILTERABLE_ATTRIBUTES`/`SORTABLE_ATTRIBUTES` are unchanged; Functional and Projection suites green |
| WP7 | Verify the public front end does not consume the field | R9 | WP4 completed; public model, API types, product page, site components | Read-only audit confirming no supplier field is added to `Product`, `ProductApi`, `produit.$slug.tsx`, or `src/components/site/**`, recorded in the WP handoff | No production files (read-only: `Zidane_front_web/src/data/catalog.ts`, `src/lib/api/types.ts`, `src/routes/produit.$slug.tsx`, `src/components/site/**`) | WP4 | Audit finds no supplier reference in public types or rendered storefront code; typecheck and lint pass |

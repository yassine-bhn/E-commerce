# Spec: Optional supplier reference, visible only in the back office

Version: 1

## Goal

A product keeps its existing customer-facing reference and gains a second, optional reference that records the supplier's own reference. The new value is persisted by the backend, editable and readable through the existing Admin and Super Admin product surfaces, and absent from every customer-facing response, search index, and storefront view.

## Corrected prompt

In the backend a product currently has only one reference. Add another reference of the same kind, named as a supplier reference — the request suggests `supplierRefference` or `supplier-refference`, but the name must follow the project's conventions. The field is optional and holds the supplier's reference. In the front end, consume this feature. The product must never show the supplier reference to end users; it is shown only in the Admin or Super Admin interface.

## Requirements

| ID | Requirement | Type | Source in prompt | Verification |
|----|-------------|------|------------------|--------------|
| R1 | `storefront_product_view` gains a nullable column `supplier_ref VARCHAR(64)`; every pre-existing row reads back `NULL`, and no backfill is performed. | functional | "add aonther same reference" / "this field is optioanl" | Migration test asserts the column exists, is nullable, and is `varchar(64)`; an existing row still reads `NULL`. |
| R2 | The schema change is reversible: `down()` drops `supplier_ref` and nothing else, `up()` restores it, and no data migration is added. | constraint | "in the backend ... add" / project MIG-001 | Migrate up and down on a disposable database; the column disappears and returns; catalogue rows survive. |
| R3 | `POST /api/v1/admin/catalog/products` accepts an optional `supplierRef`; absent, JSON `null`, `""`, and whitespace-only values all persist SQL `NULL`. | functional | "this field is optioanl" | Functional create cases followed by a database assertion. |
| R4 | `PUT /api/v1/admin/catalog/products/{slug}` accepts `supplierRef`; a supplied value replaces the stored value, and `null` or a blank value clears it to SQL `NULL`. | functional | "in the backend ... add" / "this field is optioanl" | Functional update and clear cases followed by a database assertion. |
| R5 | A supplied supplier reference is trimmed before validation, persistence, and response: `"  SUP-42  "` is stored and returned as `"SUP-42"`. | functional | "add aonther same reference" / existing `ref` normalization | Functional create and update cases assert the trimmed value in the response and in the database. |
| R6 | A trimmed supplier reference longer than 64 characters is rejected with HTTP 422, and no row is created or changed. | validation | "add aonther same reference" / "choose the naming same of project" | POST with 65 characters returns 422 and inserts nothing; PUT with 65 characters returns 422 and leaves the stored value unchanged. |
| R7 | A `supplierRef` that is neither a string nor `null` is rejected with HTTP 422, and no write occurs. | validation | "this field is optioanl" / "it will get the refference of the supplier" | POST and PUT with an array, number, or boolean return 422 and leave the database unchanged. |
| R8 | The admin create and update responses contain `supplierRef` as a string or an explicit `null`. | functional | "it's only showen in the admin or super admin interface" | Functional assertions on the `POST` and `PUT` response bodies. |
| R9 | `GET /api/v1/admin/catalog/products/{slug}` and `PATCH /api/v1/admin/catalog/products/{slug}/publication` responses contain `supplierRef` as a string or an explicit `null`. | functional | "it's only showen in the admin or super admin interface" | Functional assertions on the show and publication response bodies. |
| R10 | `GET /api/v1/admin/catalog/products` returns `supplierRef` on every row, including rows where the stored value is `NULL`. | functional | "it's only showen in the admin or super admin interface" | Functional list assertion covers a row with a value and a row with `null`. |
| R11 | No public backend response contains a `supplierRef` or `supplier_ref` key, or the stored supplier value: product list, product search, product detail, wishlist, and cart. | constraint | "the product will never show to the users the reference of the supplier" | Functional sweep seeds a known supplier value and asserts the key and value are absent from every payload. |
| R12 | The Meilisearch `storefront_products` documents contain no supplier key or value, and `searchableAttributes`, `filterableAttributes`, and `sortableAttributes` remain unchanged. | constraint | "the product will never show to the users" / public search must not surface it | Projection test seeds a product with `supplier_ref`, asserts the exact document keys, and asserts the three settings arrays. |
| R13 | The public front end neither models nor renders the supplier reference: `Product`, `ProductApi`, `produit.$slug.tsx`, and `src/components/site/**` keep their current shape and content. | constraint | "the product will never show to the users the reference of the supplier" | Read-only audit plus `npm run typecheck` and `npm run lint`. |
| R14 | The admin product form has an optional "Référence fournisseur" input: empty on create, prefilled on edit, limited to 64 characters, and submitted as a trimmed string or `null`. | functional | "in the front you have to consume this feature" / "this field is optioanl" | Typecheck and lint pass; manual create, edit, and clear against the running back office. |
| R15 | The admin product inspector renders "Référence fournisseur" with the stored value, or `—` when the value is `null`, on both the Produits and Approbations surfaces. | functional | "it's only showen in the admin or super admin interface" | Manual check of both inspectors; typecheck and lint pass. |
| R16 | Access is unchanged: no public route is added, the field is returned only by the existing `/api/v1/admin/**` product routes guarded by `ROLE_ADMIN`, both `ROLE_ADMIN` and `ROLE_SUPER_ADMIN` can read and edit it under the existing ownership rules, and anonymous or customer calls to those routes stay denied. | constraint | "it's only showen in the admin or super admin interface" | Functional role matrix covers Admin, Super Admin, customer, and anonymous callers. |
| R17 | The existing `ref` is untouched: it stays required on create and update, limited to 64 characters, returned publicly, publicly searchable, and displayed as before; `supplierRef` is a separate optional field. | constraint | "there is only one reference for the product" / "add aonther same reference" | Existing reference tests stay green; public payload and search assertions still include `ref`. |
| R18 | Naming follows project conventions everywhere: JSON and TypeScript `supplierRef`, database `supplier_ref`, admin label "Référence fournisseur"; the prompt spellings `supplierRefference` and `supplier-refference` do not appear in code or API. | constraint | "choose the naming same of project" | Repository-wide grep and API contract assertions. |

## Assumptions

| # | Assumption | Risk if wrong |
|---|------------|---------------|
| 1 | "Same of project" resolves to the abbreviated form used by `ref`: `supplierRef` in JSON and TypeScript, `supplier_ref` in PostgreSQL. | low |
| 2 | The new field mirrors `ref`'s storage width of 64 characters. | low |
| 3 | The field is free text; no supplier table, supplier entity, foreign key, or supplier lifecycle is introduced. | low |
| 4 | "Optional" means absent, `null`, empty, and whitespace-only are accepted, and every blank form is normalized to `NULL` rather than stored as `''`. | low |
| 5 | Both back-office roles may read and edit the field; it is not restricted to Super Admins. | low |
| 6 | The `PUT` endpoint keeps its full-replacement semantics, so omitting `supplierRef` clears the stored value. | med |
| 7 | The admin list carries the field so the inspector can open from a list row without a second fetch. | low |
| 8 | Admin list search (`q`) is not extended: it keeps matching name, slug, `ref`, and brand only. | med |
| 9 | The admin products table keeps its `ref · slug` subtitle; the supplier reference appears in the editor and inspector, not in the table cell. | low |
| 10 | Wishlist and cart are customer-facing surfaces even when the caller is authenticated. | low |
| 11 | Back-office labels stay French, so the field is labelled "Référence fournisseur". | low |
| 12 | No backfill is possible or needed: existing products have no known supplier reference and stay `NULL`. | low |
| 13 | The supplier reference is not unique and does not affect product identity, slug, approval, or publication. | low |
| 14 | Admin responses include `supplierRef: null` explicitly when unset, while public responses omit the key entirely rather than emitting `null`. | low |
| 15 | The write stays in the existing `AdminProductController`; no new command, aggregate, or query-handler write is introduced, preserving QRY-001. | low |
| 16 | The 64-character limit is measured in characters after trimming, consistent with the existing `ref` validation. | low |
| 17 | No dedicated audit event is added; the existing `CATALOG_PRODUCT_UPDATED` entry keeps recording the update without field-level values. | low |

## Open questions

- [minor] Confirm `supplierRef` / `supplier_ref` over the longer `supplierReference` / `supplier_reference`. Default if unanswered: `supplierRef` / `supplier_ref`, matching the existing abbreviated `ref`.
- [minor] On `PUT`, should an omitted `supplierRef` clear the stored value or leave it unchanged? Default if unanswered: clear it, matching the endpoint's full-replacement behaviour.
- [minor] Should the admin list search (`q`) also match the supplier reference? Default if unanswered: no, search behaviour is unchanged.
- [minor] Should the admin products table row show the supplier reference beside `ref`, or only the inspector and editor? Default if unanswered: inspector and editor only.
- [minor] Must the supplier reference be unique? Default if unanswered: no uniqueness constraint.
- [minor] Is 64 characters the intended maximum? Default if unanswered: 64, consistent with `ref`.
- [minor] Should both Admin and Super Admin edit the field? Default if unanswered: yes, both, under the existing ownership rules.
- [minor] Should public responses omit the key or return `supplierRef: null`? Default if unanswered: omit the key entirely, so no public contract advertises the field.

## Out of scope

- Supplier master data: supplier records, contacts, lead times, supplier pricing, supplier stock, or a supplier portal.
- Multiple supplier references per product or any product-to-supplier relationship with a cardinality other than one optional text value.
- Uniqueness validation, foreign keys, or validation against a supplier list.
- Public search, filters, facets, sorting, or display of the supplier reference.
- Indexing the supplier reference in Meilisearch, including as a searchable, filterable, or sortable attribute.
- Backfilling existing products with supplier references.
- Showing the supplier reference in orders, order lines, invoices, e-mails, PDFs, exports, imports, or audit messages.
- Changing the approval or publication workflow beyond the existing admin save behaviour.
- Restricting the field to Super Admins only.
- Changing the admin products table layout or its `ref · slug` subtitle.
- Redesigning the public product page or the public `Product` / `ProductApi` models.
- Renaming, removing, or changing the existing `ref`.
- Bulk editing, CSV import, or a new API version.
- Migrating the legacy `catalog_products` table or its demo `Product` aggregate.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add the nullable column and prove the schema | R1, R2 | Existing `storefront_product_view` DDL, migration conventions, latest migration `Version20261101000000` | New reversible migration adding `supplier_ref VARCHAR(64) DEFAULT NULL`, plus a schema integration test | `E-commerce/migrations/Version20261105000000.php`, `E-commerce/tests/Integration/Catalog/SupplierRefSchemaTest.php` | none | RED test fails before the migration; `doctrine:migrations:migrate -n` succeeds on dev and test; the column is nullable `varchar(64)`; existing rows read `NULL`; `down()` drops only the column; `up()` restores it; Integration suite green |
| WP2 | Carry the field through the admin read model without changing the public payload | R9, R10, R17, R18 | WP1 column; `StorefrontProductView`, `AdminProductFinder`, `StorefrontProductRepository` | `StorefrontProductView` gains `?string $supplierRef` as a trailing constructor argument defaulting to `null`; `fromRow()` maps `supplier_ref` with a `null` fallback; `toAdminApi()` adds `supplierRef`; `toApi()` stays unchanged; admin finder and repository SELECTs include the column; unit and integration tests | `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/AdminProductFinder.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php`, `E-commerce/tests/Unit/Catalog/StorefrontProductSupplierRefTest.php`, `E-commerce/tests/Integration/Catalog/AdminProductSupplierRefReadTest.php` | WP1 | RED unit test fails first for the missing admin field; admin list and detail return a string or `null`; `toApi()` has no supplier key; existing `ref` stays public; Unit and Integration suites green; PHPStan and php-cs-fixer clean |
| WP3 | Accept, validate, and persist the optional field on the admin write path | R3, R4, R5, R6, R7, R8, R16, R17, R18 | WP1 column; WP2 admin projection; existing `validate()`, `parameters()`, create and update SQL | Optional `supplierRef` parsing with trim, blank-to-`null`, 64-character limit, non-string rejection, INSERT and UPDATE column plus parameter, and admin responses through `adminPayload()`; functional tests | `E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php`, `E-commerce/tests/Functional/AdminProductSupplierRefWriteTest.php` | WP1, WP2 | RED functional test fails first; POST and PUT persist trimmed values; absent, `null`, and blank clear to `NULL`; 65 characters returns 422 with no write; non-string returns 422; both roles succeed under existing rules; existing `ref` validation tests stay green; Functional suite green |
| WP4 | Model the field in the admin API types and expose it in the create/edit form | R13, R14, R18 | WP2 and WP3 field contract | `AdminProductInput` gains optional `supplierRef: string or null`; `AdminProductRow` and `AdminProductDetail` gain `supplierRef: string or null`; the product sheet gains a "Référence fournisseur" input, blank on create, prefilled on edit, `maxLength` 64, submitted as trimmed text or `null`; the public `Product` and `ProductApi` types are untouched | `Zidane_front_web/src/lib/api/admin.ts`, `Zidane_front_web/src/components/admin/ProductSheet.tsx` | WP2, WP3 | `npm run typecheck` and `npm run lint` pass; create with the field blank succeeds; edit shows the stored value; clearing the field saves `null`; the input is not added to the required-field guard |
| WP5 | Display the field in the shared admin inspector | R15 | WP2 read contract | `InspectableProduct` includes `supplierRef?: string or null`; `ProductFacts` renders "Référence fournisseur" with the value or `—`; the shared component covers both Produits and Approbations | `Zidane_front_web/src/components/admin/ProductInspector.tsx` | WP2 | Inspector opened from Produits and from Approbations shows the stored value or `—`; typecheck and lint pass |
| WP6 | Prove the field never reaches public backend surfaces or public search | R11, R12, R16, R17 | WP1, WP2, and WP3 completed; a product seeded with a known `supplier_ref` | Functional sweep over product list, search, detail, wishlist, and cart, including denied public access to admin routes; projection test for document keys and settings arrays | `E-commerce/tests/Functional/StorefrontSupplierRefNonExposureTest.php`, `E-commerce/tests/Projection/Catalog/StorefrontProductSearchIndexerSupplierRefTest.php` | WP2, WP3 | Every public payload omits the key and the value; admin routes reject anonymous and customer callers; the index document has no supplier key; the three settings arrays are unchanged; the existing exact-key indexer test stays green; Functional and Projection suites green |
| WP7 | Audit the public front end and run the full verification gate | R13, R16, R17, R18 | WP4, WP5, WP6 completed | Read-only audit of the public model, public API types, product route, and site components, plus a recorded run of the backend and frontend gates | No production files; read-only audit of `Zidane_front_web/src/data/catalog.ts`, `Zidane_front_web/src/lib/api/types.ts`, `Zidane_front_web/src/routes/produit.$slug.tsx`, `Zidane_front_web/src/components/site/**` | WP4, WP5, WP6 | No supplier field appears in public types or rendered storefront code; the public `Réf.` and `Référence` display is unchanged; Unit, Integration, Projection, Functional, and Contract suites green; PHPStan, php-cs-fixer, and Deptrac clean; frontend typecheck and lint green; no unrelated file changed |

Dependency graph: `WP1 -> WP2 -> WP3 -> (WP4 || WP6) -> WP7`, plus `WP2 -> WP5`.

Parallel batches: WP1 alone; then WP2; then WP3 and WP5 together; then WP4 and WP6 together; then WP7. No two packages own the same file.

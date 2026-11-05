# Spec: Add purchase price, VAT rate and sale price (TTC) to catalog products

Version: 1

## Goal

Add pricing/cost columns (purchase price, VAT rate, sale price TTC) to the backend product read/write model and consume them in the frontend, while keeping money in integer minor units and leaving the existing customer-facing price contract intact unless a field is explicitly designated customer-facing.

## Corrected prompt

In the backend, add the following columns to the product: Purchase price, VAT rate, Sale price (TTC). Then consume them in the front end. (The prompt lists "VAT rate" twice and writes "Purchase priceVAT rate" without a separator; the exact, deduplicated column set and their public/admin visibility are unresolved — see Open questions.)

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A reversible migration adds nullable columns to `storefront_product_view` for purchase price, VAT rate and sale price (TTC); existing rows keep `NULL`; the migration performs no data backfill. | functional | prompt lines 1-4; context §CSV source (cols 46, 37, 34); context convention "no data backfill in migrations" | must |
| R2 | Canonical identifiers follow the project convention: camelCase in JSON/TS and snake_case in PostgreSQL (`purchasePrice`/`purchase_price_minor`, `vatRate`/`vat_rate`, `salePriceTtc`/`sale_price_ttc_minor`); no alternate spelling is used. | constraint | prompt "add those column"; context §Conventions identifiers | must |
| R3 | All three fields are optional: an absent key, JSON `null`, `""`, and whitespace-only values persist SQL `NULL`. | functional | prompt "add those column"; context convention "Optional semantics: blank/null/absent/whitespace → NULL" | must |
| R4 | Admin product creation accepts the three optional fields and persists them. | functional | prompt "then consume it in front-end"; context §Key backend files admin write path | must |
| R5 | Admin product update accepts the three fields with the endpoint's full-replacement semantics: a supplied value replaces the stored value; an absent key, `null`, `""`, or whitespace-only clears it to SQL `NULL`. | functional | context §Conventions; context §CSV source | must |
| R6 | Money fields (purchase price, sale price TTC) are validated as non-negative decimal DT amounts with up to 3 decimals, converted to integer millimes, never stored as float; invalid input is rejected with HTTP 422 and no row is created or changed. | functional | context ECO-001 "Money: integer minor units (millimes)"; existing `decimalMinor()` validation in `AdminProductController` | must |
| R7 | VAT rate is validated as a numeric percentage with at most 2 decimals in the range 0–100; invalid input is rejected with HTTP 422 and no write occurs. | functional | context §CSV source (19.0000 or 7.0000); prompt "VAT rate" | should |
| R8 | Admin create, update, show and list responses contain the three fields as a number or an explicit `null`. | functional | prompt "then consume it in front-end"; context §Existing supplier-reference feature admin surfaces | must |
| R9 | Purchase price and VAT rate are admin-only: they are absent from every public backend response (product list, search, detail, wishlist, cart) and from Meilisearch documents/settings. | constraint | prompt "add those column to the product" (no public requirement stated); context §Existing supplier-reference R11/R12 privacy pattern | must |
| R10 | Sale price (TTC) is added to the public payload only if it is explicitly designated the customer-facing price in a separate decision; otherwise it stays admin-only and `toApi()` remains unchanged. | constraint | prompt ambiguity; context §Existing supplier-reference R11/R19 | must |
| R11 | Admin TypeScript types model the fields: `AdminProductInput` gains optional fields, `AdminProductRow` and `AdminProductDetail` gain required `number | null` fields; public `Product` and `ProductApi` stay unchanged unless R10 designates a field customer-facing. | functional | prompt "then consume it in front-end"; context §Key frontend files | must |
| R12 | The admin product create/edit form has optional inputs for the three fields: blank on create, prefilled on edit, not part of the required-field guard, and submitted as a decimal string or `null` for money and a number or `null` for VAT rate. | functional | prompt "then consume it in front-end"; context §Existing supplier-reference R14 | must |
| R13 | The admin product inspector shows the three values (or `—` when unset) in both the Produits and Approbations surfaces. | functional | prompt "then consume it in front-end"; context §Existing supplier-reference R15 | should |
| R14 | The existing `price`/`oldPrice`/`ref` behaviour is untouched: required `ref`, 64-character limit, non-unique, publicly returned and displayed as before. | constraint | context §Existing supplier-reference R16; prompt "add those column" (additive only) | must |
| R15 | Access is unchanged: no new public route, fields returned only by existing `/api/v1/admin/**` product routes unless R10 applies, both `ROLE_ADMIN` and `ROLE_SUPER_ADMIN` may read/edit under existing ownership rules, anonymous/customer calls stay denied. | constraint | context §Conventions admin routes; context §Existing supplier-reference R17 | must |
| R16 | The three fields do not participate in slug generation, product identity, uniqueness, publication, approval, stock, cart, ordering, admin `q` search, public search ranking, or Meilisearch settings. | constraint | context §Existing supplier-reference R18; prompt "add those column" | must |
| R17 | The importer (`scripts/import-cbm/import.php` and `match.py`) is not changed by this spec unless an explicit decision maps CSV columns 33/34/36/37/46 to the new columns; any such mapping is a separate work item. | constraint | context §CSV source; prompt does not mention import | should |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The duplicated "VAT rate" in the prompt is a typo and there is exactly one VAT-rate column (CSV column 37, values 19.0000/7.0000). | med |
| 2 | "Purchase price" maps to CSV column 46 (cost price) and "Sale price (TTC)" maps to CSV column 34; column 33 (price HT) remains the existing `price`. | med |
| 3 | Money fields are stored as integer millimes (`_minor`) and exposed as integer millimes in JSON, consistent with ECO-001 and the existing `price`. | low |
| 4 | VAT rate is stored as a percentage number (`NUMERIC(5,2)`, e.g. 19.00) and exposed as a JSON number, not as basis points. | med |
| 5 | Purchase price and VAT rate are admin-only cost/tax metadata, not customer-facing. | med |
| 6 | The new fields are optional and nullable; `0` is a valid value distinct from `NULL` for VAT rate and money. | low |
| 7 | Full-replacement update semantics apply: an omitted field clears the stored value, matching the existing `PUT` behaviour. | med |
| 8 | Validation ranges (VAT 0–100, non-negative money) and 3-decimal precision are inferred from the existing price validation; the prompt does not specify them. | med |
| 9 | Admin inputs for money use canonical decimal DT strings, consistent with `price`/`oldPrice`. | low |
| 10 | No backfill is possible or needed; existing products keep `NULL`. | low |
| 11 | Meilisearch settings and public search remain unchanged. | low |
| 12 | The write stays in the existing `AdminProductController`; no new command, aggregate, or query handler is introduced, preserving QRY-001. | low |

## Open questions

- [blocking] The prompt lists "VAT rate" twice and concatenates "Purchase priceVAT rate": is the intended set three columns (Purchase price, VAT rate, Sale price (TTC)) or four, and if four, what is the second VAT-rate column? Default if unanswered: three unique columns.
- [blocking] Are Purchase price and VAT rate customer-facing or admin-only? Default if unanswered: admin-only.
- [blocking] Is Sale price (TTC) the customer-facing price that should replace/augment the existing `price` (HT), or a parallel admin field? Default if unanswered: parallel admin field; public `price` unchanged.
- [major] What JSON keys and DB column names are canonical (`purchasePrice`/`purchase_price_minor`, `vatRate`/`vat_rate`, `salePriceTtc`/`sale_price_ttc_minor`)? Default if unanswered: the names above.
- [major] Should VAT rate be stored as a percentage (`NUMERIC(5,2)`) or integer basis points? Default if unanswered: percentage.
- [major] What is the VAT rate precision and valid range? Default if unanswered: 2 decimals, 0–100.
- [major] Should Sale price (TTC) be validated against `price` and `vatRate` (e.g. TTC = HT × (1 + VAT)), or stored independently? Default if unanswered: stored independently, no cross-field validation.
- [major] Should the importer map CSV columns 33/34/36/37/46 to the new columns? Default if unanswered: no, importer unchanged.
- [minor] Should purchase price / sale price TTC / VAT rate appear in the admin products table row or only the editor and inspector? Default if unanswered: editor and inspector only.
- [minor] Should admin `q` search match any of the new fields? Default if unanswered: no.
- [minor] Should the Meilisearch index gain a sortable/filterable price field for TTC? Default if unanswered: no.

## Out of scope

- Supplier master data, supplier pricing workflows, or supplier portals.
- Tax engine, invoicing, or VAT reporting beyond storing the rate.
- Changing the existing `price` semantics or the public storefront price display unless R10 is resolved.
- Bulk editing, CSV import changes, or a new API version.
- Migrating the legacy `catalog_products` table.
- Changing approval, publication, stock, cart, or ordering flows.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add the nullable columns reversibly and prove rollback | R1, R2 | Existing `storefront_product_view` DDL, MIG-001, `MigrationProbeDatabase` | New migration adding `purchase_price_minor`, `vat_rate`, `sale_price_ttc_minor`, plus an integration test that migrates up, asserts nullability/types and rolls back | `E-commerce/migrations/Version20261106000000.php`, `E-commerce/tests/Integration/Catalog/ProductPricingMigrationTest.php` | none | RED test fails before the migration exists; migrate up/down succeed on dev and test; columns are nullable with the expected types; existing rows read `NULL`; `down()` drops only the three columns; no `INSERT`/`UPDATE`/`DELETE`; Integration suite green |
| WP2 | Carry the three fields through admin reads without changing public payloads | R2, R8, R9, R10, R14 | WP1 columns; `StorefrontProductView`, `AdminProductFinder`, `StorefrontProductRepository` | Optional constructor params; `toAdminApi()` adds the three fields; `toApi()` unchanged unless R10; `fromRow()` maps columns when present and falls back to `null`; admin SELECT includes the columns; unit + integration tests | `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/AdminProductFinder.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php`, `E-commerce/tests/Unit/Catalog/StorefrontProductViewPricingTest.php`, `E-commerce/tests/Integration/Catalog/AdminProductPricingReadTest.php` | WP1 | RED test fails first; `toApi()` key set has no new keys unless R10; `toAdminApi()` returns numbers or `null`; `fromRow()` works with and without the columns; admin detail/list return the fields; public SELECT still omits them; Unit and Integration suites green |
| WP3 | Accept, validate and persist the three fields on the admin write path | R3, R4, R5, R6, R7, R8, R15 | WP1 columns; WP2 admin projection; existing `validate()`, `parameters()`, create/update SQL | Optional parsing with blank-to-`null`, money decimal validation, VAT validation, INSERT/UPDATE columns and parameters, admin responses through `adminPayload()`; functional tests | `E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php`, `E-commerce/tests/Functional/AdminProductPricingWriteTest.php` | WP1, WP2 | RED functional test fails first; POST/PUT persist trimmed/validated values; absent, `null`, blank clear to `NULL`; invalid money/VAT return 422 with no write; both roles succeed under existing rules; existing price/ref tests stay green; Functional suite green |
| WP4 | Model the fields in admin API types and expose them in the create/edit form | R2, R11, R12 | WP2/WP3 contract | `AdminProductInput`, `AdminProductRow`, `AdminProductDetail` updated; `ProductSheet.tsx` gains three optional inputs, blank on create, prefilled on edit, not in required-field guard, submitted as decimal string or `null` for money and number or `null` for VAT | `Zidane_front_web/src/lib/api/admin.ts`, `Zidane_front_web/src/components/admin/ProductSheet.tsx` | WP2, WP3 | typecheck and lint pass; create with blank fields succeeds and sends `null`; edit shows stored values; clearing saves `null`; inputs not added to required-field guard; public types unchanged unless R10 |
| WP5 | Display the fields in the shared admin inspector | R13 | WP2 read contract; WP4 flow | `InspectableProduct` includes the three fields; `ProductFacts` renders them with value or `—`; shared component covers Produits and Approbations | `Zidane_front_web/src/components/admin/ProductInspector.tsx` | WP2, WP4 | Inspector opened from Produits and Approbations shows stored values or `—`; typecheck and lint pass |
| WP6 | Prove the new fields never reach public backend surfaces or public search | R9, R10, R15, R16 | WP1, WP2, WP3 completed; product seeded with known values | Functional sweep over product list, search, detail, wishlist, cart, denied admin access, plus projection test for document keys and settings arrays | `E-commerce/tests/Functional/StorefrontProductPricingNonExposureTest.php`, `E-commerce/tests/Projection/Catalog/StorefrontProductSearchIndexerPricingTest.php` | WP1, WP2, WP3 | Every public payload omits the keys and values (unless R10); admin routes reject anonymous/customer callers; index document has no new keys; settings arrays unchanged; Functional and Projection suites green |
| WP7 | Audit the public front end and run the full verification gate | R10, R14, R15, R16 | WP4, WP5, WP6 completed | Read-only audit of public model, API types, product route and site components, plus recorded backend/frontend gate run | No production files; read-only audit of `Zidane_front_web/src/data/catalog.ts`, `src/lib/api/types.ts`, `src/routes/produit.$slug.tsx`, `src/components/site/**` | WP4, WP5, WP6 | No non-designated field appears in public types or rendered storefront code; product page still shows `Réf. {p.ref}`; all backend suites green; PHPStan, php-cs-fixer, Deptrac clean; frontend typecheck and lint green |

Dependency graph: `WP1 -> WP2 -> WP3 -> (WP4 || WP6)`, plus `WP2 -> WP5` and `WP5` final-gated after `WP4`; `WP7` after `WP4`, `WP5`, and `WP6`. No two packages own the same file. WP7 is read-only.

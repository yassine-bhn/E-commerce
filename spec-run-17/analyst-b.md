# Spec: Add purchase price, VAT rate and sale price (TTC) to products

Version: 1

## Goal

A product gains three additional pricing/tax attributes — a purchase (cost) price, a VAT rate, and a sale price TTC — persisted by the backend and consumed by the admin frontend (form and inspector), while the existing sale price HT, reference, and public contract remain unchanged.

## Corrected prompt

In the backend, add the following columns to the product: purchase price, VAT rate, and sale price (TTC). Then consume these fields in the front end. (The prompt's first line runs "Purchase price" and "VAT rate" together; "VAT rate" is listed twice — treated as a single VAT-rate column, see assumptions.)

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A reversible migration adds nullable columns `purchase_price_minor INTEGER`, `vat_rate NUMERIC(5,2)` and `sale_price_ttc_minor INTEGER` to `storefront_product_view`; existing rows keep `NULL` and the migration performs no data backfill. | functional | "in backed add those column to the product" | must |
| R2 | The canonical identifiers are `purchasePrice` / `purchase_price_minor`, `vatRate` / `vat_rate`, and `salePriceTtc` / `sale_price_ttc_minor` (camelCase JSON/TS, snake_case SQL); no other spelling is used. | constraint | "choose the naming same of project" (implied by spec-run-16 convention) | must |
| R3 | Money fields are stored and returned as integer millimes (never float); `vatRate` is stored and returned as a percentage number (e.g. 19 or 7). | constraint | "Money: integer minor units (millimes)" (context) | must |
| R4 | Admin product creation accepts optional `purchasePrice`, `vatRate`, and `salePriceTtc`; an absent key, JSON `null`, `""`, and whitespace-only values all persist SQL `NULL`. | functional | "add those column"; "then consume it in front-end" | must |
| R5 | Admin product update accepts the three fields; a supplied value replaces the stored value, and an absent key, `null`, `""`, or whitespace-only value clears it to SQL `NULL` (full-replacement semantics). | functional | "add those column" | must |
| R6 | A supplied purchase price or sale price TTC is trimmed/parsed as a decimal DT amount into millimes before validation and persistence; a malformed or negative value is rejected with HTTP 422 and no row is created or changed. | functional | "Purchase price"; "Sale price (TTC)" (mirrors existing price handling) | must |
| R7 | A supplied VAT rate is parsed as a non-negative decimal percentage with up to 2 decimals; a malformed or negative value is rejected with HTTP 422 and no row is created or changed. | functional | "VAT rate" | must |
| R8 | Admin create and update responses contain `purchasePrice`, `vatRate`, and `salePriceTtc` as a number or an explicit `null`. | functional | "then consume it in front-end" (admin surface) | must |
| R9 | `GET /api/v1/admin/catalog/products` returns the three fields as a number or `null` on every row, including rows where the stored value is `NULL`. | functional | "then consume it in front-end" (admin surface) | must |
| R10 | `GET /api/v1/admin/catalog/products/{slug}` and `PATCH .../publication` responses contain the three fields as a number or `null`. | functional | "then consume it in front-end" (admin surface) | must |
| R11 | No public backend response contains the new keys or stored values: product list, product search, product detail, wishlist, and cart keep their current shape. | constraint | implied by "front-end" consumption being admin-scoped and by the supplier-reference privacy precedent | should |
| R12 | Meilisearch `storefront_products` documents and settings (`searchableAttributes`, `filterableAttributes`, `sortableAttributes`) are unchanged by this feature. | constraint | "consume it in front-end" must not alter public search (precedent R12) | should |
| R13 | Admin TypeScript types model the fields: `AdminProductInput` gains optional `purchasePrice?: string \| null`, `vatRate?: string \| null`, `salePriceTtc?: string \| null`; `AdminProductRow` and `AdminProductDetail` gain required `purchasePrice: number \| null`, `vatRate: number \| null`, `salePriceTtc: number \| null`; public `Product`/`ProductApi` stay unchanged. | functional | "then consume it in front-end" | must |
| R14 | The admin product create/edit form has optional inputs for the three fields: blank on create, prefilled on edit, not part of the required-field guard, submitted as trimmed decimal strings or `null`. | functional | "then consume it in front-end" | must |
| R15 | The admin product inspector shows "Prix d'achat", "Taux de TVA" and "Prix de vente (TTC)" facts with the stored value or `—` when unset. | functional | "then consume it in front-end" | should |
| R16 | The existing `price` (sale price HT), `oldPrice`, `ref`, stock, and other product behaviour are untouched. | constraint | "add those column" (additive only) | must |
| R17 | Access is unchanged: no new public route; fields returned only by existing `/api/v1/admin/**` product routes; both `ROLE_ADMIN` and `ROLE_SUPER_ADMIN` may read/edit under existing ownership rules; anonymous/customer denied. | constraint | "consume it in front-end" (admin surface) | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The prompt's first line "Purchase priceVAT rate" is two intended columns ("Purchase price" and "VAT rate") run together; the separate "VAT rate" line is a duplicate of the same column, so there is one VAT-rate field, not two. | high |
| 2 | "Front-end" consumption means the admin front end (ProductSheet + ProductInspector), mirroring the supplier-reference feature; no public storefront display or public type changes are required. | med |
| 3 | The three columns are optional (nullable) and blank/null/absent/whitespace normalise to SQL `NULL`, because existing rows cannot be backfilled and admin input may be incomplete. | med |
| 4 | Purchase price and sale price TTC follow the existing money convention: decimal DT input (up to 3 decimals) → integer millimes in storage/API. | low |
| 5 | VAT rate is a percentage stored as `NUMERIC(5,2)` and exchanged as a JSON number (not millimes, not a string). | med |
| 6 | The new fields are admin-managed free values (no computation, no validation that TTC = HT × (1 + VAT)); the backend does not derive one from the others. | med |
| 7 | The fields are not added to Meilisearch documents or settings and do not affect search ranking/filters. | low |
| 8 | Labels in the French admin UI are "Prix d'achat", "Taux de TVA", "Prix de vente (TTC)". | low |
| 9 | No importer/CSV/backfill changes are required; the importer keeps mapping its current subset. | low |
| 10 | The write stays in `AdminProductController`; no new command, aggregate, or query handler is introduced, preserving QRY-001. | low |

## Open questions

- [BLOCKING] Does "Purchase priceVAT rate" mean two columns ("Purchase price" and "VAT rate") or one combined concept? Default if unanswered: two separate columns, one VAT rate.
- [BLOCKING] Is the VAT rate a single column, or are separate purchase-VAT and sale-VAT rates intended (the prompt lists "VAT rate" twice)? Default if unanswered: a single VAT-rate column.
- [minor] Should the new fields be public (storefront) or admin-only? Default if unanswered: admin-only, matching the supplier-reference precedent.
- [minor] Should purchase price / sale price TTC / VAT rate be required on create, or optional? Default if unanswered: optional (nullable), to preserve existing rows and partial admin input.
- [minor] VAT-rate precision: 2 decimals (e.g. 19.00) or 4 decimals (as in the CSV's 19.0000)? Default if unanswered: `NUMERIC(5,2)` (2 decimals).
- [minor] Should sale price TTC be entered manually or derived from the HT price and VAT rate? Default if unanswered: entered manually, no derivation.

## Out of scope

- Supplier master data, supplier pricing agreements, or supplier stock.
- Tax calculation, invoice generation, accounting, or order-level VAT.
- Changing the existing sale price HT (`price`) semantics or the public displayed price.
- Public storefront display of the new fields.
- CSV importer updates or backfilling existing products.
- Bulk editing or a new API version.
- Migrating the legacy `catalog_products` table.
- Uniqueness, foreign keys, or validation against external lists.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add the three nullable columns reversibly with no backfill | R1, R2 | Existing `storefront_product_view` DDL; migration pattern from spec-run-16 WP1 | New migration `Version20261106000000.php` (or next date-based) adding `purchase_price_minor INTEGER`, `vat_rate NUMERIC(5,2)`, `sale_price_ttc_minor INTEGER`, all `DEFAULT NULL`, plus `down()` dropping them | `E-commerce/migrations/Version20261106000000.php` | none | Migration up/down succeeds on dev and test; `information_schema` reports nullable columns with correct types; no `INSERT`/`UPDATE`/`DELETE` in the migration; Integration suite green |
| WP2 | Carry the three fields through admin reads without changing public payloads | R2, R3, R8, R9, R10, R11, R12, R16 | WP1 columns; `StorefrontProductView`, `AdminProductFinder`, `StorefrontProductRepository` | Optional constructor params on the view; `toAdminApi()` adds the three keys; `toApi()` unchanged; `fromRow()` maps each column when present with null fallback; admin SELECTs include the columns; public SELECT unchanged | `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/AdminProductFinder.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php` | WP1 | RED test fails first; `toApi()` key set has none of the new keys; `toAdminApi()` returns number or null; `fromRow()` works with and without the columns; admin detail/list return the fields; public SELECT still omits them; Unit/Integration green; PHPStan and php-cs-fixer clean |
| WP3 | Accept, validate and persist the three optional fields on the admin write path | R4, R5, R6, R7, R8, R17 | WP1 columns; WP2 admin projection; existing `validate()`, `parameters()`, create/update SQL | Parsing with trim, blank-to-null, decimal validation and 422 rejection; INSERT/UPDATE columns and parameters; admin responses via `adminPayload()` | `E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php` | WP1, WP2 | RED functional test fails first; POST/PUT persist trimmed values; absent/null/blank clear to `NULL`; malformed/negative values return 422 with no write; both roles succeed under existing rules; existing price/ref tests stay green; Functional suite green |
| WP4 | Model the fields in admin API types and expose them in the create/edit form | R2, R13, R14, R16 | WP2/WP3 contract | `AdminProductInput` gains optional string-or-null fields; `AdminProductRow`/`AdminProductDetail` gain required number-or-null fields; ProductSheet gains "Prix d'achat", "Taux de TVA", "Prix de vente (TTC)" inputs (blank on create, prefilled on edit, not in required guard), submitted as trimmed strings or null; public types untouched | `Zidane_front_web/src/lib/api/admin.ts`, `Zidane_front_web/src/components/admin/ProductSheet.tsx` | WP2, WP3 | `npm run typecheck` and `npm run lint` pass; create with fields blank sends null; edit shows stored values; clearing sends null; inputs not in required guard; public types unchanged |
| WP5 | Display the three fields in the shared admin inspector | R15 | WP2 read contract; WP4 flow | `InspectableProduct` includes the three optional fields; `ProductFacts` renders the three facts with value or `—` | `Zidane_front_web/src/components/admin/ProductInspector.tsx` | WP2, WP4 | Inspector opened from Produits and Approbations shows stored values or `—`; typecheck and lint pass |
| WP6 | Prove the fields never reach public backend surfaces or public search | R11, R12, R17 | WP1–WP3 completed; product seeded with known values | Functional sweep over product list, search, detail, wishlist, cart including denied admin access; projection test for document keys and settings arrays | `E-commerce/tests/Functional/StorefrontPurchaseVatSaleNonExposureTest.php`, `E-commerce/tests/Projection/Catalog/StorefrontProductSearchIndexerPurchaseVatSaleTest.php` | WP1, WP2, WP3 | Every public payload omits the keys and values; admin routes reject anonymous/customer; index document has no new keys; settings arrays unchanged; Functional and Projection suites green |
| WP7 | Audit the public front end and run the full verification gate | R16, R17 | WP4, WP5, WP6 completed | Read-only audit of public model, API types, product route, site components; recorded run of backend and frontend gates | No production files; read-only audit of `Zidane_front_web/src/data/catalog.ts`, `src/lib/api/types.ts`, `src/routes/produit.$slug.tsx`, `src/components/site/**` | WP4, WP5, WP6 | No new field appears in public types or rendered storefront code; product page still shows `Réf. {p.ref}`; all backend suites green; PHPStan, php-cs-fixer, Deptrac clean; frontend typecheck and lint green |

Dependency graph: `WP1 -> WP2 -> WP3 -> (WP4 || WP6)`, plus `WP2 -> WP5` and `WP5` final-gated after `WP4`; `WP7` after `WP4`, `WP5`, and `WP6`. No two packages own the same file. WP7 is read-only.
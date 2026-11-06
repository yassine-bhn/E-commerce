# Spec: Additional pricing columns (purchase price, VAT rate, sale price TTC) on catalog products

Version: 1

## Goal

A catalog product keeps its existing `price`/`ref` and gains three additional pricing attributes — a purchase (cost) price, a VAT rate, and a sale price TTC — persisted by the backend, readable and editable through the existing Admin and Super Admin product surfaces, and consumed in the front-end without changing the existing public product contract unless a separate decision says otherwise.

## Corrected prompt

In the backend, add the following columns to the product: a purchase price, a VAT rate, and a sale price (TTC). (The prompt lists "VAT rate" twice, or as a merged "Purchase priceVAT rate"; this is read as a single VAT-rate column — see the open questions.) Then consume these fields in the front-end.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A reversible migration adds three nullable columns to `storefront_product_view` — `purchase_price_minor`, `vat_rate`, and `sale_price_ttc_minor` — with no data backfill and no default value; existing rows keep `NULL`. | functional | "in backed add those column to the product" | must |
| R2 | Canonical identifiers are `purchasePrice` / `purchase_price_minor`, `vatRate` / `vat_rate`, and `salePriceTtc` / `sale_price_ttc_minor` (JSON/TypeScript and PostgreSQL respectively); no other spelling is introduced. | constraint | "add those column"; project naming convention | must |
| R3 | The two money fields are stored as integer minor units (millimes) per ECO-001; a float is never used. The VAT rate is stored as a decimal percentage, never as a float. | constraint | "Money: integer minor units (millimes) — never float (ECO-001)" | must |
| R4 | Admin product creation accepts an optional `purchasePrice` as a decimal DT string (or `null`/absent/blank), trims it, parses it with `Money::fromDecimalString`, and stores `NULL` when unset. | functional | "Purchase price"; "then consume it in front-end" | must |
| R5 | Admin product update accepts `purchasePrice` under the same rules as R4 and replaces the stored value (absent/`null`/blank clears it to `NULL`). | functional | "Purchase price" | must |
| R6 | Admin product creation and update accept an optional `vatRate` as a decimal percentage string or number (or `null`/absent/blank), validate its precision, and store `NULL` when unset. | functional | "VAT rate" | must |
| R7 | Admin product creation and update accept an optional `salePriceTtc` as a decimal DT string (or `null`/absent/blank), trims it, parses it with `Money::fromDecimalString`, and stores `NULL` when unset. | functional | "Sale price (TTC)" | must |
| R8 | An invalid value for any of the three fields (malformed decimal, negative money amount, more than three decimals for money, unsupported VAT precision, or a non-string/non-number/non-null type) returns HTTP 422 and writes no row / leaves the existing row unchanged. | functional | "add those column"; "Optional semantics: blank/null/absent/whitespace → NULL" | must |
| R9 | Admin create and update responses include `purchasePrice`, `vatRate`, and `salePriceTtc` as numbers or explicit `null`, equal to the persisted values. | functional | "then consume it in front-end" | must |
| R10 | `GET /api/v1/admin/catalog/products` and `GET /api/v1/admin/catalog/products/{slug}` return `purchasePrice`, `vatRate`, and `salePriceTtc` as numbers or explicit `null` on every row. | functional | "then consume it in front-end" | must |
| R11 | The publication endpoint response includes the three new fields as numbers or explicit `null`. | functional | existing publication route contract | should |
| R12 | By default the three fields are admin-only: the public product list, search, and detail responses contain no `purchasePrice`, `vatRate`, or `salePriceTtc` key and no stored value, and `StorefrontProductView::toApi()` is unchanged. | constraint | "then consume it in front-end" (no instruction to expose cost data publicly) | must |
| R13 | Meilisearch `storefront_products` documents contain no purchase-price, VAT-rate, or sale-price-TTC field, and `searchableAttributes`, `filterableAttributes`, and `sortableAttributes` remain unchanged. | constraint | public search must not surface the new cost fields | must |
| R14 | `AdminProductInput` gains optional `purchasePrice?: string \| null`, `vatRate?: string \| number \| null`, and `salePriceTtc?: string \| null`; `AdminProductRow` and `AdminProductDetail` gain required `purchasePrice: number \| null`, `vatRate: number \| null`, and `salePriceTtc: number \| null`. | functional | "then consume it in front-end" | must |
| R15 | The public `Product` and `ProductApi` types remain free of the three new fields. | constraint | "Public frontend types ... must stay free of admin-only fields" | must |
| R16 | The admin product create/edit sheet has optional inputs for the three fields: blank on create, prefilled from the record on edit, money inputs limited to a decimal DT amount (3 decimals max), VAT input limited to its validated precision, and submitted as a trimmed value or `null`. | functional | "then consume it in front-end" | must |
| R17 | The admin product inspector shows "Prix d'achat", "Taux de TVA", and "Prix de vente (TTC)" facts with the stored value, or `—` when unset, in both the Produits and Approbations surfaces. | functional | "then consume it in front-end" | should |
| R18 | Access is unchanged: no new authorization gate is added; anonymous and customer callers keep their existing 401/403 behaviour; the fields follow the existing product read/write rules for `ROLE_ADMIN` and `ROLE_SUPER_ADMIN`. | constraint | "Admin routes: ROLE_ADMIN; Super Admin for publication" | must |
| R19 | The three fields do not participate in slug generation, product identity, uniqueness, publication, approval, stock, cart, ordering, admin `q` search, or public search ranking. | constraint | fields are informational pricing attributes | must |
| R20 | Blank, `null`, absent, and whitespace-only inputs normalize to SQL `NULL` for all three fields. | constraint | "Optional semantics: blank/null/absent/whitespace → NULL" | must |
| R21 | The existing `price` (`price_minor`) field is untouched: its required validation, storage, public exposure, and display behaviour remain unchanged. | constraint | "then consume it in front-end" (add, not replace) | must |
| R22 | The admin list carries the three fields so the inspector can open from a list row without a second fetch. | functional | inspector opens from a list row | should |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The prompt's "Purchase priceVAT rate" line is a missing separator and there is one VAT-rate column, not two. | high |
| 2 | The three fields are optional for every product; requiredness is not stated in the prompt. | med |
| 3 | Purchase price and sale price TTC are money and use the existing minor-unit (millime) convention; VAT rate is a percentage, not money. | med |
| 4 | The VAT rate is stored as a decimal percentage (`vat_rate`, e.g. `19.0000`) rather than basis points or ten-thousandths; its exact column type and precision are not specified by the prompt. | high |
| 5 | The canonical names chosen above (`purchasePrice`, `vatRate`, `salePriceTtc`) are consistent with the project's compound-field precedent; the prompt gives no names. | med |
| 6 | By default the three fields are admin-only; the prompt does not say they must be customer-facing, and purchase price and VAT rate are cost/margin data. | med |
| 7 | An omitted field on `PUT` clears the stored value, matching the controller's existing full-replacement semantics. | med |
| 8 | Both back-office roles may read and edit the three fields wherever they can already read or write the product. | low |
| 9 | Sale price TTC is stored independently and is not automatically recomputed from `price_minor` and `vat_rate`; consistency rules between HT, VAT, and TTC are not specified by the prompt. | high |
| 10 | The importer (`scripts/import-cbm/import.php`) and CSV import are out of scope; existing importer behaviour is unchanged. | med |
| 11 | No Supplier entity or tax configuration table is introduced; VAT rate is a per-product decimal. | med |
| 12 | Money inputs are validated as non-negative decimal DT amounts with up to three decimals, matching the existing `price` validation. | low |
| 13 | The three fields are not added to admin `q` search, filters, sorting, or Meilisearch attributes. | med |
| 14 | Admin responses always include the three keys with `null` when unset, while public responses omit them entirely. | low |
| 15 | No backfill is possible or needed: existing products stay `NULL`. | low |

## Open questions

- [BLOCKING] Does the prompt intend one VAT-rate column or two? The text reads "Purchase priceVAT rate" then "VAT rate" then "Sale price (TTC)". Default if unanswered: one VAT-rate column.
- [BLOCKING] Are these three fields customer-facing or admin-only? In particular, should `salePriceTtc` replace or supplement the existing public `price`? Default if unanswered: admin-only; the public product contract is unchanged.
- [BLOCKING] How is the VAT rate represented and stored (decimal percentage vs. integer basis points vs. ten-thousandths), and with what precision? Default if unanswered: `vat_rate` as `NUMERIC(6,4)` holding a percentage such as `19.0000`.
- [major] Are the three fields required or optional on create/update? Default if unanswered: optional, with absent/blank/`null` normalising to `NULL`.
- [major] Should `sale_price_ttc_minor` be validated against `price_minor` and `vat_rate` (e.g. TTC = HT × (1 + VAT))? Default if unanswered: no cross-field validation; each field is stored independently.
- [major] On `PUT`, should an omitted field clear the stored value or preserve it? Default if unanswered: clear it, matching full-replacement semantics.
- [major] Should the importer map CSV columns 34/37/46 into the new fields? Default if unanswered: no; import behaviour is unchanged.
- [minor] Should the admin products table display any of the three values, or only the inspector and editor? Default if unanswered: inspector and editor only.
- [minor] Is 3 decimals the correct precision for purchase price and sale price TTC? Default if unanswered: yes, matching the existing price convention.
- [minor] Should purchase price be unique or constrained in any way? Default if unanswered: no.
- [minor] Should VAT rate be a global setting rather than a per-product column? Default if unanswered: per-product column, as requested.

## Out of scope

- Supplier management, supplier records, supplier pricing, or supplier stock.
- Tax configuration, tax rules, multi-rate jurisdictions, or tax computation engine.
- Changing the existing `price` (`price_minor`) field, its validation, or its public exposure.
- Recomputing or reconciling HT / VAT / TTC automatically.
- Public search, filters, facets, sorting, or display of purchase price or VAT rate.
- Importer or CSV changes.
- Order, cart, invoice, e-mail, PDF, or export changes.
- Changing the approval or publication workflow beyond the existing admin save behaviour.
- Bulk editing or a new API version.
- Migrating the legacy `catalog_products` table.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add the three nullable columns reversibly and prove rollback | R1, R2, R3 | Existing `storefront_product_view` DDL, migration conventions | New migration adding `purchase_price_minor`, `vat_rate`, `sale_price_ttc_minor`, plus an integration migration test | `E-commerce/migrations/Version20261106000000.php`, `E-commerce/tests/Integration/Catalog/PricingColumnsMigrationTest.php` | none | RED test fails before the migration exists; migrate up/down succeeds on dev and test; columns are nullable with the agreed types; existing rows stay `NULL`; no backfill SQL |
| WP2 | Carry the three fields through admin reads without changing public payloads | R9, R10, R11, R12, R13, R15, R19, R20, R22 | WP1 columns; `StorefrontProductView`, `AdminProductFinder`, `StorefrontProductRepository` | Optional typed properties on the view; `toAdminApi()` adds the three fields; `toApi()` unchanged; `fromRow()` maps columns when present; admin SELECTs include them; public SELECT omits them; unit + integration tests | `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/AdminProductFinder.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php`, `E-commerce/tests/Unit/Catalog/StorefrontProductViewPricingTest.php`, `E-commerce/tests/Integration/Catalog/AdminProductPricingReadTest.php` | WP1 | RED test fails first; `toApi()` key set has no pricing keys; `toAdminApi()` returns numbers or `null`; `fromRow()` works with and without the columns; public SELECT still omits them; Unit and Integration suites green |
| WP3 | Accept, validate, and persist the three fields on the admin write path | R4, R5, R6, R7, R8, R9, R18, R20, R21 | WP1 columns; WP2 admin projection; existing `validate()`, `parameters()`, create/update SQL | Validation and persistence for all three fields, INSERT/UPDATE column lists and parameters, docblocks, functional tests for create, update, blank, null, invalid, and existing `price` behaviour | `E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php`, `E-commerce/tests/Functional/AdminProductPricingWriteTest.php` | WP1, WP2 | RED functional test fails first; POST and PUT persist trimmed/parsed values; absent/null/blank clear to `NULL`; invalid values return 422 with no write; existing `price` validation stays green; Functional suite green |
| WP4 | Model the fields in admin TypeScript types and expose them in the create/edit form | R14, R15, R16, R20 | WP2/WP3 field contract | `AdminProductInput`, `AdminProductRow`, `AdminProductDetail` changes; optional money/VAT inputs in `ProductSheet.tsx`, prefilled on edit, submitted as trimmed value or `null`; public `Product`/`ProductApi` untouched | `Zidane_front_web/src/lib/api/admin.ts`, `Zidane_front_web/src/components/admin/ProductSheet.tsx` | WP2, WP3 | `npm run typecheck` and `npm run lint` pass; blank sends `null`; edit shows stored value; clearing saves `null`; public types have no pricing fields |
| WP5 | Display the three fields in the shared admin inspector | R17 | WP2 read contract; WP4 flow | `InspectableProduct` includes the three fields; `ProductFacts` renders "Prix d'achat", "Taux de TVA", "Prix de vente (TTC)" with value or `—` | `Zidane_front_web/src/components/admin/ProductInspector.tsx` | WP2, WP4 | Inspector opened from Produits and Approbations shows values or `—`; typecheck and lint pass |
| WP6 | Prove the fields never reach public backend surfaces or public search | R12, R13, R18, R19, R21 | WP1-WP3 completed; a product seeded with the three values | Functional sweep over public list/search/detail/wishlist/cart, denied admin access, plus a projection test for document keys and settings | `E-commerce/tests/Functional/StorefrontPricingNonExposureTest.php`, `E-commerce/tests/Projection/Catalog/StorefrontProductSearchIndexerPricingTest.php` | WP1, WP2, WP3 | Every public payload omits the keys and values; admin routes reject anonymous/customer callers; index document has no pricing keys; settings arrays unchanged; Functional and Projection suites green |
| WP7 | Audit the public front end and run the full verification gate | R15, R19, R21 | WP4-WP6 completed | Read-only audit of public model, API types, product route, and site components; recorded gate runs | No production files; read-only audit of `Zidane_front_web/src/data/catalog.ts`, `src/lib/api/types.ts`, `src/routes/produit.$slug.tsx`, `src/components/site/**` | WP4, WP5, WP6 | No pricing field appears in public types or rendered storefront code; existing price display intact; all backend suites green; PHPStan, php-cs-fixer, Deptrac clean; frontend typecheck and lint green |

Dependency graph: `WP1 -> WP2 -> WP3 -> (WP4 || WP6)`, plus `WP2 -> WP5` final-gated after `WP4`; `WP7` after `WP4`, `WP5`, and `WP6`. No two work packages own the same file. WP7 is read-only.
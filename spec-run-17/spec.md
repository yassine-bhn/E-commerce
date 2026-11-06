# Spec: Additional pricing columns (VAT rate, sale price TTC) on catalog products

Version: 2

## Goal

A catalog product keeps its existing `price` / `ref` and gains two additional pricing attributes — a VAT rate and a sale price TTC — persisted by the backend, readable and editable through the existing Admin and Super Admin product surfaces, and consumed in the front end without changing the existing public product contract. (Purchase price and any purchase-side VAT rate are deliberately excluded.)

## Corrected prompt

In the backend, add the following columns to the product: a VAT rate and a sale price (TTC). (The prompt originally listed "Purchase priceVAT rate", "VAT rate" and "Sale price (TTC)"; the user clarified that purchase price and any purchase-side VAT rate are excluded — only the VAT rate and the sale price TTC are added.) Then consume these fields in the front-end.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A reversible migration adds two nullable columns to `storefront_product_view` — `vat_rate NUMERIC(5,2)` and `sale_price_ttc_minor INTEGER` — with no data backfill and no default value; existing rows keep `NULL`. | functional | "in backed add those column to the product" | must |
| R2 | Canonical identifiers are `vatRate` / `vat_rate` and `salePriceTtc` / `sale_price_ttc_minor` (JSON/TypeScript and PostgreSQL respectively); no other spelling is introduced. | constraint | "add those column"; project naming convention | must |
| R3 | The two money fields are stored as integer minor units (millimes) per ECO-001; a float is never used. The VAT rate is stored as a decimal percentage, never as a float. | constraint | "Money: integer minor units (millimes) — never float (ECO-001)" | must |
| R6 | Admin product creation and update accept an optional `vatRate` as a decimal percentage string or number (or `null`/absent/blank), validate its precision, and store `NULL` when unset. | functional | "VAT rate" | must |
| R7 | Admin product creation and update accept an optional `salePriceTtc` as a decimal DT string (or `null`/absent/blank), trims it, parses it with `Money::fromDecimalString`, and stores `NULL` when unset. | functional | "Sale price (TTC)" | must |
| R8 | An invalid value for either of the two fields (malformed decimal, negative money amount for the sale price, more than three decimals for the sale price, unsupported VAT precision, or a non-string/non-number/non-null type) returns HTTP 422 and writes no row / leaves the existing row unchanged. | functional | "add those column"; "Optional semantics: blank/null/absent/whitespace → NULL" | must |
| R9 | Admin create and update responses include `vatRate` and `salePriceTtc` as numbers or explicit `null`, equal to the persisted values. | functional | "then consume it in front-end" | must |
| R10 | `GET /api/v1/admin/catalog/products` and `GET /api/v1/admin/catalog/products/{slug}` return `vatRate` and `salePriceTtc` as numbers or explicit `null` on every row. | functional | "then consume it in front-end" | must |
| R11 | The publication endpoint response includes the two new fields as numbers or explicit `null`. | functional | existing publication route contract | should |
| R12 | By default the two fields are admin-only: the public product list, search, and detail responses contain no `vatRate` or `salePriceTtc` key and no stored value, and `StorefrontProductView::toApi()` is unchanged. | constraint | "then consume it in front-end" (no instruction to expose cost data publicly) | must |
| R13 | Meilisearch `storefront_products` documents contain no VAT-rate or sale-price-TTC field, and `searchableAttributes`, `filterableAttributes`, and `sortableAttributes` remain unchanged. | constraint | public search must not surface the new cost fields | must |
| R14 | `AdminProductInput` gains optional `vatRate?: string \| number \| null` and `salePriceTtc?: string \| null`; `AdminProductRow` and `AdminProductDetail` gain required `vatRate: number \| null` and `salePriceTtc: number \| null`. | functional | "then consume it in front-end" | must |
| R15 | The public `Product` and `ProductApi` types remain free of the three new fields. | constraint | "Public frontend types ... must stay free of admin-only fields" | must |
| R16 | The admin product create/edit sheet has optional inputs for the two fields: blank on create, prefilled from the record on edit, VAT input limited to its validated precision, sale-price input limited to a decimal DT amount (3 decimals max), and submitted as a trimmed value or `null`. | functional | "then consume it in front-end" | must |
| R17 | The admin product inspector shows "Taux de TVA" and "Prix de vente (TTC)" facts with the stored value, or `—` when unset, in both the Produits and Approbations surfaces. | functional | "then consume it in front-end" | should |
| R18 | Access is unchanged: no new authorization gate is added; anonymous and customer callers keep their existing 401/403 behaviour; the fields follow the existing product read/write rules for `ROLE_ADMIN` and `ROLE_SUPER_ADMIN`. | constraint | "Admin routes: ROLE_ADMIN; Super Admin for publication" | must |
| R19 | The three fields do not participate in slug generation, product identity, uniqueness, publication, approval, stock, cart, ordering, admin `q` search, or public search ranking. | constraint | fields are informational pricing attributes | must |
| R20 | Blank, `null`, absent, and whitespace-only inputs normalize to SQL `NULL` for all three fields. | constraint | "Optional semantics: blank/null/absent/whitespace → NULL" | must |
| R21 | The existing `price` (`price_minor`) field is untouched: its required validation, storage, public exposure, and display behaviour remain unchanged. | constraint | "then consume it in front-end" (add, not replace) | must |
| R22 | The admin list carries the three fields so the inspector can open from a list row without a second fetch. | functional | inspector opens from a list row | should |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The user clarified that purchase price and any purchase-side VAT rate are excluded; only a single VAT rate and a sale price TTC are added. | high |
| 2 | "Front-end" consumption means the admin front end (ProductSheet + ProductInspector); no public storefront display or public type changes are required. | med |
| 3 | The three columns are optional for every product; requiredness is not stated in the prompt. | med |
| 4 | Sale price TTC is money and uses the existing minor-unit (millime) convention; VAT rate is a percentage, not money. | med |
| 5 | The VAT rate is stored as a decimal percentage (`vat_rate`, e.g. `19.00`) with 2 decimals rather than basis points or ten-thousandths. | high |
| 6 | By default the two fields are admin-only; the prompt does not say they must be customer-facing, and VAT rate is cost/margin data. | med |
| 7 | An omitted field on `PUT` clears the stored value, matching the controller's existing full-replacement semantics. | med |
| 8 | Both back-office roles may read and edit the three fields wherever they can already read or write the product. | low |
| 9 | Sale price TTC is stored independently and is not automatically recomputed from `price_minor` and `vat_rate`; consistency rules between HT, VAT, and TTC are not specified by the prompt. | high |
| 10 | The importer (`scripts/import-cbm/import.php`) and CSV import are out of scope; existing importer behaviour is unchanged. | med |
| 11 | No Supplier entity or tax configuration table is introduced; VAT rate is a per-product decimal. | med |
| 12 | Money inputs are validated as non-negative decimal DT amounts with up to three decimals, matching the existing `price` validation. | low |
| 13 | The three fields are not added to admin `q` search, filters, sorting, or Meilisearch attributes. | med |
| 14 | Admin responses always include the three keys with `null` when unset, while public responses omit them entirely. | low |
| 15 | No backfill is possible or needed: existing products stay `NULL`. | low |
| 16 | The write stays in the existing `AdminProductController`; no new command, aggregate, or query-handler write is introduced, preserving QRY-001. | low |
| 17 | The existing `CATALOG_PRODUCT_UPDATED` audit entry is unchanged and does not record the old or new pricing values. | low |

## Open questions

- [resolved] VAT column count: one VAT-rate column only (plus sale price TTC); purchase price and purchase-side VAT rate excluded per user clarification.
- [resolved] Visibility: admin-only; the public product contract is unchanged.
- [resolved] VAT rate representation: `vat_rate NUMERIC(5,2)` holding a decimal percentage such as `19.00`.
- [major] Are the three fields required or optional on create/update? Default if unanswered: optional, with absent/blank/`null` normalising to `NULL`.
- [major] Should `sale_price_ttc_minor` be validated against `price_minor` and `vat_rate` (e.g. TTC = HT × (1 + VAT))? Default if unanswered: no cross-field validation; each field is stored independently.
- [major] On `PUT`, should an omitted field clear the stored value or preserve it? Default if unanswered: clear it, matching full-replacement semantics.
- [major] Should the importer map CSV columns 46/37/34 into the new fields? Default if unanswered: no; import behaviour is unchanged.
- [minor] Should the admin products table display any of the three values, or only the inspector and editor? Default if unanswered: inspector and editor only.
- [minor] Is 3 decimals the correct precision for sale price TTC? Default if unanswered: yes, matching the existing price convention.
- [minor] Should sale price TTC be unique or constrained in any way? Default if unanswered: no.

## Out of scope

- Supplier management, supplier records, supplier pricing, or supplier stock.
- Tax configuration, tax rules, multi-rate jurisdictions, or tax computation engine.
- Changing the existing `price` (`price_minor`) field, its validation, or its public exposure.
- Recomputing or reconciling HT / VAT / TTC automatically.
- Public search, filters, facets, sorting, or display of VAT rate.
- Importer or CSV changes.
- Order, cart, invoice, e-mail, PDF, or export changes.
- Changing the approval or publication workflow beyond the existing admin save behaviour.
- Bulk editing or a new API version.
- Migrating the legacy `catalog_products` table.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add the two nullable columns reversibly and prove rollback | R1, R2, R3 | Existing `storefront_product_view` DDL, migration conventions | New migration adding `vat_rate` and `sale_price_ttc_minor`, plus an integration migration test | `E-commerce/migrations/Version20261106000000.php`, `E-commerce/tests/Integration/Catalog/PricingColumnsMigrationTest.php` | none | RED test fails before the migration exists; migrate up/down succeeds on dev and test; columns are nullable with the agreed types; existing rows stay `NULL`; no backfill SQL |
| WP2 | Carry the two fields through admin reads without changing public payloads | R9, R10, R11, R12, R13, R15, R19, R20, R22 | WP1 columns; `StorefrontProductView`, `AdminProductFinder`, `StorefrontProductRepository` | Optional typed properties on the view; `toAdminApi()` adds the two fields; `toApi()` unchanged; `fromRow()` maps columns when present; admin SELECTs include them; public SELECT omits them; unit + integration tests | `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/AdminProductFinder.php`, `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php`, `E-commerce/tests/Unit/Catalog/StorefrontProductViewPricingTest.php`, `E-commerce/tests/Integration/Catalog/AdminProductPricingReadTest.php` | WP1 | RED test fails first; `toApi()` key set has no pricing keys; `toAdminApi()` returns numbers or `null`; `fromRow()` works with and without the columns; public SELECT still omits them; Unit and Integration suites green |
| WP3 | Accept, validate, and persist the two fields on the admin write path | R4, R5, R6, R7, R8, R9, R18, R20, R21 | WP1 columns; WP2 admin projection; existing `validate()`, `parameters()`, create/update SQL | Validation and persistence for both fields, INSERT/UPDATE column lists and parameters, docblocks, functional tests for create, update, blank, null, invalid, and existing `price` behaviour | `E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php`, `E-commerce/tests/Functional/AdminProductPricingWriteTest.php` | WP1, WP2 | RED functional test fails first; POST and PUT persist trimmed/parsed values; absent/null/blank clear to `NULL`; invalid values return 422 with no write; existing `price` validation stays green; Functional suite green |
| WP4 | Model the fields in admin TypeScript types and expose them in the create/edit form | R14, R15, R16, R20 | WP2/WP3 field contract | `AdminProductInput`, `AdminProductRow`, `AdminProductDetail` changes; optional money/VAT inputs in `ProductSheet.tsx`, prefilled on edit, submitted as trimmed value or `null`; public `Product`/`ProductApi` untouched | `Zidane_front_web/src/lib/api/admin.ts`, `Zidane_front_web/src/components/admin/ProductSheet.tsx` | WP2, WP3 | `npm run typecheck` and `npm run lint` pass; blank sends `null`; edit shows stored value; clearing saves `null`; public types have no pricing fields |
| WP5 | Display the two fields in the shared admin inspector | R17 | WP2 read contract; WP4 flow | `InspectableProduct` includes the two fields; `ProductFacts` renders "Taux de TVA" and "Prix de vente (TTC)" with value or `—` | `Zidane_front_web/src/components/admin/ProductInspector.tsx` | WP2, WP4 | Inspector opened from Produits and Approbations shows values or `—`; typecheck and lint pass |
| WP6 | Prove the fields never reach public backend surfaces or public search | R12, R13, R18, R19, R21 | WP1-WP3 completed; a product seeded with the two values | Functional sweep over public list/search/detail/wishlist/cart, denied admin access, plus a projection test for document keys and settings | `E-commerce/tests/Functional/StorefrontPricingNonExposureTest.php`, `E-commerce/tests/Projection/Catalog/StorefrontProductSearchIndexerPricingTest.php` | WP1, WP2, WP3 | Every public payload omits the keys and values; admin routes reject anonymous/customer callers; index document has no pricing keys; settings arrays unchanged; Functional and Projection suites green |
| WP7 | Audit the public front end and run the full verification gate | R15, R19, R21 | WP4-WP6 completed | Read-only audit of public model, API types, product route, and site components; recorded gate runs | No production files; read-only audit of `Zidane_front_web/src/data/catalog.ts`, `src/lib/api/types.ts`, `src/routes/produit.$slug.tsx`, `src/components/site/**` | WP4, WP5, WP6 | No pricing field appears in public types or rendered storefront code; existing price display intact; all backend suites green; PHPStan, php-cs-fixer, Deptrac clean; frontend typecheck and lint green |

Dependency graph: `WP1 -> WP2 -> WP3 -> (WP4 || WP6)`, plus `WP2 -> WP5` final-gated after `WP4`; `WP7` after `WP4`, `WP5`, and `WP6`. No two work packages own the same file. WP7 is read-only.

Whole-spec definition of done: the backend change follows the TDD law (a failing test and its RED output are recorded before each production change), every backend suite listed in `AGENTS.md` is green, the frontend typecheck and lint are green, and the public contract is byte-for-byte free of the three pricing fields in payloads, index documents, public TypeScript types, and rendered storefront pages.

## Decisions (synthesizer only)

| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Column count / "Purchase priceVAT rate" | Three literal columns named "Purchase priceVAT rate", "VAT rate", "Sale price (TTC)" | Three columns: purchase price, VAT rate, sale price TTC (split first line, dedup VAT) | Same as B | Two columns: VAT rate and sale price TTC (purchase price and purchase-side VAT rate excluded) | The user clarified that purchase price and any purchase-side VAT rate are excluded; only the VAT rate and the sale price TTC are added. |
| Field names | Literal prompt names | `vatRate`/`salePriceTtc` | Same as B | `vatRate`/`salePriceTtc` | Matches project compound-field convention (`providerReference`, `categorySlug`, `stockQuantity`) and the prompt's intent. |
| Storage target | `storefront_product_view` | `storefront_product_view` | `storefront_product_view` | `storefront_product_view` | All three agree; it is the table the live admin write path and read models use; the legacy `catalog_products` model has no pricing. |
| Optional semantics | Blank/`null` -> `NULL` | Blank/`null` -> `NULL` | Blank/`null` -> `NULL` | Blank, `null`, absent, whitespace -> SQL `NULL` | Keeps "optional" unambiguous and avoids storing `''` as a second empty value. |
| Update semantics | Omitted clears (full replacement) | Omitted clears (full replacement) | Omitted clears (full replacement) | Omitted clears | The existing `PUT` validates and rewrites the whole product; preserving an omitted field would require distinguishing "absent" from "null" and is a new pattern. |
| Admin visibility | Admin + Super Admin surfaces | Admin + Super Admin | Admin + Super Admin | Both roles, under existing ownership rules | The prompt targets the admin/super-admin back office; no new authorization gate is needed. |
| Public exclusion | Admin surfaces only (types, form, inspector) | `toApi()` unchanged; indexer unchanged | `toApi()` unchanged; indexer unchanged | Exclude from `toApi()`, the Meilisearch document/settings, public types, and storefront views | Two of three explicitly exclude; VAT rate is cost/margin data that must not leak publicly. |
| Admin list carries field | Yes (implied) | Yes | Yes | Include the three fields in the admin list and detail payloads | The inspector opens from a list row; a second fetch would be extra complexity with no benefit. |
| Admin `q` search | Unchanged (implied) | Unchanged | Unchanged | Unchanged | The prompt asks for stored pricing attributes, not a new search dimension; adding it would be scope creep. |
| Admin table row | Inspector/editor only (implied) | Inspector/editor only | Inspector/editor only | Inspector and editor only | Keeps the existing `ref · slug` subtitle and avoids changing the list layout. |
| Migration version | Unspecified | `Version20261106000000` | `Version20261106000000` | `Version20261106000000` | The latest existing migration is `Version20261105000000`; this is the next date-based version. |
| VAT rate precision | Unspecified | `NUMERIC(5,2)` | `NUMERIC(6,4)` | `NUMERIC(5,2)` | User selected the default: a decimal percentage such as `19.00`. |
| Customer-facing scope | Admin surfaces | Admin-only | Admin-only (blocking) | Admin-only | User selected the default: admin-only; the public product contract is unchanged. |
| Derived TTC | Not specified | No derivation | No derivation | No derivation; each field stored independently | The prompt gives no HT/VAT/TTC reconciliation rule; deriving would invent business logic. |
| Tests / WP shape | 2 WPs | 7 WPs | 7 WPs | 7 file-disjoint WPs with explicit dependencies | Converges across B and C; lets schema, reads, writes, frontend form, inspector, non-exposure tests, and final audit run in dependency order. |

## Changelog (synthesizer and orchestrator only)

- v2: folded user answers — one VAT-rate column only, admin-only visibility, `NUMERIC(5,2)` VAT rate; purchase price and purchase-side VAT rate excluded per user clarification.
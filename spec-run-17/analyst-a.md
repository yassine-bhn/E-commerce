# Spec: Add columns to product and consume in frontend

Version: 1

## Goal

Add the columns listed in the prompt to the product in the backend, then consume them in the front-end.

## Corrected prompt

In the backend, add the following columns to the product: "Purchase priceVAT rate", "VAT rate", "Sale price (TTC)". Then consume them in the front-end.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The backend product gains a column named "Purchase priceVAT rate". | functional | "add those column to the product" / "Purchase priceVAT rate" | must |
| R2 | The backend product gains a column named "VAT rate". | functional | "add those column to the product" / "VAT rate" | must |
| R3 | The backend product gains a column named "Sale price (TTC)". | functional | "add those column to the product" / "Sale price (TTC)" | must |
| R4 | The three new columns are consumed in the front-end. | functional | "then consume it in front-end" | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Purchase priceVAT rate" is a single column name exactly as written in the prompt, not two separate columns. | high |
| 2 | The product entity/read model targeted is the existing `storefront_product_view` and its admin/public projections, per shared context. | med |
| 3 | Column names are converted to the project's snake_case (PostgreSQL) / camelCase (JSON/TS) convention, per shared context. | med |
| 4 | Money-valued columns use integer minor units (millimes) and VAT rate is stored as a numeric percentage, per shared context conventions. | med |
| 5 | The new columns are nullable/optional and existing rows keep NULL, per shared context convention "no data backfill in migrations". | low |
| 6 | "consume it in front-end" means the admin product surfaces (types, form, inspector) read and display the new fields, per shared context. | med |

## Open questions

- [BLOCKING] The prompt lists "VAT rate" twice and writes "Purchase priceVAT rate" without a separator: is the intended set three columns or four, and if "Purchase priceVAT rate" is two columns, what is the second? Default if unanswered: three columns exactly as the three listed lines.
- [minor] Which front-end surfaces must display the new columns (admin only, or also public)? Default if unanswered: admin surfaces only.

## Out of scope

- Anything not named in the prompt (importer changes, tax engine, invoicing, public price display changes, validation ranges).

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Add the three columns to the backend product model | R1, R2, R3 | Existing `storefront_product_view`, migration conventions | Backend migration + read model + admin controller changes | `E-commerce/migrations/**`, `E-commerce/src/Catalog/Infrastructure/ReadModel/**`, `E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php` | none | The three columns exist on the product and are readable/writable via backend |
| WP2 | Consume the three columns in the front-end | R4 | WP1 backend contract | Frontend type/form/inspector changes | `Zidane_front_web/src/lib/api/admin.ts`, `Zidane_front_web/src/components/admin/ProductSheet.tsx`, `Zidane_front_web/src/components/admin/ProductInspector.tsx` | WP1 | Frontend compiles and displays the new fields |
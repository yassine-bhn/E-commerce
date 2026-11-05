# Shared context for spec-run-17

## Repo
- Backend root: /home/yassine/Desktop/freelance/Zidaine-groupe/E-commerce (Symfony 8.1, PHP 8.4, PostgreSQL 16 in Docker).
- Frontend root: /home/yassine/Desktop/freelance/Zidaine-groupe/Zidane_front_web (React + TypeScript).
- Docker services running: app, database, meilisearch, outbox, worker.

## Existing supplier-reference feature (spec-run-16, just completed)
- `storefront_product_view` already has `supplier_reference VARCHAR(64) NULL`.
- Public projection: `StorefrontProductView` with `toApi()` (public) and `toAdminApi()` (admin). Public SELECT excludes supplier_reference; admin SELECT includes it.
- Admin write path: `AdminProductController` (create/update/validate/parameters) persists columns.
- Admin frontend: `AdminProductInput`, `AdminProductRow`, `AdminProductDetail` in `src/lib/api/admin.ts`; form in `ProductSheet.tsx`; facts in `ProductInspector.tsx`.
- Public frontend types `Product`/`ProductApi` are supplier-free; product page shows `Réf. {p.ref}`.

## CSV source
- /home/yassine/Desktop/freelance/Zidaine-groupe/nobili-scrapping/zidaine-groupe-product.csv (55 columns, no header).
- Column 46 (0.007…): purchase/cost price (candidate "Purchase price").
- Column 37: VAT rate (19.0000 or 7.0000) (candidate "VAT rate").
- Column 34: price TTC (candidate "Sale price (TTC)"); column 33 is price HT.
- Column 32: tax mode HT/TTC.
- Existing importer: scripts/import-cbm/import.php maps a subset (name, ref, supplier_reference, price_minor HT, stock_quantity, ean, category, subcategory) and sets brand='CBM', description/material/dims/images empty, old_price_minor NULL, weight NULL.

## Key backend files
- E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php
- E-commerce/src/Catalog/Infrastructure/ReadModel/AdminProductFinder.php
- E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php
- E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php
- E-commerce/src/Catalog/Infrastructure/Search/StorefrontProductSearchIndexer.php
- E-commerce/migrations/ (latest: Version20261105000000)

## Key frontend files
- Zidane_front_web/src/lib/api/admin.ts
- Zidane_front_web/src/lib/api/types.ts (public ProductApi — must stay free of admin-only fields)
- Zidane_front_web/src/components/admin/ProductSheet.tsx
- Zidane_front_web/src/components/admin/ProductInspector.tsx
- Zidane_front_web/src/routes/produit.$slug.tsx

## Conventions
- Identifiers: camelCase in JSON/TS, snake_case in PostgreSQL.
- Money: integer minor units (millimes) — never float (ECO-001).
- Optional semantics: blank/null/absent/whitespace → NULL.
- Admin routes: ROLE_ADMIN; Super Admin for publication. Anonymous/customer denied.
- TDD mandatory; migrations reversible; no data backfill in migrations.
- Docker-only execution: docker compose exec app …

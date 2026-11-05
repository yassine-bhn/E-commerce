# Repository context (shared facts for all analysts)

This is a monorepo with two working trees:

- `E-commerce/` — Symfony 8.1 modular monolith (CQRS, PostgreSQL 16, DBAL read models). Harness rules live in `E-commerce/AGENTS.md`, `.agents/rules/**`, `.agents/skills/spec-driven-agents.md`.
- `Zidane_front_web/` — Next.js 15 / React 19 front end. Public storefront + a back-office dashboard.

Both trees currently contain uncommitted user work. Do not propose reverting or rewriting unrelated changes.

## Existing product reference (the one that already exists)

The live catalog product record is the denormalized table `storefront_product_view` (despite the `_view` suffix, the admin controller currently writes it directly):

- Table created in `E-commerce/migrations/Version20261017000001.php:19-44` with `ref VARCHAR(64) NOT NULL`.
- API field `ref` (camelCase, max 64 chars) is returned by `StorefrontProductView::toApi()` and by the admin projection.
- `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php`
  - constructor property `public string $ref`
  - `toApi()` (public storefront payload) includes `'ref' => $this->ref`
  - `toAdminApi()` = `toApi()` + `stockQuantity`
  - `fromRow()` requires `$row['ref']`
- `E-commerce/src/Catalog/Infrastructure/ReadModel/AdminProductFinder.php` (`SELECT ... ref ...`, search `q` matches name/slug/ref/brand, returns `AdminProductListItem` = product `toAdminApi()` + approval block).
- `E-commerce/src/Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php` (`SELECT ... ref ...`; used by public listing/search/show, and by `adminBySlug()`).
- `E-commerce/src/Catalog/Infrastructure/Search/StorefrontProductSearchIndexer.php` (Meilisearch document includes `ref`; searchable attributes are `name, brand, ref, subcategory, category_slug, material`). Supplier data must never be indexed or searchable publicly.
- `E-commerce/src/Catalog/Infrastructure/Http/AdminProductController.php`
  - `create()` INSERT list at lines 88-100 (`ref` included, `published = false`)
  - `update()` UPDATE at lines 161-170
  - `validate()` at lines 305-413: `ref` is required, `mb_strlen($ref) > 64` rejected; returns a typed array
  - `parameters()` at ~line 490 maps validated values to SQL parameters
  - `adminPayload()` at ~line 236 calls `StorefrontProductView::toAdminApi()`
- Other public surfaces that reuse `StorefrontProductView::fromRow()`: `E-commerce/src/Identity/Infrastructure/ReadModel/WishlistFinder.php:60`, `E-commerce/src/Cart/Infrastructure/ReadModel/StorefrontCartFinder.php:92`, `StorefrontProductRepository` `bySlug()/adminBySlug()/viaSql()`.

The legacy `catalog_products` table / `App\Catalog\Domain\Model\Product` aggregate is a minimal demo model that has no `ref` at all and is not the admin product write path. The real product content, including `ref`, lives in `storefront_product_view`.

## Front end

Public storefront types / surfaces:

- `Zidane_front_web/src/data/catalog.ts` — `Product` public model (includes `ref`).
- `Zidane_front_web/src/lib/api/types.ts` — `ProductApi extends Product` (public API shape).
- `Zidane_front_web/src/routes/produit.$slug.tsx` — product page shows `Réf. {p.ref}` and a facts table with `["Référence", p.ref]`.
- `Zidane_front_web/src/components/site/*` — listing, cards, header search use public `ref`.

Admin surfaces:

- `Zidane_front_web/src/lib/api/admin.ts`
  - `AdminProductInput` (create/update payload) currently has `ref: string`.
  - `AdminProductRow extends ProductApi` = admin list row + `stockQuantity` + `approval`.
  - `AdminProductDetail extends ProductApi` = admin detail + `stockQuantity` + `approval`.
  - `createProduct()` / `updateProduct()` return `Promise<ProductApi>`.
- `Zidane_front_web/src/components/admin/ProductSheet.tsx` — create/edit form shared by Produits and Approbations; `FormState` has `ref`; `emptyForm()` / `formFromProduct()`; required-field guard; builds `AdminProductInput`.
- `Zidane_front_web/src/components/admin/ProductInspector.tsx` — admin slide-in facts; `InspectableProduct`; `ProductFacts` renders `FactRow label="Référence" value={product.ref}`.
- `Zidane_front_web/src/components/admin/ProductsPanel.tsx` — admin list; row subtitle `{product.ref} · {product.slug}`; opens `ProductInspector` / `ProductSheet`.
- `Zidane_front_web/src/components/admin/ApprovalsPanel.tsx` — approval queue, fetches `getAdminProduct()` and reuses `ProductFacts` / `ProductSheet`.

## Constraints that shape the solution

- Project naming: API fields camelCase, DB columns snake_case; the existing product reference is abbreviated `ref`.
- Existing `ref` is `VARCHAR(64)`, optional new field should be consistent.
- Admin product routes are protected by `#[IsGranted('ROLE_ADMIN')]`; super-admin-only actions are separate. Both Admin and Super Admin use the same dashboard.
- Public storefront must never receive the supplier reference: not in `/api/v1/storefront/products`, `/products/search`, `/products/{slug}`, wishlist, cart, reviews, nor Meilisearch documents/settings.
- Read-model rule QRY-001: query handlers never write. This change's writes stay in `AdminProductController` (the existing write path) or a proper command; do not add writes to query handlers.
- Migration rule MIG-001: reversible `up()`/`down()`. MIG-002: schema changes to `*_view` are fine; data backfills must be done by replay, not a data migration.
- TDD is mandatory in `E-commerce`: a failing test must exist before production code. PHPUnit suites: Unit, Integration, Projection, Functional, Contract, Architecture. Frontend has `npm run typecheck` and `npm run lint` (no test runner configured).
- Definition of Done includes phpstan, php-cs-fixer, deptrac, and all suites green.

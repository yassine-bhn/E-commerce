# Storefront Brand Logos

## Goal

Expose approved brand logo references through the storefront reference API and render them in the homepage partner-brand cards, with brand-name fallback when no logo is attached.

## Current behavior

The backend stores a nullable `/uploads/brands/...` reference in `storefront_brand.image_path`, but `/api/v1/storefront/reference` currently returns only approved brand names. The frontend homepage therefore cannot render logos and displays every brand as text.

## Design

### Backend response

`StorefrontReferenceRepository::brands()` will select `name` and `image_path` for approved brands and return:

```json
[
  { "name": "CBM", "image": "/uploads/brands/example.webp" },
  { "name": "SOPAL", "image": null }
]
```

`GetStorefrontReferenceHandler` will continue exposing this list under `brands`. The database and upload storage model are unchanged: PostgreSQL stores the relative upload path, while the image file remains under `public/uploads/brands`.

### Frontend data flow

The `ReferenceData.brands` type changes from `string[]` to:

```ts
interface StorefrontBrand {
  name: string;
  image: string | null;
}
```

All consumers of `/reference` will use `brand.name` for search/filter behavior. The homepage and About page will adapt their display-only brand lists without changing navigation semantics.

### Homepage rendering

Each partner-brand card will render the logo through the existing `assetUrl()` helper when `brand.image` is non-null. The card will retain the brand name as accessible alternative text and as the visible fallback when no logo exists. No other storefront logo surfaces are included.

## Error handling and compatibility

The existing reference request error behavior remains unchanged. A missing image is represented by `null`, never by an invalid URL. Existing product/category/brand search URLs continue to use the brand name.

## Validation

- Add or update focused backend coverage for the reference repository response shape.
- Run the relevant backend test/static checks.
- Run frontend typecheck, targeted lint, and production build.
- Verify `/` in the browser with one logo-bearing brand and one imageless brand.

## Scope exclusions

- No change to image file storage.
- No migration change.
- No storefront brand detail page.
- No new test runner.

# Spec: Brand image CRUD (admin catalog API)
Version: 1

## Goal
An administrator can attach, view, replace and remove one image for a brand through the existing admin catalog API, following the repo's established upload, approval-workflow, read-model and audit conventions, so a brand can be visually identified in the dashboard.

## Corrected prompt
"Implement the feature of CRUD image of brands": administrators can create (attach), read (view), update (replace) and delete (remove) the image of a brand, implemented on the existing catalog admin infrastructure.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | An authenticated admin (`ROLE_ADMIN`) can set an image on a brand that has none; on success the brand row persists an image reference and the operation returns the updated brand representation (name, productCount, image). | functional | "crud image of brands" — the Create of CRUD | must |
| R2 | The admin brand list (`GET /api/v1/admin/catalog/brands`) returns each brand's image reference (`null` when the brand has no image) alongside `name` and `productCount`, through the `ListAdminBrands`/`AdminBrandFinder` read model. | functional | "crud image of brands" — the Read of CRUD | must |
| R3 | An authenticated admin can replace a brand's existing image with a different valid image reference; after the call the stored reference equals the new one and the old reference is no longer returned. | functional | "crud image of brands" — the Update of CRUD | must |
| R4 | An authenticated admin can remove a brand's image; afterwards the brand still exists, its image reads back as `null`, and setting a new image again works (CRUD cycle closes). | functional | "crud image of brands" — the Delete of CRUD | must |
| R5 | An image write on a brand is refused (4xx, nothing persisted) when the payload is missing/blank, not a string, or not a valid image reference; refusal happens before any SQL statement runs (edge-validated payload, BannerPayload-style). | functional | "implement the feature ... of brands" applied to the repo's payload-validation pattern (grounding: `BannerPayload`, `AdminProductController` image rule; see A3) | must |
| R6 | Brand image writes go through the same access policy as other brand writes (`SubmissionAccessPolicy.authorize` on `CatalogSubmissionKind::Brand`): a Super Admin may act on any brand, a regular Admin only on their own `PENDING_APPROVAL`/`REJECTED` submission; a refused write persists nothing and maps to HTTP 403/404 per `domainFailure`. | constraint | "crud image of brands" exercised on the approval-governed brand aggregate (grounding: `AdminBrandController`, `SubmissionAccessPolicy`; see A4) | must |
| R7 | Every successful brand image write records an audit entry via `CatalogAuditPort` (new `CATALOG_BRAND_IMAGE_*` events, target `brand`, actor id), consistent with `CATALOG_BRAND_RENAMED`/`CATALOG_BRAND_DELETED`. | constraint | "crud image of brands" on the audited brand aggregate (grounding: `AdminBrandController` audit calls; see A4) | must |
| R8 | Uploaded brand images flow through the existing upload endpoint `POST /api/v1/admin/catalog/uploads` with a new allowlisted folder `brands` (`/uploads/brands/...`), keeping the existing 2 MB limit and JPEG/PNG/WebP content-sniffed acceptance unchanged for the other folders. | constraint | "implement the feature ... image ... of brands" using the existing upload infrastructure (grounding: `AdminUploadController` `FOLDERS` allowlist; see A2/A3) | must |
| R9 | The image reference is stored on the brand itself: a new nullable column on `storefront_brand` (e.g. `image_path VARCHAR(255) DEFAULT NULL`) added by a new Doctrine migration with a working `down()`, leaving `name` as primary key and the approval columns untouched. | constraint | "crud image of brands" persisted per brand (grounding: `migrations/Version20261017000002.php`, `Version20261101000000.php`; see A1/A5) | must |
| R10 | Deleting a brand (`DELETE /{name}`, Super Admin) succeeds even when the brand has an image; the image reference disappears with the row and no leftover submission/state blocks re-creating a brand with the same name. | functional | "crud image of brands" — Delete semantics against the existing brand delete (grounding: `AdminBrandController::delete`) | must |
| R11 | Renaming a brand keeps its image attached: after `PUT /{name}` the brand under the new name returns the same image reference it had before the rename. | functional | "crud image of brands" — image ownership follows the brand identity through the existing rename (grounding: `AdminBrandController::rename`) | should |
| R12 | The three operations are exposed as dedicated routes under `/api/v1/admin/catalog/brands/{name}` (set/replace image, remove image), keeping the existing create/rename/delete route contracts unchanged. | quality | "crud image of brands" — image CRUD as its own feature surface (see A9) | should |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Image" means exactly one image per brand (a logo/visual); not a gallery like `storefront_product_view.additional_images` nor a multi-slide set like the banner. | med |
| 2 | The two-step upload flow is reused: the dashboard uploads the file first via `AdminUploadController`, then sends the returned `/uploads/...` path to the brand image endpoints; brand image endpoints never accept multipart themselves. | low |
| 3 | A brand image reference must be a dashboard upload path under `/uploads/brands/` (banner-style strictness) rather than the looser products rule (`/path` or any `http(s)` URL, ≤255 chars). | low |
| 4 | Image edits are brand writes: they inherit the approval access policy and the audit trail; setting an image does NOT change `approval_status` (the submission lifecycle stays as-is, unlike create/rename which touch submission state). | med |
| 5 | Persistence is a single nullable `image_path` column on `storefront_brand` in a new migration; no separate brand-image table, no search-index re-sync needed (brand image is not part of product documents). | low |
| 6 | This repo delivers the API only: the dashboard UI lives outside this repository (`assets/controllers` has no admin UI), and the referenced `ui-features/12-brand-management.md` does not exist in the repo, so no UI changes or UI spec compliance are required here. | med |
| 7 | The storefront does not consume brand images yet; `StorefrontReferenceRepository` / storefront brand filter stay unchanged. | low |
| 8 | Removing an image or deleting a brand only clears the reference; the file under `public/uploads/brands/` is left on disk (same tolerance as products/banner), no file GC. | low |
| 9 | Route shape: `PUT /api/v1/admin/catalog/brands/{name}/image` (set or replace) and `DELETE /api/v1/admin/catalog/brands/{name}/image` (remove); image is not folded into the create/rename request bodies. | low |
| 10 | Response shape of image operations mirrors the existing list item (`name`, `productCount`, `image`) plus the standard error mapping already in `AdminBrandController::domainFailure`. | low |

## Open questions
- [minor] One image per brand or several? Default if unanswered: exactly one (A1).
- [minor] Should a brand be created with its image in one call (`POST` with `image`), or is attach-after-create enough? Default if unanswered: attach after create via the dedicated image route (A9).
- [minor] Accept only dashboard upload paths, or also arbitrary site paths / http(s) URLs like products? Default if unanswered: dashboard upload paths under `/uploads/brands/` only (A3).
- [minor] Should the storefront brand filter/reference data expose the image? Default if unanswered: no, admin-only for now (A7).

## Out of scope
- Dashboard/frontend UI changes (no admin UI exists in this repository).
- Image processing: resizing, cropping, thumbnails, format conversion.
- Storefront exposure of brand images (filter chips, brand pages).
- Deleting or garbage-collecting image files on disk when a reference is cleared.
- Changing the existing upload constraints (2 MB, JPEG/PNG/WebP, other folders).
- Multiple images per brand / galleries / ordering.
- Bulk or CLI import of brand images (e.g. into `BackfillCatalogApprovalCommand`).
- Any change to the approval lifecycle states or the submission store schema.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Persist and expose image writes on the brand aggregate | R1, R3, R4, R6, R7, R9, R10, R11, R12 | `AdminBrandController.php`, `CatalogSubmissionKind.php`, `SubmissionAccessPolicy.php`, migration style of `Version20261101000000.php`, `BannerPayload.php` as pattern | New migration adding nullable `image_path` to `storefront_brand` (with `down()`); new routes `PUT`/`DELETE /api/v1/admin/catalog/brands/{name}/image` in `AdminBrandController` with access policy, audit events, payload validation wiring and error mapping | `migrations/Version<new>.php`, `src/Catalog/Infrastructure/Http/AdminBrandController.php`, `src/Catalog/Domain/Model/BrandImageInvalidException.php` (new, if the chosen error mapping needs it) | WP3 (consumes its folder + validator contract) | Migration migrates up/down cleanly; setting, replacing and removing an image on a brand persists and returns the expected reference; policy-refused and invalid-payload calls persist nothing |
| WP2 | Return the image in the admin brand list | R2 | `AdminBrandFinder.php`, `ListAdminBrands.php` | `AdminBrandFinder` selects the image column and returns `image` (string or null) in each list item | `src/Catalog/Infrastructure/ReadModel/AdminBrandFinder.php` (and `src/Catalog/Application/Query/ListAdminBrands.php` only if a doc/constant change is needed) | WP1 (column must exist for runtime; code can be written in parallel) | `GET /api/v1/admin/catalog/brands` returns `image: null` for imageless brands and the stored path for others, with filters/sorts/pagination unchanged |
| WP3 | Enable brand image uploads | R5, R8 | `AdminUploadController.php`, `BannerPayload.php` as validation pattern | `brands` added to the upload folders allowlist; a small brand-image payload/validator service (new file) that accepts only `/uploads/brands/...` paths and rejects anything else | `src/Catalog/Infrastructure/Http/AdminUploadController.php`, `src/Catalog/Application/Service/BrandImagePayload.php` (new) | none | Uploading to `folder=brands` returns a `/uploads/brands/...` path; `products`/`banner` behaviour unchanged; validator accepts exactly one valid path shape and rejects blank/non-string/foreign paths |
| WP4 | Prove the feature end to end | R1, R2, R3, R4, R6, R7, R10, R11 | `tests/Functional/AdminBrandApprovalWorkflowTest.php` conventions, `AdminUploadController` test style if present | New functional test class covering the full CRUD cycle plus policy, audit and edge cases | `tests/Functional/AdminBrandImageTest.php` (new) | WP1, WP2, WP3 | Test suite green: set→read→replace→remove cycle passes; regular admin blocked on foreign/approved brands (403), missing brand 404, invalid path 422 with nothing persisted; audit rows written; rename keeps image; delete with image succeeds |

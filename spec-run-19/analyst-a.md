# Spec: Brand image CRUD
Version: 1

## Goal
A user of the existing brand management API can create, read, update and delete an image on a brand, alongside the existing brand CRUD, without changing how brands themselves behave.

## Corrected prompt
"implement the feature of crud image of brands" — restated: add image CRUD (create, read, update, delete) for brands. Nothing else is requested.

## Requirements
The prompt is a single sentence, so every requirement cites that sentence, isolated to the CRUD letter it covers.

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | It is possible to create an image for a brand: an operation exists that attaches an image to a brand record that has none. | functional | "implement the feature of crud image of brands" — the "c" of "crud" | must |
| R2 | It is possible to read the image of a brand: reading a brand returns the stored image reference (no image = an explicit empty/null value, not an error). | functional | "implement the feature of crud image of brands" — the "r" of "crud" | must |
| R3 | It is possible to update the image of a brand: an operation exists that replaces an existing brand image with another one. | functional | "implement the feature of crud image of brands" — the "u" of "crud" | must |
| R4 | It is possible to delete the image of a brand: an operation exists that removes the image reference while the brand itself continues to exist. | functional | "implement the feature of crud image of brands" — the "d" of "crud" | must |
| R5 | The four image operations act on the brand entity — the same "brand" object the existing brand CRUD manages — not on a new standalone entity. | constraint | "…image of brands" | must |
| R6 | The feature is complete CRUD: all four operations (create, read, update, delete) are present and reachable; none may be a stub. | constraint | "crud" | must |

## Assumptions
Everything below was filled in from the repository because the prompt is silent on it.

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | One image per brand (the prompt says "image", singular). Persisted as a nullable `image_path` reference column on `storefront_brand`, added by a new migration — mirroring `storefront_product_view.image_path`; the banner's child-table pattern (`storefront_banner_image`) is the rejected alternative. | med |
| 2 | The image CRUD lives on the existing admin brand management surface (`/api/v1/admin/catalog/brands`, `AdminBrandController`) with its existing auth (`ROLE_ADMIN`; the destructive/delete rule follows the existing brand conventions). | low |
| 3 | Image binaries are uploaded through the existing dashboard upload endpoint (`AdminUploadController`, 2 MB, content-sniffed JPEG/PNG/WebP); the brand stores only the returned path. This requires adding a `brands` folder to that controller's allowlist (`FOLDERS = ['products', 'banner']` today). | med |
| 4 | Upload limits carried over unchanged: 2 MB max, JPEG/PNG/WebP detected from content, never from the client-supplied name or MIME type. | low |
| 5 | Image writes follow the existing brand write conventions: `SubmissionAccessPolicy` authorization for writes to existing brands (as the rename endpoint does) and a `CatalogAuditPort` audit record per image action (`CatalogSubmissionKind::Brand`, audit target `brand`). | med |
| 6 | A stored brand image path must reference a dashboard upload under `/uploads/brands/…` — the same "dashboard upload references only" rule the banner enforces. Foreign/relative/absolute URLs are rejected with 422. | low |
| 7 | Reading = the admin brand list (`AdminBrandFinder` SELECT gains the image column; `ListAdminBrands` pagination/q/sort unchanged) plus the image path echoed in the responses of the image write operations. | low |
| 8 | Renaming a brand keeps its image: the image lives on the `storefront_brand` row, which the existing rename moves with the name. | low |
| 9 | Deleting a brand removes the image reference with the row (existing delete semantics untouched); the uploaded file on disk is not garbage-collected. | low |
| 10 | Storefront exposure of brand images is not part of this feature — the prompt names no storefront surface, and existing brand reads for the storefront filter are untouched. | low |
| 11 | The grounding doc `ui-features/12-brand-management.md` does not exist in the repository (verified by search); the spec is grounded only in the listed code files. | low |

## Open questions
- [minor] One image per brand, or several images per brand? Default if unanswered: one image per brand (Assumption 1).
- [minor] Should image writes create/transition `catalog_submission` approval states, or only be authorized by `SubmissionAccessPolicy` and audited? Default if unanswered: authorization + audit only, no new submission-state transitions (Assumption 5).
- [minor] Should the storefront brand filter/brand display also return the image? Default if unanswered: no — admin API only (Assumption 10).
- [minor] Should deleting a brand image also delete the file under `public/uploads/brands/`? Default if unanswered: no file cleanup (Assumption 9), consistent with the rest of the codebase.
- [BLOCKING] none.

## Out of scope
- Storefront rendering or exposure of brand images (storefront brand filter payloads unchanged).
- Image processing: resizing, cropping, thumbnails, format conversion, CDN.
- Multiple images per brand / brand image galleries.
- Orphan-file cleanup of removed or replaced uploads on disk.
- Any change to product or banner image behavior, or to the upload size/type limits.
- Changes to brand name CRUD semantics (create/rename/delete rules stay as-is; rename keeps the image).

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Persist the brand image reference | R1, R2, R3, R4 (storage basis) | `migrations/Version20261017000002.php` (brand table, name PK), `migrations/Version20261101000000.php` (approval columns) as schema precedent | New migration adding a nullable `image_path` column to `storefront_brand`, with a working `down()` | `migrations/Version<new timestamp>.php` (new file only) | none | `doctrine:migrations:migrate` and `migrate:down` succeed; existing brand rows read back with NULL image; no other table touched |
| WP2 | Accept brand image uploads | R1, R3 (binary upload path) | `src/Catalog/Infrastructure/Http/AdminUploadController.php` | `brands` added to the upload folder allowlist so `folder=brands` stores under `public/uploads/brands/` and returns a `/uploads/brands/…` path | `src/Catalog/Infrastructure/Http/AdminUploadController.php` | none | Uploading with `folder=brands` returns 201 with a `/uploads/brands/` path; `products` and `banner` behave exactly as before; unknown folders still 422 |
| WP3 | Brand image write operations (create/update/delete) | R1, R3, R4, R5 | `src/Catalog/Infrastructure/Http/AdminBrandController.php` (current CRUD, transaction + audit pattern), `src/Catalog/Application/Service/SubmissionAccessPolicy.php`, `src/Catalog/Domain/Model/CatalogSubmissionKind.php`, `src/Catalog/Application/Port/BannerImageStore.php` + `Banner*Handler.php` (write-pattern reference), Assumptions 2, 3, 5, 6, 8 | Endpoints/flows to attach, replace and remove a brand image (create-with-image and/or set/replace/remove operations), authorized and audited per Assumptions 5–6, with the image path echoed in each response | `src/Catalog/Infrastructure/Http/AdminBrandController.php`; optionally a new `src/Catalog/Domain/Model/*.php` exception file | WP1, WP2 (integration only — code can be written in parallel) | Attaching an image to a brand persists it; replacing and removing work; removing leaves the brand intact; non-authorized writers get the existing 403/404 semantics; every action writes an audit record |
| WP4 | Brand image read | R2, R5, R7 (Assumption 7) | `src/Catalog/Infrastructure/ReadModel/AdminBrandFinder.php`, `src/Catalog/Application/Query/ListAdminBrands.php` | The admin brand list rows and page payload include the brand's image path (null when absent) | `src/Catalog/Infrastructure/ReadModel/AdminBrandFinder.php`, `src/Catalog/Application/Query/ListAdminBrands.php` | WP1 | `GET /api/v1/admin/catalog/brands` returns `imagePath` per brand; pagination, `q` filter and sorts unchanged |
| WP5 | Functional coverage | R6 | `tests/Functional/AdminBrandApprovalWorkflowTest.php` (test conventions), WPs 1–4 | Functional tests covering the full CRUD cycle: create → read → update → delete image, plus rejection cases (bad path reference, unauthorized writer, unknown brand) | New file `tests/Functional/AdminBrandImage*Test.php` | WP3, WP4 | The new suite passes and demonstrates every one of the four operations plus at least one rejection per write operation |

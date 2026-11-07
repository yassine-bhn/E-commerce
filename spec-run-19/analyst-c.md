# Spec: Brand image CRUD (admin catalog)
Version: 1

## Goal
An administrator can attach, replace, remove and view one image (logo) per brand through the existing admin brand API, with the image stored as a reference to a dashboard-uploaded file, without breaking the brand approval workflow, rename, delete or list features.

## Corrected prompt
"implement the feature of crud image of brands" — i.e. add create, read, update and delete support for brand images to the existing brand management feature (admin brand CRUD at `src/Catalog/Infrastructure/Http/AdminBrandController.php`), grounded in the repository's existing image patterns (upload endpoint, banner image writes, product `image_path` column). The prompt says nothing else; every concrete choice below is either grounded in the cited repository files or listed as an assumption.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A new Doctrine migration adds a nullable `image_path VARCHAR(512)` column to `storefront_brand`; its `down()` drops the column. Existing brands (seeded Duravit/Geberit/Sanibel/Sopal and any legacy rows) keep working with `image_path = NULL`. | functional | Prompt: "crud image of brands" (persistence implied) + Repo: migrations/Version20261017000002.php (`storefront_brand` name PK), migrations/Version20261020000001.php (`image_path VARCHAR(512)` convention) (A1, A2, A12) | must |
| R2 | The upload endpoint accepts a new `brands` folder: `POST /api/v1/admin/catalog/uploads` with `folder=brands` stores the file under `public/uploads/brands/` and returns `{path, url}`; the same 2 MB limit and content-sniffed JPEG/PNG/WebP rules apply; every other folder value is still refused. | functional | Prompt: "crud image of brands" (image upload implied) + Repo: src/Catalog/Infrastructure/Http/AdminUploadController.php (folders allowlist `products, banner`) (A3) | must |
| R3 | An admin can attach an image to a brand that has none by sending an upload path to the brand image endpoint; the path is persisted on the brand row and the response returns the brand name and its `imagePath`. | functional | Prompt: "crud image of brands" (the C of image CRUD) (A4, A5, A13) | must |
| R4 | An admin can replace an existing brand image with a new upload path; the stored path is overwritten and the old file is left untouched on disk. | functional | Prompt: "crud image of brands" (the U of image CRUD) + Repo: src/Catalog/Domain/Model/BannerSet.php (references-only, removal never touches files) (A4, A7) | must |
| R5 | An admin can remove a brand image so that `image_path` becomes NULL again; removing an image a brand does not have still succeeds (idempotent) and the response confirms. | functional | Prompt: "crud image of brands" (the D of image CRUD) (A4, A14) | must |
| R6 | `GET /api/v1/admin/catalog/brands` returns each brand's image path as a nullable `imagePath` field alongside `name` and `productCount`; filtering, sorting, pagination behaviour of the list is unchanged. | functional | Prompt: "crud image of brands" (the R of image CRUD) + Repo: src/Catalog/Infrastructure/ReadModel/AdminBrandFinder.php, src/Catalog/Application/Query/ListAdminBrands.php (A6) | must |
| R7 | Only upload references are accepted as brand images: a request path that is not inside `/uploads/` with a jpg/jpeg/png/webp extension is refused with HTTP 422 and nothing is persisted; file existence on disk is not checked (same rule as banner slides). | constraint | Repo: src/Catalog/Domain/Model/BannerImage.php (`UPLOADS_PREFIX = '/uploads/'`, extension whitelist) (A5) | must |
| R8 | Brand image writes go through `SubmissionAccessPolicy` with `CatalogSubmissionKind::Brand` exactly like a rename: a Super Admin may write any brand's image, a regular Admin only their own submission while it is PENDING_APPROVAL or REJECTED; a refused write changes nothing. | constraint | Repo: src/Catalog/Application/Service/SubmissionAccessPolicy.php, src/Catalog/Domain/Model/CatalogSubmissionKind.php, AdminBrandController::rename (A8) | must |
| R9 | Every successful image attach, replace and remove writes one audit-trail entry targeting the brand (new audit action names for image writes); failed attempts record nothing. | constraint | Repo: AdminBrandController.php (`CatalogAuditPort->record` on every write) (A9) | must |
| R10 | Renaming a brand (PUT `/{name}`) preserves its `image_path`: the rename flow (brand row, `storefront_product_view.brand`, submission move) must not clear or alter the image. | constraint | Repo: AdminBrandController::rename (edge case: image path is UUID-based and name-independent) (A15) | must |
| R11 | Deleting a brand (DELETE `/{name}`) still succeeds when the brand has an image; the image file itself is left on disk (orphan accepted). | constraint | Repo: AdminBrandController::delete + BannerSet references-only rule (A7, A10) | must |
| R12 | Image endpoints on a non-existent brand return the controller's existing 404 shape (`{error: 'Brand not found.'}`); image writes never create a brand. | constraint | Repo: AdminBrandController::domainFailure (ERR-002 mapping) (A4) | must |
| R13 | The feature is admin-API only: no storefront read (brand filter in `StorefrontReferenceRepository`, Meilisearch product documents) exposes the brand image, and no product re-indexing is triggered by image writes. | constraint | Prompt silence on storefront display + Repo: src/Shared/Infrastructure/ReadModel/StorefrontReferenceRepository.php (brands() returns names only) (A11) | must |
| R14 | Implementation follows the repo delivery law: each behaviour lands through a failing-test-first cycle and the touched code passes phpstan, php-cs-fixer and the project's test suites. | quality | Repo: AGENTS.md (TDD law, Definition of Done) (A16) | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | One image per brand (a logo), not a gallery or multiple images. | med — a multi-image design changes schema, endpoints and read model |
| 2 | The image is stored as a reference (path string) on `storefront_brand`; no blobs, no image copies, no derived thumbnails. | low |
| 3 | Uploads reuse the existing `AdminUploadController`; adding `brands` to its `FOLDERS` allowlist is the only change there. | low |
| 4 | Image writes use dedicated routes under the brand resource (set/replace: `PUT /api/v1/admin/catalog/brands/{name}/image` with JSON `{"imagePath": "..."}`; remove: `DELETE .../image`), rather than overloading create/rename payloads. | low |
| 5 | Brand images only accept paths under `/uploads/brands/` (stricter than banner's `/uploads/` prefix); rejected otherwise with 422. | low |
| 6 | The list/read response field is named `imagePath` (nullable string), matching the `image_path` column convention of products and banner images. | low |
| 7 | Orphaned files (after replace, remove or brand delete) are never deleted from disk — the banner references-only rule (spec-run banner R22 pattern) applies. | low |
| 8 | Image writes follow the rename access policy (Super Admin any, owner only own PENDING/REJECTED submission) and never change the brand's approval status. | med — if image edits are meant to be unrestricted for any admin, or to require re-approval on APPROVED brands, R8 changes |
| 9 | Audit actions are new `CATALOG_BRAND_IMAGE_*` entries via `CatalogAuditPort`, mirroring `CATALOG_BRAND_RENAMED`/`CATALOG_BRAND_DELETED`. | low |
| 10 | Brand delete with an image is allowed (image does not block deletion; `BrandNotEmptyException` still applies to products only). | low |
| 11 | No storefront exposure: prompt is silent on where the image is displayed; the storefront brand filter returns plain names today, so display is out of scope. | med — if logos must appear in the storefront brand filter or product documents, an extra read-model/indexer work package is needed |
| 12 | The image is optional; brands without an image remain valid and the API must stay backward compatible for clients that ignore the new field. | low |
| 13 | Creating a brand (POST `''`) does not accept an image inline; the image is attached in a second call after creation. | low |
| 14 | Removing a non-set image is idempotent (200), not an error. | low |
| 15 | The image path is independent of the brand name, so rename needs no image migration — but the rename transaction must be proven not to null it (regression test). | low |
| 16 | The repo's TDD law and quality gates (phpstan, php-cs-fixer, deptrac, infection thresholds per AGENTS.md) apply to this feature even though the prompt does not mention them. | low |
| 17 | `ui-features/12-brand-management.md` (listed as grounding) does not exist in the repository — no additional UX constraints (aspect ratio, dimensions, alt text) are therefore imposed. | low — if that doc defines display constraints, they must be merged into this spec |

## Open questions
- [BLOCKING] Is one image per brand (logo) intended, or several images per brand? Default if unanswered: single image (A1) — schema, endpoints and R1–R6 would need redesign for a gallery.
- [minor] Should the storefront show brand images (brand filter, brand pages, Meilisearch documents)? Default if unanswered: no (R13, A11).
- [minor] Where is the brand-management UX documented? The referenced `ui-features/12-brand-management.md` is absent from the repo — is the dashboard frontend in another repository, and does any doc fix display constraints (aspect ratio, minimum resolution)? Default if unanswered: API-only, no dimension constraints (A17).
- [minor] May a regular Admin edit the image of any brand, or only via the rename policy (own PENDING/REJECTED submissions)? Default if unanswered: rename policy (R8, A8).
- [minor] Should replacing/removing an image delete the previous file from disk, or is orphaning accepted? Default if unanswered: never delete files (R4/R11, A7).
- [minor] Must the image be required for new brands going forward, or optional forever? Default if unanswered: optional forever (A12).
- [minor] Do image endpoints need an audit entry also when a Super Admin bypasses ownership, and should failed/forbidden attempts be audited? Default if unanswered: audit successes only (R9).

## Out of scope
- Storefront display of brand images (brand filter API, `StorefrontReferenceRepository`, Meilisearch product documents) — R13 keeps reads admin-only.
- Any dashboard/frontend work: the admin UI is not in this repository (no brand templates or assets exist here); this spec covers the HTTP API only.
- Image processing: resizing, cropping, thumbnails, alt text, EXIF stripping, format conversion.
- File lifecycle management: garbage-collecting orphaned uploads, deleting files on replace/remove/brand-delete.
- Changes to the approval workflow itself (statuses, backfill command, submission kinds) — image writes ride the existing workflow.
- Changes to product images, banner feature, upload size/MIME rules, or the existing `products`/`banner` upload folders' behaviour.
- Brand create/rename/delete semantics beyond preserving the image (R10/R11).

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Schema: brand image column | R1 | migrations/Version20261017000002.php, Version20261101000000.php (naming/conventions), Version20261020000001.php (`image_path VARCHAR(512)` precedent) | New migration adding nullable `image_path VARCHAR(512)` to `storefront_brand` with symmetric `down()` | `migrations/Version<new>.php` (new file only) | none | Migration runs up and down cleanly on PostgreSQL 16 (`doctrine:migrations:migrate` in dev and test envs); column is NULL for all seeded brands |
| WP2 | Upload allowlist for brand images | R2 | src/Catalog/Infrastructure/Http/AdminUploadController.php | `brands` folder accepted by the upload endpoint; file lands in `public/uploads/brands/` with the existing 2 MB / JPEG-PNG-WebP sniff rules | src/Catalog/Infrastructure/Http/AdminUploadController.php + its test file (new, distinct name) | none | Uploading with `folder=brands` returns 201 `{path: '/uploads/brands/<uuid>.<ext>'}`; unknown folders still 422; existing products/banner uploads unaffected (suite green) |
| WP3 | Image write API (attach/replace/remove) | R3, R4, R5, R7, R8, R9, R10, R11, R12 | AdminBrandController.php, SubmissionAccessPolicy.php, CatalogSubmissionKind.php, BannerImage.php (reference validation pattern), CatalogAuditPort | Three routes on the brand resource: set/replace image, remove image; access policy, audit entries, rename/delete preservation, 404/422 error mapping | src/Catalog/Infrastructure/Http/AdminBrandController.php, any new Domain/Application files it introduces (e.g. path-validation value object or exceptions — new files only), tests/Functional/AdminBrandImageTest.php (new file) | WP1 | Functional tests prove: owner/Super-Admin authorization per R8, 422 on non-`/uploads/brands/` paths, rename keeps image, delete with image succeeds, 404 on unknown brand, audit rows written; full suites + phpstan green |
| WP4 | Image read in admin brand list | R6 | AdminBrandFinder.php, ListAdminBrands.php, AdminBrandController::list | Brand list rows carry nullable `imagePath`; filter/sort/pagination unchanged | src/Catalog/Infrastructure/ReadModel/AdminBrandFinder.php, tests for the finder (new file, distinct name) | WP1 | A test shows `GET /api/v1/admin/catalog/brands` returning `imagePath: null` and the set value after WP3's attach; sort/filter/pagination tests still green |

Contract pinned for parallel work (all WPs must match): DB column `storefront_brand.image_path VARCHAR(512) NULL`; JSON field `imagePath` (nullable string) holding values like `/uploads/brands/<uuid>.<ext>`; brand identity stays the `name` path parameter.

File-conflict note: WP2, WP3 and WP4 own disjoint files. WP3 and WP4 must merge after WP1 (column must exist for tests); they may be developed in parallel against WP1's migration.

# Spec: Brand image CRUD (admin catalog API)
Version: 2

## Goal
An administrator can attach, view, replace and remove one image per brand through the existing admin brand API, with the image stored as a reference to a dashboard-uploaded file, without changing brand create/rename/delete behaviour, the approval workflow or any other feature.

## Corrected prompt
"Implement the feature of CRUD image of brands": add create (attach), read (view), update (replace) and delete (remove) support for the image of a brand, on the existing brand management feature. Nothing else is requested.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | A new Doctrine migration adds a nullable `image_path VARCHAR(255)` column to `storefront_brand`; its `down()` drops the column; existing brand rows keep working with `image_path = NULL`. | functional | "crud image of brands" — an image must be persisted per brand | must |
| R2 | `POST /api/v1/admin/catalog/uploads` accepts `folder=brands`, stores the file under `public/uploads/brands/` and returns its path; the existing 2 MB limit and content-sniffed JPEG/PNG/WebP acceptance apply unchanged, and `products`/`banner`/unknown folder values behave exactly as before. | functional | "crud image of brands" — image files enter through the existing upload flow | must |
| R3 | An authorized admin can attach an image to a brand that has none via `PUT /api/v1/admin/catalog/brands/{name}/image` with an upload path; the reference is persisted on the brand row and the response returns the brand with its image. | functional | "crud image of brands" — the "c" of "crud" | must |
| R4 | `GET /api/v1/admin/catalog/brands` returns each brand's image as a nullable field alongside `name` and `productCount`; the list's filtering, sorting and pagination behaviour is unchanged. | functional | "crud image of brands" — the "r" of "crud" | must |
| R5 | An authorized admin can replace a brand's existing image with a different valid upload path; afterwards the stored reference equals the new one and the old one is no longer returned. | functional | "crud image of brands" — the "u" of "crud" | must |
| R6 | An authorized admin can remove a brand's image via `DELETE /api/v1/admin/catalog/brands/{name}/image`; the brand still exists and its image reads back as null; removing when no image is set succeeds (idempotent). | functional | "crud image of brands" — the "d" of "crud" | must |
| R7 | Image operations act on the existing brand entity under `/api/v1/admin/catalog/brands` as dedicated sub-routes (`PUT`/`DELETE /{name}/image`); the existing brand create/rename/delete route contracts are unchanged. | constraint | "image of brands" — on the same brand object the existing CRUD manages | must |
| R8 | Image operations on a brand that does not exist return the controller's existing 404 error shape and never create a brand. | constraint | "image of brands" — bound to existing brands | must |
| R9 | A brand image write whose payload is missing, blank, not a string, or not a valid `/uploads/brands/` image reference (image extension per the existing banner whitelist) is refused with HTTP 422 and persists nothing. | constraint | "crud image of brands" — only real image references may be attached | must |
| R10 | Brand image writes pass through `SubmissionAccessPolicy` with `CatalogSubmissionKind::Brand` exactly like a rename (Super Admin: any brand; regular Admin: only their own `PENDING_APPROVAL`/`REJECTED` submission); a refused write persists nothing, and an image write never changes the brand's `approval_status`. | constraint | "crud image of brands" — exercised on the approval-governed brand feature | must |
| R11 | Every successful attach, replace and remove records exactly one audit entry via `CatalogAuditPort` targeting the brand (new image-specific audit actions); failed attempts record nothing. | constraint | "crud image of brands" — following the existing brand write conventions | must |
| R12 | Renaming a brand preserves its image: after `PUT /{name}` the brand under the new name returns the same image reference it had before the rename. | functional | "crud image of brands" — the image belongs to the brand | should |
| R13 | Deleting a brand that has an image still succeeds; the image reference disappears with the brand row and does not block re-creating a brand with the same name. | constraint | "crud image of brands" — existing brand delete keeps working | must |
| R14 | Each behaviour is delivered through the repo's TDD law (failing test first) and the touched code passes phpstan, php-cs-fixer and the project test suites. | quality | prompt silent; grounded in AGENTS.md delivery law | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | One image per brand (a logo), not a gallery; the prompt's singular "image" is read literally. | med |
| 2 | The two-step upload flow is reused: the file is uploaded via `AdminUploadController` first, then the returned path is sent to the brand image endpoints; brand image endpoints never accept multipart. | low |
| 3 | The image is stored as a path reference string on the brand row; no blobs, no derived thumbnails. | low |
| 4 | The image is optional: brands without one remain valid, and existing API clients that ignore the new field are unaffected. | low |
| 5 | Brand create (`POST ''`) does not accept an image inline; the image is attached in a second call after creation. | low |
| 6 | Removed, replaced or orphaned upload files stay on disk — no garbage collection (same tolerance as products and banner). | low |
| 7 | The feature is admin-API only: no dashboard UI exists in this repository and `ui-features/12-brand-management.md` does not exist (verified), so no display constraints (aspect ratio, dimensions, alt text) apply. | low |
| 8 | Storefront surfaces (brand filter in `StorefrontReferenceRepository`, Meilisearch product documents) do not expose the image, and image writes trigger no re-indexing. | low |

## Open questions
- [minor] One image per brand, or several (gallery)? Default if unanswered: exactly one (Assumption 1). Note: a gallery would require redesigning R1 and R3–R6, but the prompt's singular "image" makes this a faithful reading, not a guess.
- [minor] Accept only `/uploads/brands/` references, or also arbitrary site paths / http(s) URLs like the product rule? Default if unanswered: `/uploads/brands/` only (R9).
- [minor] Should the storefront brand filter or brand pages expose the image? Default if unanswered: no (Assumption 8).
- [minor] Should failed or forbidden image writes also be audited? Default if unanswered: audit successful writes only (R11).
- [BLOCKING] none.

## Out of scope
- Storefront exposure of brand images (brand filter payloads, brand pages, Meilisearch product documents) and any re-indexing.
- Dashboard/frontend UI work (the admin UI is not in this repository).
- Image processing: resizing, cropping, thumbnails, format conversion, alt text, EXIF handling.
- File lifecycle management: deleting or garbage-collecting upload files on replace, remove or brand delete.
- Changes to upload constraints (2 MB, JPEG/PNG/WebP) or to the existing `products`/`banner` folder behaviour.
- Multiple images per brand / galleries / ordering.
- Changes to the approval workflow itself (statuses, submission store schema, backfill command) — image writes ride the existing workflow.
- Changes to brand create/rename/delete semantics beyond preserving the image (R12, R13).

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Persist the brand image reference | R1 | `migrations/Version20261017000002.php` (brand table, name PK), `migrations/Version20261017000001.php` (`image_path VARCHAR(255)` product precedent) | New migration adding nullable `image_path VARCHAR(255)` to `storefront_brand` with symmetric `down()` | `migrations/Version<new timestamp>.php` (new file only) | none | `doctrine:migrations:migrate` and down succeed; existing brands read back with NULL image; no other table touched |
| WP2 | Accept brand image uploads | R2 | `src/Catalog/Infrastructure/Http/AdminUploadController.php` (`FOLDERS` allowlist) | `brands` added to the upload folder allowlist so `folder=brands` stores under `public/uploads/brands/` under the existing rules | `src/Catalog/Infrastructure/Http/AdminUploadController.php` + its new test file (distinct name) | none | Uploading with `folder=brands` returns a `/uploads/brands/…` path; `products`/`banner` unchanged; unknown folders still refused |
| WP3 | Brand image write operations (attach/replace/remove) | R3, R5, R6, R7, R8, R9, R10, R11, R12, R13 | `src/Catalog/Infrastructure/Http/AdminBrandController.php` (current CRUD, transaction + audit pattern), `src/Catalog/Application/Service/SubmissionAccessPolicy.php`, `src/Catalog/Domain/Model/CatalogSubmissionKind.php`, `src/Catalog/Domain/Model/BannerImage.php` (reference-validation pattern) | The set/replace and remove image routes with access policy, audit entries, payload/reference validation and 404/422 mapping; rename/delete preservation of the image | `src/Catalog/Infrastructure/Http/AdminBrandController.php`; any new Domain/Application files it introduces (validator value object, exceptions — new files only); `tests/Functional/AdminBrandImageTest.php` (new file) | WP1 | Focused tests prove: attach persists and echoes the image, replace overwrites, remove nulls it (idempotent), 422 on invalid references with nothing persisted, policy refusals (403/404 per existing mapping), 404 on unknown brand, audit rows written, rename keeps the image, brand delete with image succeeds |
| WP4 | Brand image read | R4 | `src/Catalog/Infrastructure/ReadModel/AdminBrandFinder.php`, `src/Catalog/Application/Query/ListAdminBrands.php` | Admin brand list rows and payload carry the nullable image field alongside `name` and `productCount` | `src/Catalog/Infrastructure/ReadModel/AdminBrandFinder.php`, `src/Catalog/Application/Query/ListAdminBrands.php` (only if a change is needed), finder test (new file, distinct name) | WP1 | `GET /api/v1/admin/catalog/brands` returns `image: null` for imageless brands and the stored path for others; filter/sort/pagination tests still green |
| WP5 | End-to-end CRUD cycle verification | R3, R4, R5, R6 (cycle closure), R2 (upload step in the cycle) | WPs 1–4, `tests/Functional/AdminBrandApprovalWorkflowTest.php` conventions | One functional test class proving the full lifecycle through real endpoints: upload to `brands` folder → attach → read in list → replace → remove → list shows null, including policy and audit integration | `tests/Functional/AdminBrandImageCrudCycleTest.php` (new file only) | WP2, WP3, WP4 | New suite green: full set→read→replace→remove cycle passes; full project test suite passes |

Contract pinned for parallel work (all WPs must match): DB column `storefront_brand.image_path VARCHAR(255) NULL`; JSON field `image` (nullable string) on brand list items and image-write responses, holding values like `/uploads/brands/<uuid>.<ext>`; brand identity stays the `name` path parameter. File ownership is disjoint: WP1 (migration), WP2 (upload controller), WP3 (brand controller + new domain files), WP4 (finder), WP5 (cycle test file).

## Decisions (synthesizer only)
| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Image count per brand | one (Assumption 1) | one (Assumption 1) | one (Assumption 1) but flagged BLOCKING | one per brand | All three converge on single. Demoted C's blocking flag: the prompt's singular "image" makes this the faithful reading, not a coin flip; kept as a minor open question with the redesign risk noted. |
| DB column type | `VARCHAR(255)` (mirrors product) | `VARCHAR(255)` | `VARCHAR(512)` (banner precedent) | `VARCHAR(255)` | Both precedents exist in the repo (verified). The product's single-image column is the closer analog to one image per brand; banner's 512 belongs to its child-table pattern; also the analyst majority. |
| JSON field name | `imagePath` | `image` | `imagePath` | `image` | Repo convention verified: the product API uses `image` for exactly this shape (single optional image on an admin catalog item, read and write); the prompt's own word is "image". |
| Reference strictness | `/uploads/brands/` only | `/uploads/brands/` only | R7 says `/uploads/` + extension, but its own Assumption 5 says `/uploads/brands/` | `/uploads/brands/` only | Analyst majority; C is internally inconsistent and its stricter assumption resolves the tie; also matches the dedicated upload folder introduced in R2. |
| Remove idempotency | not stated | not stated | idempotent success | idempotent | Raised by one analyst but low-risk, testable, and consistent with REST semantics — kept per the "grounded low-risk assumption" rule. |
| Test organization | all functional tests in a separate WP5 after WPs 1–4 | tests inside each WP plus an e2e WP4 | tests inside each WP | tests owned per WP, plus a small WP5 owning only the cycle test file | The repo's TDD law (no production code without a failing test) favors tests beside the behavior; a dedicated big test WP would serialize work. WP5 keeps end-to-end proof with disjoint file ownership. |
| Storefront exposure | out of scope (Assumption 10) | out of scope (Assumption 7) | must-requirement R13 and also out of scope | out of scope only | C double-listed it. The prompt never mentions the storefront; a prohibition is a non-requirement, so it stays in Out of scope and Assumption 8. |
| "Complete CRUD, none a stub" as a standalone requirement | required (R6) | implied by cycle test | implied | dropped as a separate requirement | Redundant: R3–R6 each require one operation to exist and work, and WP5's cycle test proves closure. Removing duplicates per synthesis rule 4. |

## Changelog (synthesizer and orchestrator only)
- v1: created — synthesized from analyst-a.md, analyst-b.md and analyst-c.md; repository claims spot-checked (AdminBrandController routes/audit, AdminUploadController `FOLDERS`, AdminBrandFinder payload, migration precedents for `image_path`, absence of `ui-features/`, AGENTS.md TDD law).
- v2 (orchestrator, Stage 4): WP4 reported a spec defect — the pinned contract (nullable `image` on brand list items) necessarily invalidates existing tests' strict row-shape assertions, contradicting WP4's "do not touch other tests" scope rule. Resolution: the pinned contract wins; updating the 4 strict assertions (additive `'image' => null`) is in scope for WP4. No requirement text changed.

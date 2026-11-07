# Task F-subcategory-image-crud
Type: feature
Started: 2026-11-06T20:10:00+01:00
Requirement: CRUD of the sub-category pictures — attach, read, replace and remove one image per `subcategories` node, mirroring the brand image CRUD (F-brand-image-crud).

## Spec traceability
- Spec: no spec-run (direct implementation mirroring spec-run-19's brand-image contract); design note: the image lives on the JSON node (`{label, image?, children?}`), addressed by label path — self-cleaning under whole-tree saves
- Prompt archive: conversation prompt "now crud of the sub category pictures" (no /spec invoked)
- Stage 4 summary: 5/5 functional tests green (91 assertions) + shared VO regression suites green; phpstan clean on 15 touched files; cs-fixer clean; full-suite verdict: Unit/Integration/Projection/Contract/Architecture all OK, Functional 1 pre-existing failure (AdminSettingsTest settings drift, predates the feature)
- Requirements: cycle (upload→attach→read→replace→remove), policy (SubmissionAccessPolicy, Category kind), 422 invalid path/reference, 404 unknown category/node, label-path addressing, tree-save echo semantics, audit CATALOG_CATEGORY_SUBCATEGORY_IMAGE_SET/REMOVED exactly-once, approval_status untouched

## Requirements → tests
- tests/Functional/AdminSubcategoryImageTest.php (5 tests): full cycle, policy guard, invalid payloads, 404s, label paths + tree saves
- tests/Unit/Catalog/Domain/CategorySubtreeTest.php: tree rules still green with image-carrying normalize/copy

## Gates
GATE: SPEC ok — 2026-11-06T20:10:00+01:00 — orchestrator (direct feature, brand-image contract reused)

## Notes
- No migration: the image is a validated key on the `subcategories` JSON node (`storefront_category` row), VARCHAR(255) bound enforced by the VO.
- `BrandImageReference`/`BrandImageRejectedException` were generalized into `UploadedImageReference` (allowed-prefix constructor arg) + `CatalogImageRejectedException`; brand behaviour unchanged (brand suites green).
- Upload allowlist gained `categories` (folder=categories → /uploads/categories/).
- A whole-tree save (PUT /categories/{slug}) defines the tree content: nodes echoing a valid image keep it, saves without it drop it, invalid references are dropped silently — the dedicated image endpoints are the policy-checked way to set/clear one node without a full save.
- Storefront exposure of subcategory images is out of scope (mirrors brand decision).
- Concurrent in-flight SOLID remediation landed mid-task: Integration (banner fixtures) and Architecture (SolidBoundariesTest) went from red to green between runs — not this feature's doing.

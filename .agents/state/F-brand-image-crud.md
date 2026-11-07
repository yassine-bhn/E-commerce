# Task F-brand-image-crud
Type: feature
Started: 2026-11-06T18:55:00+01:00
Requirement: Implement the feature of CRUD image of brands: add create (attach), read (view), update (replace) and delete (remove) support for the image of a brand, on the existing brand management feature. Nothing else is requested.

## Spec traceability
- Spec: spec-run-19/spec.md (version 2)
- Prompt archive: spec-run-19/prompt.md
- Stage 4 summary: coverage ok — 14/14 requirements evidenced by tests run green in this session; spec v2 records the WP4 shape-assertion resolution; full-suite reds (Integration banner ×8, Functional ×2, Architecture SolidBoundaries ×3) traced to pre-existing committed/in-flight work (0482511 raw-body change, category submission churn), none touching this feature's files
- Requirements: R1, R2, R3, R4, R5, R6, R7, R8, R9, R10, R11, R12, R13, R14

## Requirements → tests
- R1 → migrations/Version20261106120000.php (up+down verified dev+test; NULL readback)
- R2 → tests/Functional/AdminBrandUploadFolderTest.php (2)
- R3, R5, R6, R7, R8, R9, R10, R11, R12, R13 → tests/Functional/AdminBrandImageTest.php (8)
- R4 → tests/Functional/AdminBrandImageListTest.php
- R2–R6 cycle closure + R10/R11 integration → tests/Functional/AdminBrandImageCrudCycleTest.php (2)
- R14 → phpstan clean (16 files), php-cs-fixer 0 fixable, suites: Unit 382 OK, Projection 41 OK, Contract 16 OK, focused brand set 48/538 OK

## Gates
GATE: SPEC ok — 2026-11-06T18:55:00+01:00 — orchestrator

## Notes
- Delivered via spec pipeline spec-run-19 (3 analysts + synthesizer + 5 WPs); WP5 completed by the orchestrator after dispatch was declined.
- In-flight uncommitted refactor (brand/product/category handler extraction) was built upon, not reverted; its pre-existing reds are out of scope (SolidBoundariesTest red-by-design, banner Integration fixtures stale vs commit 0482511).
- Storefront exposure of brand images is out of scope per spec (Assumption 8).

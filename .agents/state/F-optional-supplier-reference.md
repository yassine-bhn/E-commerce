# Task: F-optional-supplier-reference

Workflow: feature

## Requirement

In the backend, a product currently has only one reference. Add another reference of the same kind for the supplier's reference. The request suggests `supplierRefference` or `supplier-refference`; use the project's naming conventions instead. This field is optional and stores the supplier's reference. In the front end, consume this feature. The supplier reference must never be shown to end users; it is shown only in the Admin or Super Admin interface.

## Spec traceability
- Spec: spec-run-16/spec.md (version 1)
- Prompt archive: spec-run-16/prompt.md
- Stage 4 summary: coverage ok — all 19 requirements (R1–R19) mapped to WP1–WP7. WP1 added reversible nullable `supplier_reference VARCHAR(64)` migration; WP2 carried it through `StorefrontProductView::toAdminApi()` while keeping `toApi()` and the public SELECT supplier-free; WP3 added parsing, validation, persistence and admin responses on the write path; WP4 modelled the field in admin types and the create/edit form; WP5 rendered it in the shared inspector; WP6 proved non-exposure across public payloads and Meilisearch; WP7 audited the public front end. Backend Integration (154), Projection (39) and Contract (16) suites green; Unit has 39 pre-existing failures and Functional 9 pre-existing failures from the user's in-progress SOLID refactor (deleted `BannerRules`/`CategoryHierarchyValidator`/`ProductDeletionGuard` and removed `$productGuard`); phpstan 152 errors and php-cs-fixer 7 files are pre-existing baseline, none in touched files; deptrac 2 pre-existing violations in `AddWishlistItemHandler`; frontend typecheck and lint green with 0 errors. No supplier reference appears in public types, index documents, or storefront code.
- Requirements: R1, R2, R3, R4, R5, R6, R7, R8, R9, R10, R11, R12, R13, R14, R15, R16, R17, R18, R19

## RED evidence (Stage 3, TDD-001)
- WP1: `docker compose exec -T app vendor/bin/phpunit tests/Integration/Catalog/SupplierReferenceMigrationTest.php` → `MigrationClassNotFound: DoctrineMigrations\Version20261105000000` before the migration existed; then `OK (2 tests, 13 assertions)`.
- WP2: `docker compose exec -T app vendor/bin/phpunit tests/Unit/Catalog/StorefrontProductViewSupplierReferenceTest.php` → 2 failures (`null is identical to 'SUP-42'`, missing `supplierReference` key); `tests/Integration/Catalog/AdminProductSupplierReferenceReadTest.php` → 2 failures; then `OK (7 tests, 33 assertions)`.
- WP3: `docker compose exec -T app php vendor/bin/phpunit tests/Functional/AdminProductSupplierReferenceWriteTest.php --testdox` → `Tests: 27, Failures: 23` (null vs 'SUP-42', 422 actual 200/201); then `OK (27 tests, 85 assertions)`.
- WP6: constraint tests added after the fact (no RED production change); non-exposure sweep `OK (6 tests, 54 assertions)`; projection `OK (2 tests, 13 assertions)`.

## Stage 3 implementation status
All WPs complete. Production files changed: `migrations/Version20261105000000.php`, `src/Catalog/Infrastructure/ReadModel/StorefrontProductView.php`, `AdminProductFinder.php`, `StorefrontProductRepository.php`, `src/Catalog/Infrastructure/Http/AdminProductController.php`, `Zidane_front_web/src/lib/api/admin.ts`, `ProductSheet.tsx`, `ProductInspector.tsx`. Test files added: migration, unit view, integration admin read, functional admin write, functional non-exposure, projection indexer. TDD-001 satisfied with recorded RED output above.

## Harness review gate (Stage 5)
Pre-existing baseline blockers (not caused by this feature): Unit 39 errors/failures and Functional 9 failures from the user's in-progress SOLID refactor; phpstan 152 errors and php-cs-fixer 7 files; deptrac 2 violations in `AddWishlistItemHandler`. These are out of scope and are not fixed here. Reviewers are dispatched on the supplier-reference diff only.

## Review gate (Stage 5)
All three roster reviewers (code-reviewer, architecture-reviewer, security-reviewer) returned no findings on the supplier-reference diff. Rules verified clean across NAM-001, ERR-001–ERR-003, CMD-001–CMD-003, QRY-001–QRY-004, LAY-001–LAY-004, MIG-001/MIG-002, SEC-001–SEC-005, ECO-006, ECO-008, TDD-004/TDD-006, AGT-002/AGT-005. No fixes required.

## Closure
- Supplier-reference behavior: all 19 requirements (R1–R19) pass review and verification; no reviewer findings.
- Baseline blockers (pre-existing, out of scope, not fixed): Unit 39 errors/failures and Functional 9 failures from the user's in-progress SOLID refactor (deleted `BannerRules`/`CategoryHierarchyValidator`/`ProductDeletionGuard`, removed `$productGuard`); phpstan 152 errors and php-cs-fixer 7 files; deptrac 2 violations in `AddWishlistItemHandler`. These are not caused by this feature and are left untouched per spec out-of-scope.
- Clean `GATE: DONE` is therefore not claimed. The feature is complete and shippable on its own; the baseline must be repaired by the user's refactor before the harness DoD can close.

GATE: REVIEW ok — 2026-11-05T16:55:00+01:00 — orchestrator

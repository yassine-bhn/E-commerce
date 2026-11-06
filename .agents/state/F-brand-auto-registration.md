# Task F-brand-auto-registration
Type: bugfix
Started: 2026-11-06T00:00:00+00:00
Requirement: Brand strings carried by products must be registered in storefront_brand (approved) so the admin Marques screen lists them and rename cascades; backfill existing product brands.

## Gates

## RED record
RED: tests/Functional/AdminBrandApprovalWorkflowTest.php::testAProductRegistersItsUnknownBrandAsApproved — "the brand carried by the product is registered and counted ... Failed asserting that two arrays are identical. Expected WfBrand... productCount 1, got empty list." — cmd: docker compose exec -T app vendor/bin/phpunit --testsuite=Functional --filter=testAProductRegistersItsUnknownBrandAsApproved → FAIL
RED: tests/Integration/Catalog/RegisterProductBrandsCommandTest.php — "Error: Class App\\Catalog\\Infrastructure\\Console\\RegisterProductBrandsCommand not found" — cmd: docker compose exec -T app vendor/bin/phpunit --testsuite=Integration --filter=RegisterProductBrandsCommandTest → ERROR
RED: tests/Functional/AdminBrandApprovalWorkflowTest.php::testTheAccessPolicyGuardsWritesAndDeletionIsSuperAdminOnly — "Call to a member function ensureCanDeleteProduct() on null / Undefined property productGuard" — pre-existing failure fixed by using ProductDeletionReader::orderCount.
ATTEMPTS: 0

## Human approvals

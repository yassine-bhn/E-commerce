# WP3 brief — route every approval path through the store

Authority: `spec-run-15/spec.md` v2, requirements R2, R4–R11, R13, R34 and the WP3 row.
WP2 built the store (`spec-run-15/wp2-brief.md`), WP4 built the stock port. This package makes the
store the only approval writer and the only approval reader for authorisation.

## Scope

Owned paths:

- `src/Catalog/Domain/Model/ApprovalStatus.php` (docblocks only, true rule)
- `src/Catalog/Application/CommandHandler/CreateCategoryHandler.php`
- `src/Catalog/Application/CommandHandler/ReplaceCategoryTreeHandler.php`
- `src/Catalog/Application/CommandHandler/AddCategorySubLevelHandler.php`
- `src/Catalog/Application/CommandHandler/SubmitCatalogSubmissionHandler.php`
- `src/Catalog/Application/CommandHandler/DecideCatalogSubmissionHandler.php`
- `src/Catalog/Application/CommandHandler/SetProductPublicationHandler.php`
- `src/Catalog/Application/CommandHandler/DeleteCategoryHandler.php`
- `src/Catalog/Application/Service/SubmissionAccessPolicy.php`
- `src/Catalog/Infrastructure/ReadModel/CatalogSubmissionFinder.php`
- approval portions of `src/Catalog/Infrastructure/Http/AdminProductController.php`
- approval + product-deletion portions of `src/Catalog/Infrastructure/Http/AdminCategoryController.php`
- `src/Catalog/Infrastructure/Console/BackfillCatalogApprovalCommand.php`
- `src/Catalog/Application/Port/CatalogSubmissionStore.php`,
  `src/Catalog/Infrastructure/Persistence/DbalCatalogSubmissionStore.php`,
  `src/Catalog/Domain/Model/CatalogSubmission.php` only to add the `import()` seed path
  (R11 needs it; this is the one WP2 extension allowed)
- `tests/Functional/AdminCatalogApprovalWorkflowTest.php`
- `tests/Functional/AdminCategoryManagementTest.php`
- `tests/Functional/AdminProductCategoryMoveTest.php`
- `tests/Architecture/CatalogApprovalDesignTest.php`
- `tests/Integration/Catalog/BackfillCatalogApprovalCommandTest.php`
- `tests/Integration/Catalog/Support/MigrationProbeDatabase.php`
- direct-seed fixtures in other tests only where they create a product/category view row and then
  call an approval route; add the matching `catalog_submission` row instead of falling back to the
  view.

Never edit: `spec-run-15/**`, `.agents/**`, WP4 stock logic, WP2 projector/replay semantics, money
parsing, cart/checkout quote logic, frontend, or the two migrations already applied.

## Store extension for the backfill

Add to `CatalogSubmissionStore` and `DbalCatalogSubmissionStore`:

```php
public function import(CatalogSubmission $submission): bool;
```

- Inserts the submission exactly as supplied with `ON CONFLICT (kind, slug) DO NOTHING`.
- Returns whether a row was inserted. No outbox event, no audit row: seeding an existing state is
  not a transition. It may project the six columns (the view already holds the same values).
- `CatalogSubmission::reconstitute(...)` is already public; the command uses it.
- TDD: RED first (store has no `import`), then implement. Add an integration assertion that a second
  import returns false and changes nothing.

## Creation

- `AdminProductController::create`: remove `approval_status`, `submitted_by`, `submitted_at` from
  the INSERT and from `parameters`/`types` if they are still there; after the content row exists,
  call `$store->create(CatalogSubmissionKind::Product, $slug, $admin->getId())`. Remove the
  controller's `CATALOG_SUBMISSION_CREATED` audit call — the store writes it. Keep WP4's
  `InventoryStockPort` call and every price/content line untouched.
- `CreateCategoryHandler`: remove the approval columns from the INSERT and the manual
  `CATALOG_SUBMISSION_CREATED` audit; call the store's `create()` inside the same transaction after
  the row insert. The store owns the audit and the outbox event.
- A created row is `PENDING_APPROVAL`, owned by the creator, with exactly one CREATED audit entry
  and one create event.

## Submit / resubmit / decide

- `SubmitCatalogSubmissionHandler`: inject `CatalogSubmissionStore`, delegate to
  `submit(kind, slug, actorId, actorIsSuperAdmin)`. Delete the direct SQL, ownership check and audit
  (the store does all three). Keep the route and status codes unchanged.
- `DecideCatalogSubmissionHandler`: inject the store, delegate to
  `decide(kind, slug, decision, reviewerId, reason)`. Delete the direct SQL and audit. The store
  refuses non-pending, requires a rejection reason, writes the audit/outbox and projects.

## Reviewer save / owner edit

Product update (`AdminProductController::update`):

- Keep `SubmissionAccessPolicy::authorize()` first.
- Remove `$next`, `$reopens`, the approval columns from the UPDATE and the `STORE_REOPEN` logic.
- Write the content columns only, then the existing `CATALOG_PRODUCT_UPDATED` audit, then:
  - Super Admin: `$store->reviewerSave(Product, $slug, $admin->getId())`;
  - regular Admin: `$store->ownerModified(Product, $slug)`.
  The store's reviewer-save writes `CATALOG_SUBMISSION_APPROVED` only when the row really moves from
  pending/rejected to approved; an already-approved save writes no approval entry. Audit order:
  `CATALOG_PRODUCT_UPDATED` then the approval entry.
- Preserve WP4's stock port call and all price/content fields.

Category replace-tree and add-sub-level:

- Keep `SubmissionAccessPolicy::authorize()` for the ownership decision, but ignore its return value
  for writing; the store now owns the status.
- Remove `STORE_REOPEN`, the `afterReviewerModification`/`afterModification` branches and the
  approval columns from the UPDATE. Write content only, then `CATALOG_CATEGORY_UPDATED`, then
  `reviewerSave` (Super Admin) or `ownerModified` (regular Admin). Same audit order as products.
- R7 applies to both paths: pending or rejected -> approved by a Super Admin save; approved stays
  approved with its original reviewer metadata; a regular Admin can only edit pending/rejected and
  the status is unchanged.

## Approval reads

- `SubmissionAccessPolicy`: inject `CatalogSubmissionRepository` (the DBAL store implements it) and
  read `find(kind, slug)`; use the model's status and `submittedBy()`. No SQL against either view
  table. Keep the same exceptions and ownership rules.
- `CatalogSubmissionFinder`: build both list and detail rows from `catalog_submission s` joined to the
  content table (`storefront_product_view` / `storefront_category`) for `label`, `category_slug` and
  `published`. All filters (`submitted_by`, `approval_status`, slug/search term) must be qualified
  with `s.`; the page total and rows share the predicate. `CatalogSubmissionView::fromRow()` keeps
  its current keys.
- `SetProductPublicationHandler`: inject the store; replace the view read with `find(Product, slug)`
  and use the model status. Keep the `published` UPDATE and its audit.
- `AdminProductController::adminPayload()` and `CatalogSubmissionDetailHandler` already go through
  the finder; no extra read change.

## Deletion

- `DeleteCategoryHandler`: inject the store and call `delete(Category, slug)` in the same transaction
  after the content delete. Audit stays.
- `AdminCategoryController::deleteProduct`: inject the store and call `delete(Product, slug)` in the
  same transaction as the view delete; keep WP4's `InventoryStockPort::remove()` call. Audit stays.
- Slug reuse must not inherit a previous approval: a second create inserts a fresh submission.

## Backfill (R11)

Rewrite `app:catalog:backfill-approval` so it seeds through the store:

1. Select every product/category view row with no `catalog_submission` row. Build a
   `CatalogSubmission::reconstitute(...)` from the view's approval metadata and call
   `$store->import(...)`. This preserves already-approved/rejected rows and submitted metadata.
2. Then select the legacy rows (no submitted/reviewed metadata) that are still
   `PENDING_APPROVAL` and have a Pending submission. For products call
   `$store->decide(Product, slug, CatalogDecision::Approve, 'system:backfill-approval')` and set
   `published = true`; for categories call the same decision (no publish). The store writes the
   approval audit and outbox and projects the view.
3. Dry-run by default, `--apply` writes, report imports + approvals + untouched. One transaction.
   Idempotent: a second `--apply` imports 0, approves 0 and writes nothing.
4. Update `BackfillCatalogApprovalCommandTest`:
   - migrate the probe through `Version20261029000001` (add a constant to
     `MigrationProbeDatabase`; do not change the rollback helpers);
   - construct the command with the probe connection + a `DbalCatalogSubmissionStore` wired to the
     probe (`DoctrineOutbox` with the kernel serializer, `DbalCatalogAuditWriter`,
     `ProjectStorefrontApproval`);
   - assert the new `catalog_submission` rows (legacy approved, submitted preserved, pending
     legacy approved), the approval audit/outbox, the publish flag, dry-run, and idempotency.
5. Apply it to dev and test after the code is green: `app:catalog:backfill-approval --apply`.
   Report the counts.

## Tests and design guard

- `AdminCatalogApprovalWorkflowTest`: update the reviewer-save audit expectations at the two
  super-admin save tests (around lines 268 and 521) to include exactly one
  `CATALOG_SUBMISSION_APPROVED` after the `CATALOG_PRODUCT_UPDATED`; a second save of an
  already-approved row adds no approval entry. Update category save audit expectations the same way.
- `AdminCategoryManagementTest`: the super-admin sub-level save already approves the category, so
  remove/replace the redundant `approve()` call that now returns 409; assert the save approved it
  and keep the navigation assertion. Do not weaken the workflow coverage.
- `AdminProductCategoryMoveTest`: if its super-admin product save now writes an approval entry,
  update the audit assertion deliberately.
- Any other functional test that seeds a view row directly and then calls submit/decide/update/
  publication must also seed the matching `catalog_submission` row (or create the record through the
  admin endpoint). No production fallback to view approval columns is allowed.
- `CatalogApprovalDesignTest`: rewrite the source assertions for the new architecture, without
  weakening them:
  - `CatalogDecision` is named only by the enum, the command, the controller, `CatalogSubmission`,
    `DbalCatalogSubmissionStore` and the backfill command.
  - Files containing `INSERT INTO catalog_submission`, `UPDATE catalog_submission` or
    `DELETE FROM catalog_submission` are exactly `DbalCatalogSubmissionStore.php` (migrations are
    not scanned).
  - Files writing approval columns to the view tables are exactly `ProjectStorefrontApproval.php`
    and `ReplayCatalogSubmission.php`; handlers/controllers/backfill must not.
  - Keep the R40 workflow-column assertions for products and categories and add the
    `catalog_submission` column set (kind, slug, approval_status, submitted_by, submitted_at,
    reviewed_by, reviewed_at, rejection_reason, updated_at).
  - Keep the audit shape/append-only assertions.
- Fix `CatalogVisibilitySingleSourceTest` only if WP3's file changes make it fail for an approval
  reason; the known Meilisearch `AdminProductListTest:142` failure stays for WP7.

## TDD and verification

RED before production code for every behaviour change; capture the actual failure. At minimum:
submit delegates to the store, decide delegates, reviewer save writes the approval audit and a
second save does not, approval reads use `catalog_submission`, deletion removes the submission,
backfill imports and approves through the store.

Run and report:

- `docker compose exec app php bin/console app:catalog:backfill-approval --apply` (dev and test)
- `docker compose exec app vendor/bin/phpunit tests/Unit`
- `docker compose exec app vendor/bin/phpunit tests/Integration`
- `docker compose exec app vendor/bin/phpunit tests/Projection`
- `docker compose exec app vendor/bin/phpunit tests/Functional`
- `docker compose exec app vendor/bin/phpunit tests/Architecture`
- `docker compose exec app vendor/bin/phpstan analyse src/Catalog src/Shared`
- `docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff` on touched files

The only expected remaining failure is `AdminProductListTest:142` (Meilisearch state, WP7). If
`CatalogVisibilitySingleSourceTest` still fails, report its exact cause; do not edit it unless the
cause is WP3's own file change.

Do not commit. Final report at most 18 lines: status; files; RED commands + failure lines; backfill
counts; suite counts; each WP3 Done-when; remaining failures; deviations.

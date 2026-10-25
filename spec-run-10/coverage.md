# Coverage — spec-run-10 (R1–R59)

Source spec: `spec-run-10/spec.md` v1. Suite executed inside the app container on 2026-10-23; the
commands and their output are in "Evidence" at the bottom and in
`.agents/state/F-super-admin-approval-workflow.md`.

A follow-up pass on 2026-10-24 (`F-approval-coverage-gaps`) closed the eight evidence gaps this file
listed under "Remaining limitations". It added tests and no migration; it changed one `src/**` file,
`StorefrontReviewController`, because the reviews test it added exposed a genuine visibility defect
(see the third block under "Evidence"). Its results are the second and third blocks under
"Evidence" and `.agents/state/F-approval-coverage-gaps.md`.

A third pass the same day (`F-close-approval-limitations`, spec `spec-run-12/spec.md`) closed the six
limitations that survived both earlier rounds, each with executed evidence: an architecture/schema
test for R39, R40 and R58, a functional test for the category move (R42), an integration test that
reads the approval transaction from a second connection and from a killed writer process (R38), a
migration for `customer_profile.email`, a schema-only approval migration with an operator backfill
command, and a handler-level reviews guard whose endpoint budget is pinned. Its results are the last
block under "Evidence" and `.agents/state/F-close-approval-limitations.md`. Every row below is PASS,
and the section that used to be "Remaining limitations" now reports each of the six with the artifact
that closed it and with what is still out of reach.

Legend:

- **PASS** — an executed test asserts the requirement (the suite is green).
- **PARTIAL** — the requirement is exercised, but the row names the part that no test asserts.
- **INSPECTED** — no test asserts it; verified by reading the handler, migration, ADR or SQL. This is
  weaker evidence and is listed again under "Remaining limitations". No row uses it after the third
  pass: where a judgement still belongs to a human, the row says so next to its passing evidence.

## R1–R19 — submission, ownership, Super Admin CRUD

| R | Requirement (short) | Evidence | Status |
|---|---|---|---|
| R1 | Product created by an Admin is `PENDING_APPROVAL` with the creator as submitter | `AdminCatalogTest::testGuestIsRefusedAndAStandardAdminOnlySubmitsForReview`; `AdminCatalogApprovalWorkflowTest::testASubmissionStartsPendingAndOnlyItsAuthorReadsIt` (`submittedBy` = owner) | PASS |
| R2 | Category created by an Admin is `PENDING_APPROVAL` with the creator as submitter | `AdminCatalogApprovalWorkflowTest::testTheSuperAdminDecidesAnotherAdminsSubmissionsAndTheDecisionIsStoredAndAudited` (a `ROLE_ADMIN` creates it, and approving it later only works from `PENDING_APPROVAL`); `testTheSuperAdminManagesASubmittedCategoryAndDeletionHonoursItsProducts` (`submittedBy` = the creating Admin). `AdminCategoryManagementTest::testAnAdminCreatesATopCategoryThatBothReadsReturn` shows the same pending→approved navigation behaviour (as Super Admin) | PASS |
| R3 | Author submits own record; submitting changes no status | `AdminCatalogApprovalWorkflowTest::testASubmissionStartsPendingAndOnlyItsAuthorReadsIt` | PASS |
| R4 | Author reads own status; foreign record answers not-found | same test (own 200, foreign 404, foreign queue empty) | PASS |
| R5 | Author reads the rejection reason | `AdminCatalogApprovalWorkflowTest::testTheSuperAdminDecidesAnotherAdminsSubmissionsAndTheDecisionIsStoredAndAudited` | PASS |
| R6 | Author edits own `PENDING_APPROVAL` under existing rules; it stays pending | Domain: `ApprovalStatusTest::testOnlyTheOwnerOfAPendingOrRejectedSubmissionMayEditIt`. Functional: `AdminCatalogApprovalWorkflowTest::testAnAuthorEditsTheirOwnPendingSubmissionWithoutLeavingReview` (author PUT over HTTP → still `PENDING_APPROVAL`, `submittedBy` unchanged, own approve 403, audit `[CREATED, UPDATED]`); the Super Admin path stays in `testTheSuperAdminCorrectsReadsAndDeletesAnotherAdminsSubmissionWithoutApprovingIt` | PASS |
| R7 | Author edits own `REJECTED` record; it stays rejected | `AdminCatalogApprovalWorkflowTest::testARejectedSubmissionStaysRejectedUntilItsAuthorResubmitsIt` | PASS |
| R8 | Resubmission returns to `PENDING_APPROVAL` and clears reviewer, time and reason | same test | PASS |
| R9 | Author cannot edit, resubmit, submit, publish or delete a foreign or approved record | `AdminCatalogApprovalWorkflowTest::testARegularAdminCannotDecidePublishOrTouchAForeignSubmission` (submit, PUT, DELETE, publication); `testARejectedSubmissionStaysRejectedUntilItsAuthorResubmitsIt` (submit on approved → 409) | PASS |
| R10 | Author cannot approve or reject, including own records | `AdminCatalogTest::testGuestIsRefusedAndAStandardAdminOnlySubmitsForReview` (own, 403); `testARegularAdminCannotDecidePublishOrTouchAForeignSubmission` (foreign, 403) | PASS |
| R11 | Approval metadata in a payload is ignored or refused, never persisted | `AdminCatalogTest::testGuestIsRefusedAndAStandardAdminOnlySubmitsForReview` (`approvalStatus`/`reviewedBy` smuggled → stored pending, reviewer null) | PASS |
| R12 | Super Admin creates product and category directly; the record is pending, never approved | `AdminCatalogTest::testSuperAdminCanCreateReadUpdateAndDeleteAProduct` (storefront 404 right after create); `AdminCategoryManagementTest::testAnAdminCreatesATopCategoryThatBothReadsReturn` | PASS |
| R13 | Super Admin enumerates and reads records in every state, with approval metadata | `AdminCatalogApprovalWorkflowTest::testTheSuperAdminDecidesAnotherAdminsSubmissionsAndTheDecisionIsStoredAndAudited` (queue + category queue), `testTheSuperAdminCorrectsReadsAndDeletesAnotherAdminsSubmissionWithoutApprovingIt` (detail before/after approve), `testARefusedDecisionWritesNeitherTheRowNorAnAuditEntry` (rejected). One listing over all three states: `testTheSuperAdminQueueListsEveryApprovalStateAtOnce` (three submissions sharing a prefix; approve one and reject one, then `?q=<prefix>&size=100` returns `meta.total` 3 with a slug→status map covering pending, approved and rejected) | PASS |
| R14 | Super Admin updates another Admin's record in any approval state | `testTheSuperAdminCorrectsReadsAndDeletesAnotherAdminsSubmissionWithoutApprovingIt` (pending, then approved); `testTheSuperAdminManagesASubmittedCategoryAndDeletionHonoursItsProducts` (pending category); `testTheSuperAdminEditsARejectedSubmissionAndItStaysRejected` (rejected: status and `Photos floues` retained, reviewer unchanged, audit `[CREATED, REJECTED, UPDATED]`) | PASS |
| R15 | Super Admin deletes a product | `testTheSuperAdminCorrectsReadsAndDeletesAnotherAdminsSubmissionWithoutApprovingIt` | PASS |
| R16 | Super Admin deletes a category when dependencies allow | `testTheSuperAdminManagesASubmittedCategoryAndDeletionHonoursItsProducts` | PASS |
| R17 | A refused delete changes nothing | same test (422 with the product present, row survives); `AdminCategoryManagementTest::testDeletingRefusesANonEmptyCategoryWithItsCountsAndAnswersNotFoundAfterwards` | PASS |
| R18 | Existing domain rules still apply to submissions and Super Admin edits | `AdminCatalogTest::testProductValidationRejectsBadInput`, `testSuperAdminCanCreateReadUpdateAndDeleteAProduct`; `AdminCategoryManagementTest::testAnInvalidLabelOrATakenSlugIsRefusedWithoutWritingARow`, `testSubLevelsObeyTheStoredTreeLimits`; `ProductTest`, `CategoryTest`, `CategorySubtreeTest` | PASS |
| R19 | Status is exactly `PENDING_APPROVAL`/`APPROVED`/`REJECTED` in storage and admin output | Domain: `ApprovalStatusTest::testTheThreeStatesAreTheOnlyOnesTheStorageCanCarry`, `testAnUnknownStoredStatusIsRefusedInsteadOfGuessed`. Storage: `ApprovalStatusConstraintTest` (`tests/Integration/Catalog`), which attempts the invalid `BOGUS` status against both tables — `testTheProductTableRefusesAnApprovalStatusTheWorkflowNeverWrites`, `testTheCategoryTableRefusesAnApprovalStatusTheWorkflowNeverWrites` — and proves the rejected statement leaves the stored row untouched (`testARefusedProductStatusLeavesTheStoredStatusUnchanged`, `testARefusedCategoryStatusLeavesTheStoredStatusUnchanged`) | PASS |

## R20–R34 — decisions, publication, authorization

| R | Requirement (short) | Evidence | Status |
|---|---|---|---|
| R20 | Approving a pending submission stores reviewer and decision time | `testTheSuperAdminDecidesAnotherAdminsSubmissionsAndTheDecisionIsStoredAndAudited` | PASS |
| R21 | Rejecting stores reviewer, time and reason | same test | PASS |
| R22 | Super Admin modification of an approved record reopens it and clears review metadata | `testTheSuperAdminCorrectsReadsAndDeletesAnotherAdminsSubmissionWithoutApprovingIt`; `AdminCatalogTest::testSuperAdminCanCreateReadUpdateAndDeleteAProduct` | PASS |
| R23 | Any transition outside the matrix is refused, writes nothing | Unit: `ApprovalStatusTest::testTheTransitionMatrix` (matrix provider). Functional: `testARefusedDecisionWritesNeitherTheRowNorAnAuditEntry` (409, stored status and reason unchanged, audit unchanged) | PASS |
| R24 | No create or update path produces `APPROVED`/`REJECTED` | `AdminCatalogTest::testGuestIsRefusedAndAStandardAdminOnlySubmitsForReview` (payload ignored), `testSuperAdminCanCreateReadUpdateAndDeleteAProduct` (update reopens); `testTheSuperAdminCorrectsReadsAndDeletesAnotherAdminsSubmissionWithoutApprovingIt` | PASS |
| R25 | Approval and publication are separate persisted values; approving never publishes | `AdminCatalogTest::testSuperAdminCanCreateReadUpdateAndDeleteAProduct` (approve → 404, publish → 200); `AdminCatalogApprovalWorkflowTest::testAPendingProductCannotBePublishedAndNoPublicPayloadCarriesApprovalData` | PASS |
| R26 | Approved product publishes and unpublishes explicitly; a non-approved product cannot publish | `testAPendingProductCannotBePublishedAndNoPublicPayloadCarriesApprovalData` (pending → 409) and the publish step in `AdminCatalogTest`; `testAPublishedProductLeavesTheStorefrontWhenItIsUnpublished` (approve → publish → storefront 200, unpublish → `published` false, storefront 404, status still `APPROVED`, audit `[CREATED, APPROVED, PUBLISHED, UNPUBLISHED]`) | PASS |
| R27 | Pending or rejected product is absent from every public read regardless of the flag | `testAPendingProductCannotBePublishedAndNoPublicPayloadCarriesApprovalData` (detail 404 while pending); `StorefrontApprovalVisibilityTest::testAPendingOrRejectedProductIsAbsentFromEveryPublicRead` (approved, pending and rejected rows in one category: browse via the index, browse via the SQL fallback `promotions=true`, `?q=` search and the detail route all return the approved row only; each SQL-fallback response is asserted to carry `facets: null`, which only `viaSql()` produces, so the fallback really served it; each hidden row's slug is searched for individually and must leave `meta.total` at 0, which is the only field that can expose a document the facet-filtered hits already drop); `testAnApprovedButUnpublishedProductAppearsOnlyOncePublished` (absent while unpublished, present after publication, without approving again, on both read paths); `testTheReviewsOfANonPublicProductAreNeitherServedNorAccepted` (the per-product review reads are bound to the same predicate: `GET`/`POST …/{slug}/reviews` answer 404 for a pending or an unpublished product and 200/201 for the public one, so a review cannot be read from nor attached to a row the storefront hides) | PASS |
| R28 | Approving a category publishes none of its products | `DecideCatalogSubmissionHandler` updates only `$command->kind->table()`; publication is `SetProductPublication` on `storefront_product_view`. `testApprovingACategoryPublishesNoneOfItsProducts` approves the category holding a product, asserts the product is still `published = false` and 404 on the storefront, that approving the product does not publish it either, and that the explicit publish step is what makes it reachable | PASS |
| R29 | Reachable only when the product is approved+published and its category approved+visible | `AdminCategoryManagementTest::testHidingACategoryDropsItAndItsProductsFromTheStorefrontButKeepsThemInTheAdmin` (hidden parent → 404); approval predicate in `CatalogVisibility::PRODUCT_PUBLIC`; `StorefrontApprovalVisibilityTest::testAProductOfAnUnapprovedCategoryIsPrivateAndTheCategoryIsNotNavigable` (an approved+published product of a `PENDING_APPROVAL` category — not hidden — is absent from browse and search, its detail 404, and the pending category is missing from the reference taxonomy while the approved sibling is present) | PASS |
| R30 | Same predicates on every public surface incl. the search index | `CatalogVisibility::PRODUCT_PUBLIC` is the single predicate at `StorefrontProductRepository` (lines 69, 188, 277) and `StorefrontProductSearchIndexer` (lines 65, 139), and both review handlers compose the same constant — `ListProductReviewsHandler` appends `CatalogVisibility::PRODUCT_PUBLIC` to its own statement, `SubmitProductReviewHandler` asks `ProductVisibilityPort` whose DBAL adapter applies it, and `StorefrontReviewController` only maps their refusal to 404 (QRY-004) — rather than restating it; positive coverage: `StorefrontCatalogBrowseTest`, `StorefrontTaxonomyTest`, `StorefrontProductSqlFallbackTest`, `StorefrontProductSearchIndexerTest`, `HttpApiContractTest::testProductsListResponseShape`/`testProductDetailResponseShape`. Negative coverage on the same surface: `StorefrontProductSearchIndexerTest::testIndexSlugOnlyIndexesAProductTheStorefrontCanSee` (six-case provider asserting the `DELETE` of the document for pending, rejected, unpublished, unapproved-category and hidden-category rows and the `POST` only for the public one) and `StorefrontApprovalVisibilityTest` (browse on both the index and SQL-fallback paths, search, detail, taxonomy) | PASS |
| R31 | Public responses expose no approval metadata | `testAPendingProductCannotBePublishedAndNoPublicPayloadCarriesApprovalData` (no `approval`/`approvalStatus` key; taxonomy keys are exactly `slug`,`label`,`subs`); `HttpApiContractTest::testProductDetailResponseShape` | PASS |
| R32 | Only a Super Admin approves or rejects, enforced server-side | `AdminCatalogTest::testGuestIsRefusedAndAStandardAdminOnlySubmitsForReview`; `testARegularAdminCannotDecidePublishOrTouchAForeignSubmission` | PASS |
| R33 | Every path reaching a product or category obeys the same rules | `AdminCatalogApprovalWorkflowTest::testARegularAdminCannotDecidePublishOrTouchAForeignSubmission` (six routes), `AdminCatalogTest` (legacy product routes), `AdminCategoryManagementTest::testGuestsGet401AndPlainCustomersGet403OnEveryAdminCategoryRoute` | PASS — no bulk endpoint exists, so the tested set is the whole surface |
| R34 | Ownership and role come from the principal and stored submitter, never the payload | foreign 404/403 in `testASubmissionStartsPendingAndOnlyItsAuthorReadsIt` and `testARegularAdminCannotDecidePublishOrTouchAForeignSubmission`; `SubmissionAccessPolicy` | PASS |

## R35–R42 — audit, schema, migration, integrity

| R | Requirement (short) | Evidence | Status |
|---|---|---|---|
| R35 | Each significant action writes exactly one audit entry | Exact audit arrays are asserted for create, submit, reject, update, resubmit, approve and delete in six of the eight workflow tests | PASS |
| R36 | Entry carries action, record, actor, timestamp; rejection carries the reason | `audit()` asserts action, `target_type`, `actor_id`, `reason` (including `Photo manquante`, `Prix incohérent`). `testEveryAuditEntryCarriesTheMomentOfTheActionItDescribes` selects `created_at`, parses it as a `DateTimeImmutable`, and asserts every entry of a create→approve→publish→unpublish trail falls between the moment the test started and the moment it finished, and never goes backwards | PASS |
| R37 | A refused action writes no entry and changes no row | `testARegularAdminCannotDecidePublishOrTouchAForeignSubmission` (audit stays at the creation row), `testARefusedDecisionWritesNeitherTheRowNorAnAuditEntry` (audit snapshot identical) | PASS |
| R38 | Audit write commits with the change it describes | Both statements run inside `Connection::transactional(...)` in every audited handler; the adapter shares the connection. Proven by `CatalogAuditTransactionTest`: `testAnApprovalWhoseAuditWriteFailsLeavesTheSubmissionPending` (the handler runs with an audit port that throws — the exception reaches the caller and the row is still `PENDING_APPROVAL` with `reviewed_by`/`reviewed_at` null, so the decision is not committed without its entry) and `testAnAuditEntryJoinsTheCallersTransactionAndDisappearsWhenItRollsBack` (an entry written through the adapter is visible inside the caller's transaction and gone after its rollback). Process level, `CatalogAuditProcessIsolationTest`: `testASecondConnectionSeesTheDecisionOnlyOnceTheTransactionCommits` runs the real handler inside `Connection::transactional()` and reads the same database from a second `DriverManager` connection — `PENDING_APPROVAL` and zero audit rows while the transaction is open, `APPROVED` and one entry after the commit; `testAWriterKilledBetweenItsUpdateAndCommitLeavesNeitherTheDecisionNorAnAuditEntry` starts a child PHP process that runs the same handler inside an explicit transaction, verifies both writes, writes a marker file and `SIGKILL`s itself before the `COMMIT` — the test waits for the marker and for the signal, then asserts the row is still `PENDING_APPROVAL`, `reviewed_by` is null and the trail empty, because PostgreSQL rolls the orphaned transaction back | PASS |
| R39 | Reuses the existing audit infrastructure | `CatalogApprovalDesignTest::testTheCatalogTrailReusesTheIdentityTrailShape` compares the live `catalog_audit_log` with `identity_audit_log` in `information_schema`: exactly seven columns, `id`/`created_at` with the identity trail's types, `action` with its `VARCHAR(64)`, `reason` the same free-text type (nullable here, because only a rejection carries one), `actor_id` with the same id type as `actor_admin_id`, `target_type` + `target_slug` as the documented adaptation, and `idx_catalog_audit_target`. `testNothingUnderSourceUpdatesOrDeletesTheCatalogTrail` scans `src/` for an `UPDATE`/`DELETE` against the table and finds none, which is the append-only convention ADR-0012 describes. The shape is read from the live schema, not from the migration text | PASS — the residual judgement is named under "Remaining limitations" |
| R40 | Only genuinely missing schema is added | `CatalogApprovalDesignTest::testTheWorkflowColumnsAreExactlyTheDocumentedSet` (a provider per table: the columns matching `/^(approval_\w*|approved_\w*|submitted_\w*|reviewed_\w*|rejection_\w*|published|hidden)$/` are exactly the documented ones, so an undocumented `approved_at` or `published_at` fails without the whitelist being edited), `testCategoriesKeepHiddenAsTheirPublicationFlagAndGainNoPublishedColumn` (categories have `hidden` and no `published`; products have `published`, and each table carries its `idx_*_approval` index), `testAnApprovalStatusIsMandatoryPendingByDefaultAndRangeConstrained` (VARCHAR(20), NOT NULL, default `PENDING_APPROVAL`, CHECK constraint present). That the audit table is this feature's, and only schema, is `MigrationRollbackTest::testRollingBackTheApprovalMigrationDropsItsSchemaAndKeepsTheCatalogueRows` (`down()` removes it and the columns) | PASS — the residual judgement is named under "Remaining limitations" |
| R41 | Migration is safe and reversible; legacy rows keep their visibility; a second run is a no-op | `Version20261023000000.php` is **schema only** (MIG-002): it adds the columns, `published` and `catalog_audit_log` and writes no row, so re-running it cannot re-approve anything. The data step is the operator command `app:catalog:backfill-approval` — dry run by default, `--apply` to write, selecting only rows that carry no workflow metadata at all. Run against dev and test it answered `Approved 0 product(s) and 0 category/categories` (the catalogue was already approved, which is also the idempotency proof); `BackfillCatalogApprovalCommandTest::testTheBackfillWritesNothingUntilItIsAppliedAndThenOnlyTouchesLegacyRows` proves the dry run changes nothing, the apply approves and publishes the legacy rows, a second apply reports 0, and the command's own selection is empty afterwards — on a database migrated from scratch by `MigrationProbeDatabase`. The release path runs it between the migrations and the reindex (`deploy/release.sh`, `make migrate`/`migrate-test`/`ci`, both CI jobs), and `docs/runbook-deployment.md` §3 documents it. Reversibility is executed against the throw-away `app_test__migration_probe`, never dev or test: `MigrationRollbackTest::testRollingBackTheApprovalMigrationDropsItsSchemaAndKeepsTheCatalogueRows` (`down()` drops the columns, the publication flag and the audit table while the catalogue rows, their slugs and names survive, and the app database is unchanged), `testMigratingUpAgainRestoresTheApprovalSchemaWithoutRepublishingWhatTheRollbackForgot` (after `down()`+`up()` a row that was `REJECTED` comes back `PENDING_APPROVAL` and unpublished, a category that was pending comes back pending, and nothing is re-approved or re-published), and `testNoMigrationOfTheApprovalFeatureWritesReadModelRows` (neither feature migration contains an `INSERT`/`UPDATE`/`DELETE` against `storefront_*` or `customer_profile`) | PASS — a rollback still discards approval and publication state; see "Remaining limitations" 3 |
| R42 | Product-category references stay consistent through create, update and delete | `AdminCatalogTest::testProductValidationRejectsBadInput` (unknown category → 422); `AdminCatalogApprovalWorkflowTest::testTheSuperAdminManagesASubmittedCategoryAndDeletionHonoursItsProducts`; `AdminCategoryManagementTest::testHidingAndDeletingNeverTouchTheOrderSnapshots`; and the move itself, `AdminProductCategoryMoveTest::testAMoveIsStoredAuditedOnceAndKeepsTheProductPrivateUntilItIsHomeAgain` — an approved, published product moved to a `PENDING_APPROVAL` category keeps the new `category_slug`, gains exactly one `CATALOG_PRODUCT_UPDATED` entry, reopens to `PENDING_APPROVAL`, and is absent from the storefront detail, from browse (index and SQL fallback) and from search even after it is re-approved, until it is moved home and approved again, when all three serve it again without republishing it. `testAMoveToAnUnknownCategoryIsRefusedAndWritesNeitherTheRowNorTheTrail` moves it to a slug no category carries: 422 `Unknown category`, stored category and status unchanged, no audit entry, the whole trail identical | PASS |

## R43–R59 — the prompt's test list, constraints and report

| R | Requirement (short) | Evidence | Status |
|---|---|---|---|
| R43 | Tests cover Admin product and category submission | `AdminCatalogTest`, `AdminCategoryManagementTest`, `AdminCatalogApprovalWorkflowTest`, `CategoryManagementTest` | PASS |
| R44 | Tests cover Super Admin CRUD on another Admin's submission | `AdminCatalogApprovalWorkflowTest` tests 2, 4 and 5 | PASS |
| R45 | Tests cover unauthorized CRUD and approval attempts | `testARegularAdminCannotDecidePublishOrTouchAForeignSubmission`; `AdminCatalogTest::testGuestIsRefusedAndAStandardAdminOnlySubmitsForReview`; `AdminCategoryManagementTest::testGuestsGet401AndPlainCustomersGet403OnEveryAdminCategoryRoute` | PASS |
| R46 | Tests cover explicit approval and rejection | `testTheSuperAdminDecidesAnotherAdminsSubmissionsAndTheDecisionIsStoredAndAudited` | PASS |
| R47 | Tests cover editing before approval | `testTheSuperAdminCorrectsReadsAndDeletesAnotherAdminsSubmissionWithoutApprovingIt`; `testARejectedSubmissionStaysRejectedUntilItsAuthorResubmitsIt` | PASS |
| R48 | Tests cover the reapproval rule for a modified approved record | `testTheSuperAdminCorrectsReadsAndDeletesAnotherAdminsSubmissionWithoutApprovingIt`; `AdminCatalogTest::testSuperAdminCanCreateReadUpdateAndDeleteAProduct` | PASS |
| R49 | Tests cover rejection reasons and resubmission | `testARejectedSubmissionStaysRejectedUntilItsAuthorResubmitsIt` | PASS |
| R50 | Tests cover deletion constraints and product-category dependencies | `testTheSuperAdminManagesASubmittedCategoryAndDeletionHonoursItsProducts`; `AdminCategoryManagementTest::testDeletingRefusesANonEmptyCategoryWithItsCountsAndAnswersNotFoundAfterwards`; `CategoryManagementTest::testDeletingCountsTheJsonNodesAndRefusesANonEmptyCategoryWithoutTouchingOrders` | PASS |
| R51 | Tests cover public API visibility restrictions | `testAPendingProductCannotBePublishedAndNoPublicPayloadCarriesApprovalData`; `AdminCategoryManagementTest::testHidingACategoryDropsItAndItsProductsFromTheStorefrontButKeepsThemInTheAdmin`; browse/search/contract suites; `StorefrontApprovalVisibilityTest` (pending, rejected, unpublished and unapproved-category rows across browse index/fallback, search, detail and taxonomy) | PASS |
| R52 | Tests cover bypass attempts through alternative endpoints | `testARegularAdminCannotDecidePublishOrTouchAForeignSubmission` (submit/approve/reject/publication/PUT/DELETE); `testARejectedSubmissionStaysRejectedUntilItsAuthorResubmitsIt` (resubmit on an approved record → 409); `AdminCatalogTest` (legacy routes, guest 401); `AdminCategoryManagementTest` (every category route) | PASS |
| R53 | Tests cover existing functionality and regressions in the touched areas | Full suite green: 671 tests, 3872 assertions (below; the gap-closing pass ran it four times, and the only red runs were the concurrent-task interference described in the isolation note); the count includes the unrelated uncommitted WhatsApp suite present in the working tree. The third pass re-ran it green at 710 tests, 4095 assertions (last block below) | PASS |
| R54 | Suites executed and actual output reported | Output quoted in "Evidence"; `.agents/state/F-super-admin-approval-workflow.md` | PASS |
| R55 | Static analysis and architecture checks pass | PHPStan `[OK] No errors`; Deptrac `0 violations / 0 errors / 0 warnings`; php-cs-fixer `0 of 469 files`. Third pass: PHPStan `[OK] No errors`, Deptrac `0 violations, 0 errors, 0 warnings`, php-cs-fixer `0 of 487 files` | PASS |
| R56 | No frontend path modified | `git status --porcelain` filtered for `Zidane_front_web`, `templates/`, `assets/` → none | PASS |
| R57 | No unrelated refactoring and no new dependency | This run wrote only `tests/Functional/AdminCatalogApprovalWorkflowTest.php`, `docs/adr/ADR-0012-catalog-audit-trail.md`, `spec-run-10/**` and `.agents/**`; `git diff --stat -- composer.json composer.lock` is empty. The 2026-10-24 gap-closing pass wrote `tests/**`, `spec-run-10/**`, `.agents/**` and one production file, `src/Catalog/Infrastructure/Http/StorefrontReviewController.php`; that is not refactoring but the guard its new test exposed as missing (reviews were served for and accepted on non-public products), and it reuses the detail route's query instead of adding a second predicate. No `composer.json`/`composer.lock` change accompanies it. The 2026-10-24 third pass wrote `tests/**`, `spec-run-10/**`, `.agents/**`, one migration and the production code its requirements named: `src/Identity/Application/EmailUniqueness.php` plus the conflict mapping in `RegisterCustomerHandler`, `LinkGoogleAccountHandler` and the profile handler, the visibility checks in `ListProductReviewsHandler` and `SubmitProductReviewHandler`, the removal of the controller-side guard in `StorefrontReviewController`, and `src/Catalog/Infrastructure/Console/BackfillCatalogApprovalCommand.php` (the data step taken out of the migration); one pre-existing test, `CatalogOutboxFlowTest`, also gained a backlog reset so a shared test database cannot hide its event, and `composer.json`/`composer.lock` are still untouched | PASS — the branch also carries unrelated uncommitted work from earlier tasks (WhatsApp contact, deployment env), untouched by this run |
| R58 | Create/update/delete and approval paths extended, not duplicated; no second approval mechanism | `CatalogApprovalDesignTest::testTheDecisionTypeIsAcceptedByOneCommandAndTurnedIntoAStatusByOneHandler`: `CatalogDecision` is named by exactly three files (the enum, the command, the controller that builds it) and `->targetStatus()` is called in exactly one handler, so nothing else under `src/` can write `APPROVED`/`REJECTED`. `testOneControllerDeclaresEveryDecisionRoute`: `/approve` and `/reject` are declared by `AdminCatalogSubmissionController` alone, once each, POST only. `AdminProductController` and `AdminCategoryController` stay extended, not forked — the checks are listed by name. `DecideCatalogSubmissionHandler` remains the only writer of `approval_status` | PASS — the residual judgement is named under "Remaining limitations" |
| R59 | Report lists changed files, migrations, executed results and remaining limitations | The final report for this run, plus this file | PASS |

## Remaining limitations

The eight evidence gaps this file opened are closed (see the statuses above), and so are the six the
approval-workflow report still carried. Each of the six is reported below with the artifact that
closed it; what follows the table is what no test and no migration can close, stated instead of
claimed.

| # | Limitation as reported | Closed by | Status |
|---|---|---|---|
| 1 | R39, R40 and R58 were INSPECTED — "design properties, not testable" | `tests/Architecture/CatalogApprovalDesignTest.php` (9 tests): one command, one handler and one controller for a decision; the workflow columns are exactly the documented set, `hidden` kept on categories; the trail has the identity trail's shape and is never updated or deleted | CLOSED — the judgement that remains is item 1 below (R39, R40, R58) |
| 2 | Moving a product to another category was only exercised through the update path | `tests/Functional/AdminProductCategoryMoveTest.php` (2 tests): stored `category_slug`, one audit entry, the public consequence on both sides, and the 422 refusal that writes nothing | CLOSED (R42) |
| 3 | The transactional proofs were statement-level | `tests/Integration/Catalog/CatalogAuditProcessIsolationTest.php` (2 tests) and `tests/Support/doomed_decision_writer.php`: a second connection, and a writer killed between its `UPDATE` and its `COMMIT` | CLOSED (R38) — the limit of the model is item 2 below |
| 4 | `customer_profile.email` had no unique index | `migrations/Version20261024000000.php` (`uniq_customer_profile_email_lower` on `lower(email)`), the violation mapped to the existing 409 in the identity handlers, `CustomerEmailUniquenessTest` (refusal, abort on duplicates, rollback restores the plain index, raced insert → conflict) and `CustomerProfileEmailConflictTest` | CLOSED — the operator limit is item 4 below |
| 5 | The approval migration was reversible in schema, not in state, and its backfill wrote the read model from a migration (MIG-002) | `Version20261023000000.php` is schema only; `app:catalog:backfill-approval` is the operator step (dry run by default, idempotent, wired into `release.sh`, `make migrate`/`migrate-test`/`ci` and both CI jobs); `MigrationRollbackTest` proves `down()`+`up()` re-approves and re-publishes nothing and that no feature migration writes read-model rows | CLOSED — the loss a rollback still causes is item 3 below |
| 6 | The reviews guard lived at the HTTP edge (QRY-004) and raised the endpoint from 2 to 3 statements, which no test pinned | `ListProductReviewsHandler` and `SubmitProductReviewHandler` authorize, both composing `CatalogVisibility::PRODUCT_PUBLIC` (directly, or through `ProductVisibilityPort` for the write); `StorefrontReviewController` only maps the refusal to 404; `StorefrontReviewEndpointBudgetTest` pins GET at 2 statements and POST at 2 per request | CLOSED |

### What is still out of reach, and why

1. **The judgement inside R39, R40 and R58 stays human.** The tests now executed prove the footprint,
   the shape and today's single writer; whether a different design would have needed less schema,
   whether one polymorphic audit table would have been the better reuse, and whether a future command
   is "a second mechanism" or a legitimate extension are review decisions. ADR-0012 records the
   alternatives that were rejected, and `CatalogApprovalDesignTest`'s docblock names the three.
2. **The crash is modelled, not witnessed.** R38's child process is killed with `SIGKILL` between the
   state `UPDATE` and the `COMMIT`, so what the test observes is PostgreSQL rolling an orphaned
   transaction back. A power loss, a severed connection or a `COMMIT` that reaches the server after
   the client has gone are different failures: the second connection proves visibility, the killed
   process proves the lost commit, and nothing covers a network partition.
3. **Rolling the feature back still discards state.** `down()` drops the columns that hold the
   approval status, the reviewer, the rejection reason and the publication flag, so no migration can
   restore them: a re-applied `up()` leaves every row `PENDING_APPROVAL` and unpublished, which is
   the safe direction and is now asserted. Recovering the decisions and the trail is a backup restore,
   as `docs/runbook-deployment.md` §4 states, and `app:catalog:backfill-approval` deliberately does
   not touch a row it did not approve.
4. **Two accounts that already share an address are not merged.** `Version20261024000000` aborts with
   the list of duplicated addresses and changes no row, because choosing which account keeps the
   address is an operator decision (runbook, "If the release stops on a duplicate e-mail"). The index
   is on `lower(email)`, so it refuses case variants but not Unicode look-alikes or surrounding
   spaces, which the identity lookups do not normalise either.

## Test-isolation note (for whoever runs the suite next)

A full-suite run is green (`710 tests, 4095 assertions`) when it is the only PHPUnit process touching
`app_test`. Several suites create and delete their own storefront rows and a few assert cardinalities
derived from the projection, so a run started while another one is still finishing will see the other
process's rows — the same coupling `spec-run-6/failures.md` records as V3-a/V3-b. Piping PHPUnit into
`head` causes exactly that: `head` exits, PHPUnit receives SIGPIPE mid-run and keeps writing tables.
Redirect to a file instead of piping when you need a truncated view.

The outbox is the second shared resource. `OutboxRelay::publishBatch()` relays the 50 oldest rows,
and `app_test` keeps the unpublished rows of every earlier run (a Functional test that writes through
the command bus and never relays, for instance), so after enough runs `CatalogOutboxFlowTest`'s own
event falls outside the batch and the test reads a read model that was never projected. That test now
closes the backlog (`UPDATE outbox SET published_at = now() WHERE published_at IS NULL`) before it
registers its product, which is also why it must not relay that backlog: dispatching those stale
events projects rows other suites have since removed. The third pass learned this the hard way — one
full-suite run relayed the whole backlog and reddened `MigrationRollbackTest` and
`AdminCategoryManagementTest` a second later.

## Evidence

### 2026-10-23 — original run (`F-super-admin-approval-workflow`)

```
$ docker compose exec -T app php bin/phpunit tests/Functional/AdminCatalogApprovalWorkflowTest.php
OK (8 tests, 101 assertions)

$ docker compose exec -T app php bin/phpunit
OK (632 tests, 3563 assertions)

$ docker compose exec -T app vendor/bin/phpstan analyse --no-progress
[OK] No errors

$ docker compose exec -T app vendor/bin/deptrac analyse --no-progress
[OK] 0 violations, 0 errors, 0 warnings

$ docker compose exec -T app vendor/bin/php-cs-fixer fix --dry-run
Found 0 of 457 files that can be fixed

$ docker compose exec -T app php bin/console doctrine:migrations:status --env=dev
Current: DoctrineMigrations\Version20261023000000 | Next: Already at latest version

$ docker compose exec -T app php bin/console doctrine:migrations:migrate -n --env=dev
[OK] Already at the latest version ("DoctrineMigrations\Version20261023000000")

$ docker compose exec -T database psql -U app -d app -tAc "SELECT ... FROM storefront_product_view"
products_total=13 approved_published=13 pending=0

$ docker compose exec -T database psql -U app -d app -tAc "SELECT ... FROM storefront_category"
categories_total=11 approved=11
```

### 2026-10-24 — gap-closing pass, before the review round (`F-approval-coverage-gaps`)

At this point the new suites asserted behaviour that was already shipped, and each test had been
written first: its first run against unchanged production code is the RED record in
`.agents/state/F-approval-coverage-gaps.md`. `StorefrontApprovalVisibilityTest` reports 3 tests here
because the fourth, the reviews test that found a real defect, was added after the review round;
the final state is the next block.

```
$ docker compose exec -T app php bin/phpunit tests/Functional/AdminCatalogApprovalWorkflowTest.php
OK (14 tests, 165 assertions)

$ docker compose exec -T app php bin/phpunit tests/Functional/StorefrontApprovalVisibilityTest.php
OK (3 tests, 41 assertions)

$ docker compose exec -T app php bin/phpunit tests/Projection/Catalog/StorefrontProductSearchIndexerTest.php
OK (10 tests, 41 assertions)

$ docker compose exec -T app php bin/phpunit tests/Integration/Catalog
OK (37 tests, 236 assertions)

$ docker compose exec -T app php bin/phpunit
OK (665 tests, 3777 assertions)

$ docker compose exec -T app vendor/bin/phpstan analyse --no-progress
[OK] No errors

$ docker compose exec -T app vendor/bin/deptrac analyse --no-progress
[OK] 0 violations, 0 errors, 0 warnings (Uncovered 1508 / Allowed 786)

$ docker compose exec -T app vendor/bin/php-cs-fixer fix --dry-run
Found 0 of 467 files that can be fixed
```

### 2026-10-24 — review round and the reviews defect (`F-approval-coverage-gaps`)

The review fan-out raised two defects that were checked against the code rather than accepted on
faith. The harness guard was dead code and was fixed inside this pass's own helper:
`MigrationProbeDatabase::assertDisposableName()` validated the probe name, which always carries the
`__migration_probe` suffix and so could never match, instead of the database the probe derives from.
The storefront one was real and is the single production change:

```
# RED — the new test, against unchanged code
$ docker compose exec -T app php bin/phpunit --filter testTheReviewsOfANonPublicProductAreNeitherServedNorAccepted
1) StorefrontApprovalVisibilityTest::testTheReviewsOfANonPublicProductAreNeitherServedNorAccepted
Failed asserting that 404 is identical to 200.
# body: {"slug":"vis-3","count":1,"averageRating":5.0,"items":[{"author":"Client vis-3",…}]}

# GREEN — after StorefrontReviewController resolves the slug through GetStorefrontProductBySlug
$ docker compose exec -T app php bin/phpunit tests/Functional/StorefrontApprovalVisibilityTest.php
OK (4 tests, 82 assertions)

$ docker compose exec -T app php bin/phpunit tests/Functional/StorefrontProductReviewsPaginationTest.php
OK (7 tests, 36 assertions)

$ docker compose exec -T app php bin/phpunit tests/Functional/AdminCatalogApprovalWorkflowTest.php
OK (14 tests, 165 assertions)

$ docker compose exec -T app php bin/phpunit tests/Projection/Catalog/StorefrontProductSearchIndexerTest.php
OK (10 tests, 41 assertions)

$ docker compose exec -T app php bin/phpunit tests/Integration/Catalog
OK (37 tests, 236 assertions)

$ docker compose exec -T app php bin/phpunit
OK (671 tests, 3872 assertions)

$ docker compose exec -T app vendor/bin/phpstan analyse --no-progress
[OK] No errors

$ docker compose exec -T app vendor/bin/deptrac analyse --no-progress
[OK] 0 violations, 0 errors, 0 warnings (Uncovered 1508 / Allowed 786)

$ docker compose exec -T app vendor/bin/php-cs-fixer fix --dry-run
Found 0 of 469 files that can be fixed
```

The reviews guard also invalidated one pre-existing expectation: `StorefrontProductReviewsPagination`
asserted that an unknown slug answers `200` with an empty page, which only held because reviews could
be attached to a slug no product matched. It now asserts that the reviews route answers like the detail
route (404), and its fixture creates the product the reviews belong to. Intermediate full-suite runs
reddened in `SessionCookieTest`, `AdminCustomerListQueryTest` and `AdminBannerTest` — files untouched
by this pass, whose mtimes fell 52 seconds before the run — because another task was writing the same
checkout (see the isolation note above and `spec-run-6/failures.md` V3-a/V3-b).

### 2026-10-24 — after the review fixes (`F-approval-coverage-gaps`)

The five-reviewer fan-out raised three changes that were made and re-measured; none touches production
except the reviews guard recorded above:

- `LegacyCatalogRow` no longer restates the column list of `storefront_product_view` / `storefront_category`: it
  delegates to `CatalogRow`, which now takes the column overrides a fixture needs (R58, extend not
  duplicate).
- `MigrationProbeDatabase::migrateToLatest()` became `migrateUpToTheApprovalMigration()` and targets
  `Version20261023000000` by version instead of the `latest` alias, so the rollback guard keeps holding once a
  newer migration exists instead of aborting a correct rollback.
- `StorefrontApprovalVisibilityTest` no longer rebuilds the search index for the reviews test, which reads
  PostgreSQL only — a full Meilisearch round trip removed (class runtime 1.719s → 0.794s).

The run was made while no other PHPUnit process was touching `app_test` (`pgrep` clear before and after); a
full-suite run taken in the middle of the round did red on `CategoryManagementTest` and
`MigrationRollbackTest` because a concurrent `--testsuite=Functional` process was inserting rows into the
shared test database — the same interference as above, not a regression.

```
$ docker compose exec -T app php bin/phpunit tests/Functional/StorefrontApprovalVisibilityTest.php
OK (4 tests, 82 assertions)

$ docker compose exec -T app php bin/phpunit tests/Functional/StorefrontProductReviewsPaginationTest.php
OK (7 tests, 36 assertions)

$ docker compose exec -T app php bin/phpunit tests/Functional/AdminCatalogApprovalWorkflowTest.php
OK (14 tests, 165 assertions)

$ docker compose exec -T app php bin/phpunit tests/Projection/Catalog/StorefrontProductSearchIndexerTest.php
OK (10 tests, 41 assertions)

$ docker compose exec -T app php bin/phpunit tests/Integration/Catalog
OK (37 tests, 236 assertions)

$ docker compose exec -T app php bin/phpunit
OK (671 tests, 3890 assertions)

$ docker compose exec -T app vendor/bin/phpstan analyse --no-progress
[OK] No errors

$ docker compose exec -T app vendor/bin/deptrac analyse --no-progress
Violations 0 | Skipped violations 0 | Warnings 0 | Errors 0
# 42 "Syntax Error on File" notes for typed class constants the parser does not support;
# the same 42 appear in the run taken before this pass changed anything.

$ docker compose exec -T app vendor/bin/php-cs-fixer fix --dry-run
Found 0 of 469 files that can be fixed
```

### 2026-10-24 — closing the six reported limitations (`F-close-approval-limitations`)

Every one of the six ends with an executed artifact. The counts below are the individual files; the
full suite and the three static gates after the last edit close the block.

```
$ docker compose exec -T app php bin/phpunit tests/Architecture/CatalogApprovalDesignTest.php
OK (9 tests, 50 assertions)                        # R39, R40, R58

$ docker compose exec -T app php bin/phpunit tests/Functional/AdminProductCategoryMoveTest.php
OK (2 tests, 96 assertions)                        # the move and its 422 refusal (R42)

$ docker compose exec -T app php bin/phpunit tests/Integration/Catalog/CatalogAuditProcessIsolationTest.php
OK (2 tests, 13 assertions)                        # second connection, killed writer (R38)

$ docker compose exec -T app php bin/phpunit tests/Integration/Identity/CustomerEmailUniquenessTest.php
OK (4 tests, 16 assertions)                        # unique lower(email), abort, rollback, race

$ docker compose exec -T app php bin/phpunit tests/Functional/CustomerProfileEmailConflictTest.php
OK (1 test, 5 assertions)                          # the violation answers 409, not 500

$ docker compose exec -T app php bin/phpunit tests/Integration/Catalog/MigrationRollbackTest.php
OK (3 tests, 22 assertions)                        # down/up re-approves nothing; schema only

$ docker compose exec -T app php bin/phpunit tests/Integration/Catalog/BackfillCatalogApprovalCommandTest.php
OK (1 test, 17 assertions)                         # dry run, apply, idempotent, fresh database

$ docker compose exec -T app php bin/phpunit tests/Functional/StorefrontReviewEndpointBudgetTest.php
OK (4 tests, 25 assertions)                        # GET 2 / POST 2 statements per request

$ docker compose exec -T app php bin/phpunit tests/Integration/Catalog/ProductReviewsHandlerBudgetTest.php
OK (3 tests, 14 assertions)                        # the handler budget the limitation left alone

$ docker compose exec -T app php bin/phpunit tests/Functional/StorefrontApprovalVisibilityTest.php
OK (4 tests, 82 assertions)                        # reviews of a hidden product answer 404

$ docker compose exec -T app php bin/phpunit tests/Functional/StorefrontProductReviewsPaginationTest.php
OK (7 tests, 36 assertions)                        # the public reviews contract is unchanged

$ docker compose exec -T app php bin/phpunit tests/Functional/AdminCatalogApprovalWorkflowTest.php
OK (14 tests, 165 assertions)

$ docker compose exec -T app php bin/console app:catalog:backfill-approval --env=dev
Dry run: 0 product(s) and 0 category/categories would be approved.
Nothing was written. Re-run with --apply to apply the backfill.

$ docker compose exec -T app php bin/console app:catalog:backfill-approval --apply --env=dev
Approved 0 product(s) (published) and 0 category/categories.

$ docker compose exec -T app php bin/console app:catalog:backfill-approval --apply --env=test
Approved 0 product(s) (published) and 0 category/categories.

$ docker compose exec -T app php bin/console doctrine:migrations:status --env=dev
Current: DoctrineMigrations\Version20261024000000 | Next: Already at latest version | New: 0

$ docker compose exec -T app php bin/phpunit
OK (710 tests, 4095 assertions)

$ docker compose exec -T app vendor/bin/phpstan analyse --no-progress
[OK] No errors

$ docker compose exec -T app vendor/bin/deptrac analyse --no-progress
Violations 0 | Skipped violations 0 | Uncovered 1552 | Allowed 797 | Warnings 0 | Errors 0

$ docker compose exec -T app vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.dist.php
Found 0 of 487 files that can be fixed
```

Three intermediate full-suite runs were red and none of them was a production defect. The first found
`CatalogOutboxFlowTest` depending on an outbox nobody drains across runs (that test now closes the
backlog, see the isolation note); the second relayed the whole backlog and reddened
`MigrationRollbackTest` and `AdminCategoryManagementTest`, because the stale events project rows other
suites had since removed; the third was green. PHPStan then reported two typing mistakes in new test
code (`getProfile()`'s nullable profile and `getmypid()`'s `int|false`), both narrowed in place, and
php-cs-fixer reformatted `CatalogAuditProcessIsolationTest` and `Version20261024000000`; the block
above is the run taken after those fixes.

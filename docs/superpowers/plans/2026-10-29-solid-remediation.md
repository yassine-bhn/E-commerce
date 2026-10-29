# SOLID Remediation and Enforcement — Implementation Plan

Date: 2026-10-29
Design: `docs/superpowers/specs/2026-10-29-solid-remediation-design.md`
Scope: `E-commerce/` only. Frontend (`Zidane_front_web`) is out of scope.
Branch: `feat/solid-remediation`, cut from the finished spec-run-15 commit.
Task state: `.agents/state/G-solid-remediation.md` (create before the first `src/` edit).

> **For the implementer:** this plan assumes you have not seen the codebase. Every task names
> the exact files, interfaces, tests and commands. Follow the task order; each phase is a gate.
> If the code has moved since the plan was written, re-read the named file before editing and
> keep the names/signatures in this plan stable.

## 0. Rules of execution

- Run every PHP/Composer command through Docker: `docker compose exec -T app …` (INF-002).
  Commands that need `git`, `rg` or the host shell are labeled "Host shell" in their block.
- Every production change starts with a RED record in `.agents/state/G-solid-remediation.md`:
  `RED: <test file> — "<assertion>" — cmd: <command> → FAIL`. The TDD gate is authoritative.
- Behavior-preserving refactor commits use the `refactor:` subject. New behavior commits use
  `feat:`. New tests/gates use `test:`. Docs use `docs:`. Chores use `chore:`.
- No allowlists, baselines, `@phpstan-ignore`, path exclusions, or legacy exceptions in the
  SOLID gates. The one historical exception is the WP7 migration guard required by
  spec-run-15 R33 (a frozen three-file allowlist); it belongs to the spec-run-15 feature and is
  not part of the SOLID gates.
- Do not edit tests to reach GREEN. If a test is wrong, record why in the state file and change
  it only when the production change is the intended one.
- Run `php-cs-fixer fix` on every touched file before committing.
- After each task: run the named tests, record GREEN, commit. Do not batch phases.

### Commit and branch sequence

`refactor:` (including `refactor(scope):`) is allowed only when the commit adds no concrete
class. A split or extraction that adds a concrete class uses `feat:`/`chore:` and behavior mode,
because the new class must be referenced by a changed test. This is not cosmetic: it is the
rule that keeps `refactor:` commits behavior-preserving.

```bash
# after Phase 0 is green
git switch -c feat/solid-remediation
# Phase 1
git commit -m "test(solid): add failing SOLID and TDD gates"
# Phase 2 (one commit per task; representative subjects below)
git commit -m "feat(cart): split the cart command handler"
git commit -m "feat(identity): split the profile, wishlist and profile-finder handlers"
git commit -m "feat(catalog): split the catalog submission store"
git commit -m "refactor(solid): replace match(true) with explicit transitions"
git commit -m "feat(ordering): add the order placement store"
git commit -m "chore(solid): correct the guard hooks"
# Phase 3 (one commit per context; ports and adapters are new classes)
git commit -m "feat(identity): invert persistence dependencies"
git commit -m "feat(catalog): invert persistence dependencies"
git commit -m "feat(ordering): invert persistence dependencies"
git commit -m "feat(payment,shared): invert persistence dependencies"
# Phase 4 (two commits per controller: feat for messages/handlers + tests,
# refactor for the controller; representative subjects below)
git commit -m "feat(http): add the admin commands and queries"
git commit -m "refactor(http): make every controller thin"
# Phase 5
git commit -m "test(solid): add repository and payment contracts; enable strict rules"
# Phase 6
git commit -m "docs(solid): record enforcement model and verify all gates"
```

### Corrections to the design inventory (verified 2026-10-29)

The design is the approved target; these factual corrections are recorded here so the
implementer does not chase stale counts:

1. **ISP-003 is 4 classes today.** `AdminCategoryController` (9 class-typed deps),
   `AdminProductController` (9), `CheckoutOrderController` (6) and `PlaceOrderHandler` (6)
   exceed five. The two admin controllers were 8 before WP3 added
   `CatalogSubmissionStore`; Phase 4 removes it. `CheckoutOrderController` is
   `MessengerQuery`, `CheckoutSaga`, `MessageBusInterface`, `RateLimiterFactoryInterface`,
   `RateLimitChecker`, `PlatformSettingsRepository`; Phase 4.1 deletes `CheckoutSaga` and
   moves settings behind `GetPaymentMethods`, ending at 4. Phase 2.7 reduces
   `PlaceOrderHandler` to 5.
2. **`CheckoutSaga` is deleted.** Design §8.5 describes a saga the controller keeps; design
   §8.2 and the approved decision delete it. The plan follows §8.2: the controller dispatches
   `PlaceOrder` directly and ends at 4 dependencies.
3. **WP6 `confirmedQuote` is mandatory.** R27 returns 409 when it is absent, so there is no
   `null` bypass. The quote carries `promoCode`, `deliveryMethod`, `subtotal`, `discount`,
   `delivery`, `total`, `currency` and the priced lines (R26/R27).
4. **WP7 has a spec-mandated allowlist.** R33 explicitly freezes the three historical
   migrations. This is a spec-run-15 historical guard, not a SOLID exemption; the SOLID gates
   still have no allowlist.
5. **TDD-005 is test-scoped.** `.agents/rules/tdd.md` scopes TDD-005 to `tests/**`; the guard's
   current `src/**` `new DateTime` branch is a false positive (35 findings). Removing that branch
   is a correction to match the rulebook, not a weakening.
6. **`OrderPlacementStore` combines claim + save.** This keeps `PlaceOrderHandler` at 5 deps
   while preserving `OrderRepository`; record the deviation from design §8.5 in ADR-0018.
7. **Handlers must not name Infrastructure.** `ProductSearchIndexer` becomes the Application
   port `ProductSearchIndexPort`; the existing `StorefrontProductSearchIndexer` implements it.
8. **Audit writers need the actor.** `AdminProductWriter` and `CategoryTreeWriter` take the
   actor id so the adapter can write the content audit without adding a handler dependency.
9. **PHPStan rule fixtures live outside analysed paths.** Put them under
   `.agents/phpstan/fixtures/` so the main PHPStan run does not need a path exclusion.
10. **The TDD refactor mode needs the commit subject.** Git's `pre-commit` hook cannot see the
    pending message; a `commit-msg` hook supplies it. The local hooks are split accordingly.
11. **The DIP-003 inventory is 39 classes; the remediation scope is 40.** WP3 already removed
    `Connection` from `SubmitCatalogSubmissionHandler`, `DecideCatalogSubmissionHandler` and
    `SubmissionAccessPolicy`. The audit found one more Application class, `EmailUniqueness`,
    which imports the DBAL `UniqueConstraintViolationException` without injecting `Connection`;
    the Application DBAL ban catches it too. It moves to
    `Identity\Infrastructure\Persistence\EmailUniqueness`, shared by the account and Google
    adapters. Appendix E maps all 40.
12. **TDD attribution for entry-point adapters.** Design §9.4 attaches a changed test to each
    changed class by short name/FQCN or same-context path. That cannot work for HTTP
    controllers: functional tests exercise routes through the kernel and never import the
    controller class. The gate therefore attributes `src/*/Infrastructure/Http/**` to a changed
    `tests/Functional/**` test, and `src/*/Infrastructure/ReadModel/**` to the same-context or
    functional tests. Domain, Application and Persistence keep the per-class rule, and new
    concrete classes always need a referencing test. This preserves the "no production change
    without a changed test" invariant without forcing artificial imports. ADR-0018 records it.
13. **ISP-001 is 8 methods today, not 7.** `CatalogSubmissionStore` gained `import()` after the
    design audit. The split is unchanged: `CatalogSubmissionCreator` owns `create` and `import`;
    the other five methods become five single-method ports, and `CatalogSubmissionRepository`
    keeps `find`.
14. **LAY-001 must also allow Doctrine collections.** `Order` has an ORM `OneToMany` mapped
    through `Doctrine\Common\Collections\Collection`/`ArrayCollection`; allowing only ORM
    mapping attributes and Symfony Uid would still leave two findings. The rule now allows
    `Doctrine\Common\Collections\` and nothing else from `Doctrine\Common`.

## Phase 0 — Finish spec-run-15 and get a green baseline (0A)

The current branch is mid-feature. The state file records WP1, WP2, WP4 and WP5a as done;
WP3 was dispatched and is in progress; WP6 and WP7 are not started. Do not start SOLID work
until all six suites, PHPStan and Deptrac are green on this base.

### Task 0.0 — Commit the approved design and this plan

**Files:** `docs/superpowers/specs/2026-10-29-solid-remediation-design.md`,
`docs/superpowers/plans/2026-10-29-solid-remediation.md`.

**Steps:**

1. `git add docs/superpowers` and commit:
   `docs(solid): record the approved design and implementation plan`.
2. Confirm `git status --short` no longer lists `docs/superpowers/`.

**Acceptance:** both documents are tracked; no `src/` or `tests/` file is in this commit.

### Task 0.1 — Commit the finished WP1/WP2/WP4/WP5a work

**Files:** the current working tree (review `git status` first).

**Steps:**

1. `git status --short`, `git diff --stat`, and `git diff` on every modified `src/` file.
2. Split the changes by hunk, not only by file: `AdminProductController`, `AdminCategoryController`
   and their functional tests carry both WP4 stock work and WP3 approval work. Use `git add -p`
   and stage only the WP4 hunks; leave every WP3-in-progress hunk unstaged for Task 0.2.
   The WP1/WP2/WP4/WP5a set is the approval store, inventory, money, migrations
   `Version20261029000001..000003`, ADRs 0014/0015/0017 and the related tests.
3. Run the suites that are expected green, and PHPStan:
   ```bash
   docker compose exec -T app vendor/bin/phpunit --testsuite=Unit
   docker compose exec -T app vendor/bin/phpunit --testsuite=Integration
   docker compose exec -T app vendor/bin/phpunit --testsuite=Projection
   docker compose exec -T app vendor/bin/phpunit --testsuite=Contract
   docker compose exec -T app vendor/bin/phpstan analyse --no-progress
   ```
4. Fix the 9 known PHPStan errors in the WP4/WP1 tests without weakening an assertion:
   - `tests/Integration/Inventory/StockReservationReleaseTest.php` — the fixture slug is
     `?string`; assert it is a string (or make the fixture return `string`) before calling
     `reserve()`. Do not cast `null` to `''`.
   - `tests/Projection/Catalog/ReplayCatalogSubmissionTest.php` and
     `tests/Projection/Inventory/ReplayInventoryStockTest.php` — the optional `missingView`
     offset; use `array_key_exists()`/`??` with an explicit array shape.
   Re-run PHPStan until it is 0. This is test typing, not behavior; record it in the state file.
5. Commit: `feat(spec-run-15): approval write model, inventory authority and millime money`.

**Acceptance:** Unit 334, Integration 106, Projection 37, Contract 16 green; PHPStan 0; no test
file modified only to hide a failure; `Functional` and `Architecture` are expected to stay red
only on the WP3/WP7-owned failures recorded in Appendix D.

### Task 0.2 — Finish WP3 (approval paths)

**Authority:** `spec-run-15/wp3-brief.md`. Read it fully before editing. It is the contract for
this task; the summary below only pins the order and the acceptance gate.

**Files (from the brief):** `ApprovalStatus.php` (docblocks), `CreateCategoryHandler`,
`ReplaceCategoryTreeHandler`, `AddCategorySubLevelHandler`, `SubmitCatalogSubmissionHandler`,
`DecideCatalogSubmissionHandler`, `SetProductPublicationHandler`, `DeleteCategoryHandler`,
`SubmissionAccessPolicy`, `CatalogSubmissionFinder`, approval portions of
`AdminProductController`/`AdminCategoryController`, `BackfillCatalogApprovalCommand`,
`CatalogSubmissionStore`/`DbalCatalogSubmissionStore` (`import` only),
`tests/Functional/AdminCatalogApprovalWorkflowTest.php`,
`tests/Functional/AdminCategoryManagementTest.php`,
`tests/Functional/AdminProductCategoryMoveTest.php`,
`tests/Architecture/CatalogApprovalDesignTest.php`,
`tests/Integration/Catalog/BackfillCatalogApprovalCommandTest.php`.

**Steps (one RED/GREEN cycle each):**

1. Add `CatalogSubmissionStore::import(CatalogSubmission $submission): bool`. RED: an
   integration test asserts a first import returns true, a second returns false and changes
   nothing, and no outbox/audit row is written. GREEN: `INSERT … ON CONFLICT DO NOTHING`.
2. Product and category creation: remove approval columns from the content INSERT; call
   `CatalogSubmissionStore::create()` after the row exists. RED: a created record is
   `PENDING_APPROVAL`, owned by its creator, with one CREATED audit entry and one create event.
3. Submit/resubmit/decide: delegate to the store; delete direct SQL, ownership checks and
   audit. RED: submit clears reviewer fields; only pending can be decided; approved cannot be
   resubmitted.
4. Reviewer save/owner edit for products and categories: content-only UPDATE, then the existing
   `CATALOG_*_UPDATED` audit, then `reviewerSave` (Super Admin) or `ownerModified` (regular
   Admin). RED: a reviewer save from pending/rejected approves and writes exactly one
   `CATALOG_SUBMISSION_APPROVED`; a second save writes none; audit order is content then
   approval.
5. Approval reads: `SubmissionAccessPolicy` uses `CatalogSubmissionRepository`; the finder
   joins `catalog_submission` to the content table for label/category/published;
   `SetProductPublicationHandler` reads the model status.
6. Deletion: `DeleteCategoryHandler` deletes the submission in the same transaction.
7. Update `CatalogVisibilitySingleSourceTest` only if the WP3 predicate change is intended;
   record the reason. Fix the two known failures it owns.

**Verification:**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Functional/AdminCatalogApprovalWorkflowTest.php
docker compose exec -T app vendor/bin/phpunit tests/Functional/AdminCategoryManagementTest.php
docker compose exec -T app vendor/bin/phpunit tests/Functional/AdminProductCategoryMoveTest.php
docker compose exec -T app vendor/bin/phpunit tests/Architecture/CatalogApprovalDesignTest.php
docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture
docker compose exec -T app vendor/bin/phpstan analyse --no-progress
```

**Acceptance:** WP3 Done-when row in `spec-run-15/spec.md` is satisfied; Architecture green;
PHPStan 0; Functional has only `AdminProductListTest:142` left for Task 0.4. The
`AdminCategoryManagementTest:499` failure is fixed by this task.

### Task 0.3 — Finish WP6 (checkout reconfirmation, backend only)

**Authority:** `spec-run-15/spec.md` WP6 row. Frontend work is explicitly out of scope; the
backend contract must be complete and tested.

**Files:** `Cart/Application/Dto/CartQuote.php`, `Cart/Infrastructure/ReadModel/QuoteCartHandler.php`,
`Cart/Infrastructure/ReadModel/StorefrontCartFinder.php`,
`Cart/Infrastructure/Http/CheckoutQuoteController.php`,
`Ordering/Application/Command/PlaceOrder.php`,
`Ordering/Application/CommandHandler/PlaceOrderHandler.php`,
`Ordering/Infrastructure/Http/CheckoutOrderController.php`,
`Ordering/Domain/Model/PriceChangedException.php`,
`tests/Functional/CheckoutPriceChangeTest.php`,
`tests/Unit/Ordering/PlaceOrderPriceChangeTest.php`,
`tests/Projection/Cart/StorefrontCartFinderTest.php`, and existing call sites in
`tests/Integration/Ordering/**` and `tests/Functional/**`.

**Steps:**

1. RED: the cart read returns per line the stored snapshot `unitPrice`, the current catalogue
   unit price and `priceChanged` (true when they differ). Keep the existing `unitPrice` and
   `lineTotal` fields; add `currentUnitPrice` and `priceChanged`. The quote endpoint returns
   `count`, `subtotal`, `discount`, `delivery`, `total`, `currency`, `promoCode`,
   `deliveryMethod` and `lines` (`slug`, `variant`, `quantity`, `unitPrice`). GREEN:
   `StorefrontCartFinder` compares the stored line price with the current variant price and
   `CartQuote` carries the priced lines from `StorefrontQuoteService`. The service key is
   `unitPriceMinor`; map it to the wire `unitPrice` and do not expose both.
2. RED: `PlaceOrder` accepts `?array $confirmedQuote = null` with the exact quote shape:
   `promoCode`, `deliveryMethod`, `subtotal`, `discount`, `delivery`, `total`, `currency` and
   `lines` (`slug`, `variant`, `quantity`, `unitPrice`). All amounts are integer minor units.
   The handler groups lines by slug and normalised variant, sorts them, and compares every
   field, including the wire `unitPrice` against the service's `unitPriceMinor`. A missing or
   different quote throws `PriceChangedException` carrying the current quote and the changed
   lines. GREEN: compare before claiming the idempotency key.
3. RED: a missing or stale quote returns 409
   `{error: "price-changed", reason: "confirmation-required"|"price-changed", quote: …,
   changedLines: […]}`; it creates no order, reservation, payment, audit or outbox row and
   leaves `processed_commands` untouched. GREEN: the controller maps `PriceChangedException`
   to 409 and the handler throws before `OrderPlacementStore::claim()`.
4. RED: resubmitting the current quote creates exactly one order with current snapshots; a
   retry with the same `Idempotency-Key` still creates one order; a later catalogue change does
   not alter the order. GREEN: accept the matching quote and keep the existing idempotency
   claim.
5. Update every `new PlaceOrder(...)` call site and fixture to pass the current quote built
   from the same `StorefrontQuoteService` values. Only the tests that assert the 409 pass
   `confirmedQuote: null` or an intentionally stale quote; there is no null bypass.

**Verification:**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Functional/CheckoutPriceChangeTest.php
docker compose exec -T app vendor/bin/phpunit tests/Unit/Ordering/PlaceOrderPriceChangeTest.php
docker compose exec -T app vendor/bin/phpunit tests/Projection/Cart/StorefrontCartFinderTest.php
docker compose exec -T app vendor/bin/phpunit --testsuite=Functional
```

### Task 0.4 — Finish WP7 (MIG-002 guard, docs, release, regression)

**Authority:** WP7 row and `spec-run-15/spec.md` decisions.

**Files:** `tests/Architecture/MigrationReadModelGuardTest.php`,
`docs/system-design/DATABASE-ARCHITECTURE.md`, `docs/runbook-deployment.md`,
`deploy/release.sh`.

**Steps:**

1. RED: `MigrationReadModelGuardTest` scans `migrations/*.php` for
   `INSERT INTO|UPDATE|DELETE FROM|TRUNCATE` against a `*_view` table or
   `storefront_category`. R33 mandates a frozen allowlist of exactly the three historical
   migrations: `Version20261017000002`, `Version20261018000000` and
   `Version20261029000003`. The test:
   - fails on a synthetic migration that writes only a view table;
   - passes the three allowlisted files;
   - fails if any allowlisted file disappears;
   - names the offending file and statement.
   GREEN: implement the parser with `nikic/php-parser` or a strict regex over `addSql`/
   `executeStatement` strings. The allowlist is a spec-run-15 historical exception and must
   not be reused by the SOLID gates.
2. Fix `AdminProductListTest:142`: diagnose whether the storefront read used a stale
   Meilisearch index or the SQL fallback; repair the test setup or projection without weakening
   the assertion. Record the cause in the state file.
3. Update the database architecture doc to list `catalog_submission`, `inventory_stock`, the
   three replay names and the projection ownership rules. Update the runbook/release script to
   run `app:catalog:backfill-approval --apply` and `app:inventory:backfill-stock --apply`
   after migrations and before app start.
4. Run the full regression:
   ```bash
   docker compose exec -T app vendor/bin/phpunit --testsuite=Unit
   docker compose exec -T app vendor/bin/phpunit --testsuite=Integration
   docker compose exec -T app vendor/bin/phpunit --testsuite=Projection
   docker compose exec -T app vendor/bin/phpunit --testsuite=Functional
   docker compose exec -T app vendor/bin/phpunit --testsuite=Contract
   docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture
   docker compose exec -T app vendor/bin/phpstan analyse --no-progress
   docker compose exec -T app vendor/bin/deptrac analyse --config-file=deptrac.yaml
   docker compose exec -T app vendor/bin/php-cs-fixer fix --dry-run --diff
   docker compose exec -T app bin/harness-check
   ```
5. Update `.agents/state/F-fix-six-high-audit-findings.md` with `GATE: DONE`.
6. Commit: `feat(spec-run-15): finish approval, inventory, checkout and projection work`.

**Acceptance:** all six suites green, PHPStan 0, Deptrac 0, cs-fixer clean, harness-check ok.
Record the exact counts in the state file. Tag the commit mentally as the SOLID base.

### Task 0.5 — Cut the remediation branch

```bash
git switch -c feat/solid-remediation
git rev-parse HEAD > .git/solid-base
cat .git/solid-base
```

`.git/solid-base` is the local base for every `bin/tdd-check --range` in this plan; record the
SHA in `.agents/state/G-solid-remediation.md`. CI uses `origin/<base-ref>...HEAD` instead
(Task 1.5).

Create `.agents/state/G-solid-remediation.md` from `.agents/state/TEMPLATE.md` with:
- task id, requirement, spec link, design link;
- `SOLID_BASE=<sha from .git/solid-base>`;
- baseline table (suite counts, PHPStan, Deptrac);
- empty RED/GREEN log.

## Phase 1 — Gates first (RED)

Every gate is written and run on the current code before any fix. Record the first failure
output as the RED for the rulebook. The gates are the specification of Phases 2–5.

### Task 1.1 — `tests/Architecture/SolidBoundariesTest.php`

**Files:** `tests/Architecture/SolidBoundariesTest.php`,
`tests/Architecture/Support/SourceTree.php` (small parser helper).

**Steps:**

1. Create the helper: parse every `src/**/*.php` with `PhpParser\ParserFactory`; expose
   `classes()`, `interfaces()`, `imports()`, `constructorParams()`, `methods()`,
   `attributes()`, `hasSqlCall()`.
2. Write these tests (data providers over the parsed tree; each failure names file + rule):
   - `it_keeps_application_free_of_forbidden_types` — no `Doctrine\DBAL\*`, `Doctrine\ORM\*`,
     `Doctrine\Persistence\*`, `Symfony\Component\HttpFoundation\*`, `App\*\Infrastructure\*`
     in `src/*/Application/**` imports or constructor/property/parameter types.
   - `it_keeps_controllers_thin` — controllers under `src/*/Infrastructure/Http/` may only
     construct with: `MessageBusInterface`, `MessengerQuery`, `RateLimiterFactoryInterface`,
     `RateLimitChecker`, `Security`, `LoggerInterface`, `ClockInterface`, scalars/arrays.
   - `it_allows_one_handler_per_class` — at most one `#[AsMessageHandler]` per class.
   - `it_limits_ports_to_three_methods` — interfaces in Domain/Application have ≤3 methods.
   - `it_limits_constructor_dependencies` — concrete services (`Controller`, `Handler`,
     `Subscriber`, `Adapter`, `Store`, `Repository`, `Finder`, `Indexer`, `Checker`, `Guard`,
     `Policy`, `Service`) have ≤5 class-typed constructor params.
   - `it_keeps_ports_in_inner_layers` — an interface used by Application lives in Domain or
     Application; its implementation lives in Infrastructure.
   - `it_forbids_service_location` — no `ContainerInterface`, `->get(` on a container,
     `static::getContainer()` in `src/` outside `Kernel`/compiler passes.
   - `it_forbids_match_true_in_application_and_domain` — no `match (true)` in
     Domain/Application (OCP-002).
   - `it_forbids_sql_in_http` — no exact DBAL calls `->fetch(`, `->fetchOne(`,
     `->fetchAllAssociative(`, `->fetchAssociative(`, `->fetchFirstColumn(`, `->fetchNumeric(`,
     `->executeStatement(`, `->beginTransaction(`, `->commit(`, `->rollBack(` in
     `src/*/Infrastructure/Http/**`. A bare `->fetch*` matches `GoogleOAuthClient::fetchUser()`
     and is not a SQL signal.
3. Run it. Record the RED counts per test (expected: 37 Application classes still importing
   `Doctrine\DBAL\*` at this point — 40 are in scope, but WP3 has already removed `Connection`
   from `SubmitCatalogSubmissionHandler`, `DecideCatalogSubmissionHandler` and
   `SubmissionAccessPolicy`; 12 thin controllers, 4 one-handler classes, 1 port over 3 methods,
   4 constructors over 5 deps (`AdminCategoryController` 9, `AdminProductController` 9,
   `CheckoutOrderController` 6, `PlaceOrderHandler` 6), 2 `match (true)`, 7 SQL controllers).
   Each rule is its own test method so Phases 2–4 can run the relevant method with
   `--filter`; the full test stays red until Phase 5.

**Verification:** `docker compose exec -T app vendor/bin/phpunit tests/Architecture/SolidBoundariesTest.php` → FAIL.

### Task 1.2 — PHPStan rule tests

**Files:** `tests/Unit/PhpStan/*RuleTest.php` and
`.agents/phpstan/fixtures/<Rule>/*.php` for:
`NoDoctrineInApplicationRule`, `ControllerThinRule`, `OneHandlerPerClassRule`,
`InterfaceMethodLimitRule`, `ConstructorDependencyLimitRule`, and the extended
`NoInfrastructureInHandlerRule`.

Fixtures live outside `src/` and `tests/` so the main PHPStan run does not need a path
exclusion. Create each rule as a no-op class skeleton (its `processNode()` returns `[]`) before
the test, so RED is the missing error, not a fatal "class not found".

Use `PHPStan\Testing\RuleTestCase`. Each test class:

```php
final class ControllerThinRuleTest extends RuleTestCase
{
    protected function getRule(): Rule { return new ControllerThinRule(); }

    public function test_it_reports_a_connection_in_a_controller(): void
    {
        $this->analyse([__DIR__.'/../../../.agents/phpstan/fixtures/ControllerThin/ControllerWithConnection.php'], [
            ['SRP-002: controller may not depend on Doctrine\DBAL\Connection.', 12],
        ]);
    }
}
```

Cases per rule:

| Rule | RED case | CLEAN case |
|---|---|---|
| `NoDoctrineInApplicationRule` | Application handler with `Connection` param | Application handler with a port; Infrastructure class with `Connection` |
| `ControllerThinRule` | controller with `Connection` / repository / read model | controller with bus + query + limiter + `Security` |
| `OneHandlerPerClassRule` | class with two `#[AsMessageHandler]` methods | one attribute |
| `InterfaceMethodLimitRule` | Application port with 4 methods | 3 methods; Infrastructure interface with 4 |
| `ConstructorDependencyLimitRule` | `*Handler` with 6 class-typed deps | 5 deps; 6 deps in a non-service class |
| `NoInfrastructureInHandlerRule` | `Connection`, `Doctrine\DBAL\*`, `HttpFoundation\*` in an Application handler | ports only; same types in Infrastructure |

Run each; each fails because the skeleton reports no error (or, for the extended rule, because
the current rule misses the type). Then implement in Task 1.3. Error messages use the form
`<RULE-ID>: <sentence>`; pin the exact sentence in the test. The PHPStan identifiers are
`agents.dip003.application`, `agents.srp002.controller`, `agents.srp001.oneHandler`,
`agents.isp001.methodLimit`, `agents.isp003.dependencyLimit` and
`agents.dip003.noInfrastructure`.

### Task 1.3 — Implement and register the PHPStan rules

**Files:** `.agents/phpstan/NoDoctrineInApplicationRule.php`,
`.agents/phpstan/ControllerThinRule.php`,
`.agents/phpstan/OneHandlerPerClassRule.php`,
`.agents/phpstan/InterfaceMethodLimitRule.php`,
`.agents/phpstan/ConstructorDependencyLimitRule.php`,
`.agents/phpstan/NoInfrastructureInHandlerRule.php` (extend),
`phpstan.neon`, `.agents/phpstan/README.md`, `bin/harness-check`.

**Rule contracts:**

1. `NoDoctrineInApplicationRule` (`Rule<Node>`): handle `Use_` nodes and class nodes.
   - Namespace must match `App\…\Application\…`.
   - Forbidden prefixes: `Doctrine\DBAL\`, `Doctrine\ORM\`, `Doctrine\Persistence\`,
     `Symfony\Component\HttpFoundation\`, `App\…\Infrastructure\`.
   - Check imports, constructor params, method params/returns, property types.
   - Error identifier `agents.dip003.application`.
2. `ControllerThinRule` (`Rule<InClassNode>`): class name ends `Controller`, namespace
   `App\…\Infrastructure\Http`. Allowed constructor types exactly:
   `Symfony\Component\Messenger\MessageBusInterface`,
   `App\Shared\Infrastructure\Http\MessengerQuery`,
   `Symfony\Component\RateLimiter\RateLimiterFactoryInterface`,
   `App\Shared\Infrastructure\Http\RateLimitChecker`,
   `Symfony\Bundle\SecurityBundle\Security`,
   `Psr\Log\LoggerInterface`,
   `Symfony\Component\Clock\ClockInterface`, scalars/arrays. Check constructor parameters only;
   `Request`/`JsonResponse` in action signatures are HTTP translation and stay allowed.
   Identifier `agents.srp002.controller`; message
   `SRP-002: controller may not depend on <type>.`
3. `OneHandlerPerClassRule` (`Rule<InClassNode>`): count method attributes named
   `AsMessageHandler`; >1 → error `agents.srp001.oneHandler`.
4. `InterfaceMethodLimitRule` (`Rule<InClassNode>`): interfaces in `App\…\Application\…` or
   `App\…\Domain\…` with >3 methods → `agents.isp001.methodLimit`.
5. `ConstructorDependencyLimitRule` (`Rule<InClassNode>`): service suffix list; count
   class-typed constructor params (exclude scalars, arrays, `mixed`, `callable`, `iterable`,
   `object`, `null`); >5 → `agents.isp003.dependencyLimit`.
6. Extend `NoInfrastructureInHandlerRule`: keep the existing handler check; add forbidden
   prefixes `Doctrine\DBAL\`, `Doctrine\Persistence\`, `Doctrine\ORM\`,
   `Symfony\Component\HttpFoundation\`; also inspect property types. Keep identifier
   `agents.dip003.noInfrastructure`.

Register each in `phpstan.neon` under `services:` with `phpstan.rules.rule`. Update
`.agents/phpstan/README.md` and the `phpstan_rules` array + `expected_phpstan_rules=10` in
`bin/harness-check`.

**Verification:** `docker compose exec -T app vendor/bin/phpunit tests/Unit/PhpStan` green;
`docker compose exec -T app vendor/bin/phpstan analyse --no-progress` still reports the
production violations (the gates are RED).

### Task 1.4 — `bin/solid-check` and `make solid`

**Files:** `bin/solid-check` (new, executable), `Makefile`.

`bin/solid-check` runs inside the app container (so `make solid` and CI use the same PHP 8.5):

```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

fast=0
if [ "${1:-}" = "--fast" ]; then fast=1; fi

if [ "$fast" -eq 0 ]; then
  php vendor/bin/phpunit --testsuite=Architecture
fi
php vendor/bin/phpstan analyse --no-progress
php vendor/bin/deptrac analyse --config-file=deptrac.yaml
mapfile -t files < <(find src -name '*.php' | sort)
bash .agents/hooks/guard-domain-imports.sh "${files[@]}"
bash .agents/hooks/guard-cqrs.sh "${files[@]}"
```

Add `solid` to `Makefile`:

```make
solid: ## Run the SOLID gates (architecture, PHPStan, Deptrac, guard hooks)
	$(EXEC) bin/solid-check
```

The local hook invokes it through Docker because the host does not run the project PHP:

```bash
docker compose exec -T app bin/solid-check --fast
```

`--fast` skips the Architecture suite (pre-commit has no migrated test DB); it still runs
PHPStan, Deptrac and both guard hooks. `chmod +x bin/solid-check`. Update `make help`.

**Verification:** `make solid` → FAIL; record the first 20 lines.

### Task 1.5 — `bin/tdd-check`, `make tdd`, pre-commit and the strengthened hook

**Files:** `bin/tdd-check` (new, executable), `Makefile`, `.githooks/pre-commit` (new),
`.githooks/commit-msg` (new), `.agents/hooks/tdd-gate.sh` (rewrite),
`tests/Architecture/TddHarnessTest.php`.

`bin/tdd-check` contract:

- Modes: `--staged`, `--range <base>...HEAD`, `--refactor`, `--staged --refactor`,
  `--message-file <path>`, `--self-test`.
- `--staged` uses `git diff --cached --name-status`; `--range` iterates every non-merge commit
  in the range and checks that commit's own diff:
  - if the subject matches `^refactor(\([^)]*\))?!?:`, run refactor mode for that commit;
  - otherwise run behavior mode for that commit;
  - aggregate failures with the short commit hash.
  This is required because the branch contains deliberate `refactor:` commits with no test
  change; a range-level behavior check would reject them.
- Behavior mode (default):
  - ignore `src/Kernel.php`;
  - deleted files (`D`) are ignored: there is no class left to cover; a rename (`R`) is treated as
    an add of the new path;
  - if `src/**/*.php` changed (added or modified) and no `tests/**/*.php` changed → fail;
  - **Domain, Application and Infrastructure/Persistence:** for each changed concrete class,
    derive FQCN + short name from the file's `namespace` and class declaration; pass when a
    changed test contains the short name/FQCN **or** lives under `tests/<Suite>/<Context>/` for
    the same context;
  - **new concrete class** (`A`) in Domain, Application or Persistence: always needs a changed
    test that references its short name; the context-only fallback is not enough. New HTTP
    controllers and ReadModel handlers use the layer rules below;
  - **new interface:** covered when a changed test references the interface or an
    implementation of it; the adapter test in the same context is sufficient. Phase 5 adds the
    LSP contract tests;
  - **HTTP controllers** (`src/*/Infrastructure/Http/**`): covered by a changed
    `tests/Functional/**` test. Functional tests exercise routes through the HTTP kernel and do
    not import controller classes; requiring a class-name reference would force artificial
    imports without adding safety;
  - **ReadModel query handlers** (`src/*/Infrastructure/ReadModel/**`): covered by a changed
    test that references the query class or by a changed test under
    `tests/<Suite>/<Context>/` or `tests/Functional/**`;
  - renamed files (`R`) are treated as an add of the new path.
- Refactor mode (`--refactor`):
  - with `--range`, the commit subject must match `^refactor(\([^)]*\))?!?:`; otherwise fail;
  - deleted files (`D`) are ignored; renames (`R`) are treated as an add of the new path;
  - no added **concrete** class. A new interface is allowed when an existing class already
    implements it and that implementation is covered by an existing test;
  - with `--staged`, the mode is a candidate check: no added concrete class and every changed
    Domain/Application/Persistence class already referenced by a test under `tests/`. HTTP
    controllers are covered by the existing functional suite for their context; ReadModel
    query handlers by the existing read-model tests. The subject is enforced by `commit-msg`;
  - pre-existing coverage is required; no artificial test edit is required.
- `--message-file <path>` reads the pending subject from the commit-msg file. It is used by the
  `commit-msg` hook to choose refactor mode when the subject matches the refactor pattern;
  otherwise it runs behavior mode.
- Output one line per failure: `TDD-CHECK: <file> — <reason>`; prefix the short commit hash in
  range mode; exit 1.
- `--self-test` creates temporary git repos with fixture files and runs the script's core
  function with each scenario, asserting pass/fail:
  1. src-only change → fail
  2. src+test referencing the class → pass
  3. new class + new test referencing it → pass
  4. new class + only a same-context test → fail
  5. refactor with pre-existing coverage + `refactor:` subject → pass
  6. refactor with pre-existing coverage + `refactor(solid):` subject → pass
  7. refactor diff with `feat:` subject → fail (behavior mode sees no test change)
  8. refactor adding a new concrete class → fail
  9. refactor adding only a new interface implemented by the existing adapter → pass
  10. refactor without coverage → fail
  11. `src/Kernel.php` only → pass
  12. range with one `feat:` and one `refactor:` commit → pass/fail per commit
  13. `--message-file` with `refactor(solid):` → refactor mode; with `feat:` → behavior mode
  14. HTTP controller change + changed functional test → pass
  15. HTTP controller change + no test change in refactor mode with existing functional suite → pass

Add to `Makefile`:

```make
tdd: ## Run the TDD gate, all suites and infection on changed Domain+Application files
	@base=$$(cat .git/solid-base 2>/dev/null || echo HEAD~1); \
	if git diff --cached --quiet; then \
		./bin/tdd-check --range "$$base...HEAD"; \
	else \
		./bin/tdd-check --staged; \
	fi
	$(EXEC) vendor/bin/phpunit --testsuite=Unit
	$(EXEC) vendor/bin/phpunit --testsuite=Integration
	$(EXEC) vendor/bin/phpunit --testsuite=Projection
	$(EXEC) vendor/bin/phpunit --testsuite=Functional
	$(EXEC) vendor/bin/phpunit --testsuite=Contract
	$(EXEC) vendor/bin/phpunit --testsuite=Architecture
	$(EXEC) vendor/bin/infection --threads=max --min-msi=85 --min-covered-msi=90
```

`bin/tdd-check` runs on the host before the container commands. For a staged refactor, run
`./bin/tdd-check --staged --refactor`; the commit-msg hook makes the final subject check.
Document that `make tdd` is authoritative for TDD and `make solid` for SOLID.

`.githooks/pre-commit` (fast, no DB) and `.githooks/commit-msg` (authoritative TDD check):

```bash
#!/usr/bin/env bash
# .githooks/pre-commit
set -euo pipefail
docker compose exec -T app bin/solid-check --fast
```

```bash
#!/usr/bin/env bash
# .githooks/commit-msg
set -euo pipefail
bin/tdd-check --staged --message-file "$1"
```

Git's `pre-commit` cannot see the pending message, so the subject-sensitive refactor mode must
run in `commit-msg`; this is the one mechanical deviation from design §9.4, recorded in
ADR-0018. `chmod +x` both hooks. Document `git config core.hooksPath .githooks` in `AGENTS.md`
and the plan's DoD.

Strengthen `.agents/hooks/tdd-gate.sh`:

- require `TASK_ID` and `.agents/state/<task-id>.md`;
- parse `RED: <path> — "<assertion>" — cmd: <command> → FAIL`;
- the path must match `tests/.*Test\.php`; when explicit file arguments are supplied (hook
  tests), it must exist and be one of them; otherwise it must be modified/added in the working
  tree or index;
- the assertion must be quoted, `cmd:` must be present, and the line must end `→ FAIL`;
- block otherwise with a specific TDD-001/TDD-002 message.

`tests/Architecture/TddHarnessTest.php` invokes the hook with `TASK_ID` and explicit file
arguments in a temp workspace (no git needed) and asserts pass/fail for: missing state file,
missing RED, bad path, unmodified test, valid RED.

**Verification:** `./bin/tdd-check --self-test` green;
`docker compose exec -T app vendor/bin/phpunit tests/Architecture/TddHarnessTest.php` green;
`./bin/tdd-check --range "$(cat .git/solid-base)...HEAD"` fails on the current branch and the
failure is recorded.

### Task 1.6 — CI `solid` and `tdd` jobs

**Files:** `.github/workflows/ci.yml`, `.github/workflows/tests.yml` if it lists jobs.

Add:

```yaml
solid:
  name: Solid gates
  runs-on: ubuntu-latest
  timeout-minutes: 30
  steps:
    - uses: actions/checkout@v4
    - run: docker compose build
    - run: docker compose up -d --wait app database meilisearch
    - run: docker compose exec -T app php bin/console doctrine:migrations:migrate -n
    - run: docker compose exec -T app php bin/console doctrine:migrations:migrate -n --env=test
    - run: make solid

tdd:
  name: TDD gate
  runs-on: ubuntu-latest
  timeout-minutes: 15
  steps:
    - uses: actions/checkout@v4
      with: { fetch-depth: 0 }
    - run: git fetch origin ${{ github.base_ref }} --depth=1
    - run: bin/tdd-check --self-test
    - run: |
        if [ "${{ github.event_name }}" = "pull_request" ]; then
          bin/tdd-check --range origin/${{ github.base_ref }}...HEAD
        elif [ "${{ github.event_name }}" = "push" ] \
          && [ -n "${{ github.event.before }}" ] \
          && [ "${{ github.event.before }}" != "0000000000000000000000000000000000000000" ]; then
          bin/tdd-check --range ${{ github.event.before }}...HEAD
        else
          bin/tdd-check --range HEAD~1...HEAD
        fi
```

`bin/tdd-check --range` checks each commit with its own subject, so `refactor:` and
`refactor(scope):` commits pass while a `feat:` commit without a test fails. The `solid` job runs
`make solid` in parallel with the test jobs and is required for merge. Do not make the test jobs
depend on it while the branch is red.

**Verification:** `docker compose exec -T app php -r 'yaml_parse_file(...)'` is not available;
use `docker compose exec -T app php bin/console lint:yaml .github/workflows/ci.yml` or a YAML
parser. The CI jobs are exercised by pushing the branch.

### Task 1.7 — Record the gate RED

Run `make solid`, `make tdd`, and the two shell self-tests. Record in
`.agents/state/G-solid-remediation.md`:
- the failing tests per rule;
- the counts (controllers, handlers, ports, constructors, `match (true)`, SQL controllers);
- the exact commands and first failure lines.

Commit: `test(solid): add failing SOLID and TDD gates`.

**Gate:** the gates exist, they fail, and the failures match the audit. No production fix yet.

## Phase 2 — Structural fixes (SRP-001, OCP-001, ISP-001, ISP-003, OCP-002)

Each task is behavior-preserving; the existing tests are the safety net. Use
`refactor(solid):` only for tasks that add no concrete class (2.6 and 2.8). Tasks that add
classes use `feat(solid):` and must add a test that references each new concrete class, because
the TDD gate's new-class rule is stricter than the context fallback. Run the named suite after
each task.

### Task 2.1 — Split `CartCommandHandler`

**Files:** `src/Cart/Application/CommandHandler/CartCommandHandler.php` (delete),
new `AddCartItemHandler.php`, `UpdateCartItemHandler.php`, `RemoveCartItemHandler.php`,
`ClearCartHandler.php`; new `src/Cart/Application/Service/CartLoader.php`,
`src/Cart/Application/Service/CartLinePricer.php`.

**Signatures:**

```php
final class CartLoader {
    public function __construct(private CartRepository $carts) {}
    public function loadOrCreate(string $customerId): Cart;
    public function require(string $customerId): Cart;
}
final class CartLinePricer {
    public function __construct(private StorefrontQuoteService $quotes) {}
    /** @return array{unitPriceMinor:int, currency:string} */
    public function price(string $slug, ?string $variant, int $quantity): array;
}
```

Handlers:
- `AddCartItemHandler(CartRepository, CartLoader, StorefrontCatalogPort, CartLinePricer)`.
- `UpdateCartItemHandler(CartRepository, CartLoader, CartLinePricer)`.
- `RemoveCartItemHandler(CartRepository, CartLoader)`.
- `ClearCartHandler(CartRepository)`.

Each has exactly one `#[AsMessageHandler(bus: 'command.bus')]` on `__invoke`. Move the existing
bodies verbatim; no behavior change.

**Tests:** existing `tests/Unit/Cart/*` and `tests/Functional/StorefrontCart*` must stay green.
Add `tests/Unit/Cart/AddCartItemHandlerTest.php`, `UpdateCartItemHandlerTest.php`,
`RemoveCartItemHandlerTest.php`, `ClearCartHandlerTest.php`; each instantiates its handler with
in-memory fakes and references `CartLoader`/`CartLinePricer` where used. Functional tests alone
do not name the new classes, so they cannot satisfy the gate. The architecture test's
one-handler rule is the RED.

### Task 2.2 — Split `CustomerProfileCommandHandler`

**Files:** delete `src/Identity/Application/CommandHandler/CustomerProfileCommandHandler.php`;
add `UpdateCustomerProfileHandler.php`, `SaveCustomerAddressHandler.php`,
`DeleteCustomerAddressHandler.php`; new ports `CustomerProfileStore`,
`CustomerAddressStore` and adapters (Task 3.1). For the split itself, keep the current
`Connection` temporarily if the ports are not ready; move the SQL in Task 3.1. The point of
this task is one message per class.

**Tests:** existing `tests/Functional/CustomerProfileTest.php` and address tests, plus
`tests/Integration/Identity/CustomerProfileHandlersTest.php` which dispatches each of the three
messages and references `UpdateCustomerProfileHandler`, `SaveCustomerAddressHandler` and
`DeleteCustomerAddressHandler`. The integration test is required because the split introduces
three concrete classes and no unit test names them.

### Task 2.3 — Split `WishlistCommandHandler`

**Files:** delete `src/Identity/Application/CommandHandler/WishlistCommandHandler.php`; add
`AddWishlistItemHandler.php`, `RemoveWishlistItemHandler.php`. For the split itself, keep the
current `Connection` product check; Task 3.1 introduces `ProductExistencePort` and its adapter,
and Phase 4.5 removes `StorefrontProductRepository` from `WishlistController` and maps
`ProductNotFoundException` to 404. The point of this task is one message per class.

**Tests:** existing wishlist functional tests, plus
`tests/Integration/Identity/WishlistHandlersTest.php` which dispatches both messages and
references `AddWishlistItemHandler` and `RemoveWishlistItemHandler`. The split introduces two
concrete classes; the functional tests do not name them.

### Task 2.4 — Split `CustomerProfileFinder`

**Files:** delete `src/Identity/Infrastructure/ReadModel/CustomerProfileFinder.php`; add
`GetCustomerProfileHandler.php`, `ListCustomerAddressesHandler.php`. The `escapeLike` helper
moves to the list handler (only user).

**Tests:** existing profile/address functional tests, plus
`tests/Integration/Identity/CustomerProfileFinderHandlersTest.php` which dispatches
`GetCustomerProfile` and `ListCustomerAddresses` and references `GetCustomerProfileHandler` and
`ListCustomerAddressesHandler`. The ReadModel attribution rule covers the handlers through the
changed integration test.

### Task 2.5 — Split `CatalogSubmissionStore` (ISP-001)

**Files:** `src/Catalog/Application/Port/CatalogSubmissionStore.php` (delete after migration),
new:
- `CatalogSubmissionCreator.php` — `create(kind, slug, actorId): CatalogSubmission`,
  `import(CatalogSubmission $submission): bool`
- `CatalogSubmissionSubmitter.php` — `submit(kind, slug, actorId, bool $actorIsSuperAdmin)`
- `CatalogSubmissionDecider.php` — `decide(kind, slug, CatalogDecision, reviewerId, ?reason)`
- `CatalogSubmissionReviewer.php` — `reviewerSave(kind, slug, actorId)`
- `CatalogSubmissionOwnerEditor.php` — `ownerModified(kind, slug)`
- `CatalogSubmissionDeleter.php` — `delete(kind, slug)`

`CatalogSubmissionRepository` keeps `find(kind, slug)`. `DbalCatalogSubmissionStore`
implements all six + the repository. Add aliases in `config/services.yaml`:

```yaml
App\Catalog\Application\Port\CatalogSubmissionCreator: '@App\Catalog\Infrastructure\Persistence\DbalCatalogSubmissionStore'
# …one alias per interface
App\Catalog\Domain\Repository\CatalogSubmissionRepository: '@App\Catalog\Infrastructure\Persistence\DbalCatalogSubmissionStore'
```

Update every WP3 call site to inject the narrow interface it uses. Keep
`Connection::transactional()` in the adapter and document the carve-out in ADR-0014.

**Tests:** extend `tests/Integration/Catalog/CatalogSubmissionStoreTest.php` with a
container-resolution test asserting each interface resolves to the same adapter; all existing
store tests stay green. RED: the new interfaces do not exist.

**Gate:** Architecture `it_limits_ports_to_three_methods` green.

### Task 2.6 — Replace `match (true)` (OCP-002)

**Files:** `src/Catalog/Domain/Model/ApprovalStatus.php`,
`src/Shared/Application/Service/ReadinessService.php`.

Rewrite:

```php
public function canMoveTo(self $target): bool
{
    if (self::PendingApproval === $this) {
        return self::Approved === $target || self::Rejected === $target;
    }
    if (self::Rejected === $this) {
        return self::PendingApproval === $target;
    }
    return self::PendingApproval === $target; // Approved
}
```

and:

```php
$status = 'ok';
if ($unavailable) { $status = 'unavailable'; }
elseif ($degraded) { $status = 'degraded'; }
```

**Tests:** existing `ApprovalStatusTest`, `ReadinessServiceTest`; Architecture OCP test green.

### Task 2.7 — `PlaceOrderHandler` → `OrderPlacementStore` (ISP-003)

**Files:** new `src/Ordering/Application/Port/OrderPlacementStore.php`,
`src/Ordering/Infrastructure/Persistence/DbalOrderPlacementStore.php`,
`src/Ordering/Application/CommandHandler/PlaceOrderHandler.php`.

Interface:

```php
interface OrderPlacementStore
{
    /** Returns false when the key was already used. */
    public function claim(string $key): bool;
    public function save(Order $order): void;
}
```

Adapter: `claim()` uses the existing `processed_commands` INSERT … ON CONFLICT DO NOTHING;
`save()` delegates to `OrderRepository::save()` (inject the repository). Handler deps become
`StorefrontQuoteService`, `StockReservationPort`, `PaymentInitiationPort`,
`OrderPlacementStore`, `OutboxPort` = 5.

**Tests:** new `tests/Integration/Ordering/OrderPlacementStoreTest.php` instantiating
`DbalOrderPlacementStore` and covering claim/duplicate-claim/save; existing
`PlaceOrderHandlerTest`, checkout integration tests. RED: the port does not exist; then GREEN.

### Task 2.8 — Correct the guard hooks

**Files:** `.agents/hooks/guard-cqrs.sh`, `.agents/hooks/guard-domain-imports.sh`,
`.agents/hooks/tdd-gate.sh` (Task 1.5), `.agents/hooks/hooks.yaml`, `.agents/rules/layering.md`,
`.agents/rules/tdd.md` if the enforcement line changes.

Corrections:

1. `guard-domain-imports.sh` — LAY-001: allow `Doctrine\ORM\Mapping` (mapping attributes,
   including `use Doctrine\ORM\Mapping as ORM;`), `Doctrine\Common\Collections\` (ORM to-many
   associations) and `Symfony\Component\Uid\` in `src/*/Domain/**`; ban every other `Doctrine\ORM\`,
   `Doctrine\DBAL`, `Doctrine\Persistence`, `HttpFoundation`, `Psr\`, `Meilisearch`, and
   `App\*\Infrastructure\*` / `App\*\Application\*` import. Update the rule text and ADR.
2. `guard-cqrs.sh` — remove the `match (true)` grep (the architecture test owns OCP-002).
3. `guard-cqrs.sh` — ECO-004: flag `->setStockQuantity(`/`->setReserved(` read-then-write
   patterns; do **not** flag `InventoryStockPort::setAvailable()`, which is the atomic port.
4. `guard-cqrs.sh` — TDD-005: enforce it exactly as scoped in `.agents/rules/tdd.md`, on
   `tests/**` only. Remove the `src/**` branch (35 `new DateTime` false positives). Production
   clock injection is a separate concern and is not part of this remediation; record the
   correction in ADR-0018.
5. `guard-cqrs.sh` — ERR-003: keep the `catch (Throwable)` check; the AdminBrand catch is
   removed in Phase 4.
6. `guard-cqrs.sh` — CMD-003: for `src/*/Application/CommandHandler/*Handler.php`, require exactly
   one `#[AsMessageHandler(bus: 'command.bus')]` and require `__invoke(...): void`. Do not extend
   this check to query handlers: they live in `Infrastructure/ReadModel`, may be named
   `handleX`, and return DTOs. The architecture test owns the one-handler-per-class rule; this
   hook is the fast local check. Before the Phase 2 splits the three multi-message command
   handlers fail this check, which is the intended RED.
7. `.agents/hooks/pre-finish.sh` — add `bin/solid-check` to the required commands. Do not add a
   git-dependent `bin/tdd-check` call to the container hook (the app image has no git); the
   `commit-msg` hook and the CI `tdd` job are the TDD gate. Record this in ADR-0018.

Run the corrected hooks over all `src`:
```bash
mapfile -t files < <(find src -name '*.php')
bash .agents/hooks/guard-domain-imports.sh "${files[@]}"
bash .agents/hooks/guard-cqrs.sh "${files[@]}"
```
The remaining `guard-domain-imports` findings must be only the Phase 3 Application DBAL
imports. `guard-cqrs` must be clean. Record the counts.

### Task 2.9 — Phase 2 gate

```bash
docker compose exec -T app vendor/bin/phpunit tests/Architecture/SolidBoundariesTest.php --filter 'it_allows_one_handler_per_class|it_limits_ports_to_three_methods|it_forbids_match_true_in_application_and_domain'
docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture
docker compose exec -T app vendor/bin/phpstan analyse --no-progress
```

Expected: the filtered SRP-001/OCP-001/ISP-001/OCP-002 methods are green. The full Architecture
suite still fails only on `it_keeps_controllers_thin` (the Phase 4 controllers), ISP-003 (the
two admin controllers and `CheckoutOrderController`; `PlaceOrderHandler` is now 5) and DIP-003
(Phase 3). Record the exact remaining failures. Commit.

## Phase 3 — DIP-003 and the Application DBAL ban (40 classes)

Order: Identity → Catalog → Ordering → Payment → Shared. For each context:

1. Write the adapter test first (RED: class/interface missing).
2. Add the interfaces, DTOs and adapter.
3. Move the SQL verbatim from the Application class to the adapter.
4. Replace `Connection` in the handler/service with the port(s).
5. Add `config/services.yaml` aliases.
6. Run the context's unit/integration/functional tests + the Architecture suite.
7. Commit.

The tables below are the complete mapping. Method signatures are the contract; do not rename
them without updating the plan and the tests. Every adapter test constructs its concrete `Dbal*`
adapter (or `StorefrontProductSearchIndexAdapter`) directly, so the TDD gate's new-class rule
finds the short name in a changed test.

### 3.1 Identity (14 classes → 16 handlers after the splits)

**Ports** (`App\Identity\Application\Port` unless noted):

| Port | Methods | Adapter | Used by |
|---|---|---|---|
| `CustomerAccountReader` | `find(string $id): ?CustomerRecord`; `activeSuperAdminCount(): int` | `DbalCustomerAccountAdapter` | Ban, ChangeRole, Delete |
| `CustomerDeletionReader` | `orderCount(string $customerId): int` | `DbalCustomerAccountAdapter` | Delete |
| `CustomerStatusStore` | `ban(string $id, string $reason, string $adminId): void`; `unban(string $id): void` | `DbalCustomerAccountAdapter` | Ban, Unban |
| `CustomerRoleStore` | `changeRole(string $id, string $role): void` | `DbalCustomerAccountAdapter` | ChangeRole |
| `CustomerDeletionWriter` | `anonymize(string $id, string $anonEmail): void`; `hardDelete(string $id): void` | `DbalCustomerAccountAdapter` | Delete |
| `CustomerRegistrar` | `emailExists(string $email): bool`; `register(NewCustomer $customer): void` | `DbalCustomerAccountAdapter` | Register |
| `CustomerProfileStore` | `upsert(CustomerProfileData $profile): void` | `DbalCustomerAccountAdapter` | UpdateProfile |
| `CustomerAddressStore` | `save(AddressData $address): void`; `delete(string $id, string $customerId): void` | `DbalCustomerAddressStore` | Save/DeleteAddress |
| `GoogleAccountLinker` | `isLinked(string $googleId): bool`; `linkByEmail(string $email, string $googleId): bool`; `register(string $id, string $name, string $email, string $googleId): void` | `DbalGoogleAccountLinker` | LinkGoogle |
| `PasswordResetStore` | `customerByEmail(string $email): ?array{id:string,email:string}`; `updatePassword(string $customerId, string $passwordHash): void` | `DbalPasswordResetStore` | Request/Reset |
| `PasswordResetTokenStore` | `invalidateActive(string $customerId): void`; `issue(string $customerId, string $tokenHash): void`; `consume(string $tokenHash): ?string` | `DbalPasswordResetStore` | Request/Reset |
| `ContactMessageStore` | `store(string $id, string $name, string $email, ?string $phone, string $subject, string $message): void` | `DbalIdentityIntakeStore` | Contact |
| `QuoteRequestStore` | `store(QuoteRequestData $data): void` | `DbalIdentityIntakeStore` | QuoteRequest |
| `NewsletterStore` | `subscribe(string $id, string $email): void` | `DbalIdentityIntakeStore` | Newsletter |
| `WishlistStore` | `add(string $id, string $customerId, string $slug): void`; `remove(string $customerId, string $slug): void` | `DbalWishlistStore` | Wishlist |
| `IdentityAuditPort` | `record(string $action, string $targetUserId, string $actorAdminId, ?string $reason = null): void` | `DbalIdentityAuditPort` | Ban, Unban, ChangeRole, Delete |

`App\Shared\Application\Port\ProductExistencePort`: `exists(string $slug): bool`; implemented by
the existing `App\Catalog\Infrastructure\ReadModel\StorefrontProductRepository` (add
`exists()` delegating to `bySlug()`), aliased to the port. No new adapter class; the existing
repository test covers it. Used by `AddWishlistItemHandler`.

**DTOs** (`App\Identity\Application\Dto`): `CustomerRecord` (id, email, role, status,
googleId), `NewCustomer` (id, name, email, phone, accountType, company, mf, passwordHash),
`CustomerProfileData` (id, name, email, phone, accountType, company, mf), `AddressData` (id,
customerId, name, phone, street, city, governorate, zip), `QuoteRequestData` (quoteId, userId,
fullName, email, phone, city, category, subcategories, message).

**Handler mapping:**

| Handler | Constructor after migration |
|---|---|
| `BanCustomerHandler` | `CustomerAccountReader`, `CustomerStatusStore`, `IdentityAuditPort` |
| `ChangeCustomerRoleHandler` | `CustomerAccountReader`, `CustomerRoleStore`, `IdentityAuditPort` |
| `DeleteCustomerWithAnonymizationHandler` | `CustomerAccountReader`, `CustomerDeletionReader`, `CustomerDeletionWriter`, `IdentityAuditPort` |
| `LinkGoogleAccountHandler` | `GoogleAccountLinker` |
| `RegisterCustomerHandler` | `CustomerRegistrar` |
| `RequestPasswordResetHandler` | `PasswordResetStore`, `PasswordResetTokenStore`, `MailerInterface`, `LoggerInterface`, string, string |
| `ResetPasswordHandler` | `PasswordResetTokenStore`, `PasswordResetStore` |
| `SubmitContactMessageHandler` | `ContactMessageStore` |
| `SubmitQuoteRequestHandler` | `QuoteRequestStore`, `StorefrontCategoryPort` |
| `SubscribeNewsletterHandler` | `NewsletterStore` |
| `UnbanCustomerHandler` | `CustomerStatusStore`, `IdentityAuditPort` |
| `UpdateCustomerProfileHandler` | `CustomerProfileStore` |
| `SaveCustomerAddressHandler` | `CustomerAddressStore` |
| `DeleteCustomerAddressHandler` | `CustomerAddressStore` |
| `AddWishlistItemHandler` | `WishlistStore`, `ProductExistencePort` |
| `RemoveWishlistItemHandler` | `WishlistStore` |

Adapters catch `UniqueConstraintViolationException` on email insert/update and throw
`EmailAlreadyUsedException` (preserve the current messages). Move
`src/Identity/Application/EmailUniqueness.php` to
`src/Identity/Infrastructure/Persistence/EmailUniqueness.php` unchanged; both
`DbalCustomerAccountAdapter` and `DbalGoogleAccountLinker` use its `INDEX` constant and
`isEmailCollision()`. `CustomerRegistrar::register` and `CustomerProfileStore::upsert` throw the
domain exception, while `GoogleAccountLinker::register` catches an e-mail collision and returns
silently, preserving the current "the next sign-in links it" behavior. `RegisterCustomerHandler`,
`LinkGoogleAccountHandler` and `UpdateCustomerProfileHandler` lose their `catch` blocks and the
Application layer has no DBAL import left. `PasswordResetTokenStore::consume`
uses one `UPDATE … SET used_at = NOW() WHERE token_hash = :hash AND used_at IS NULL AND
expires_at > NOW() RETURNING customer_id`; null means invalid token.

**Tests:** `tests/Integration/Identity/CustomerAccountAdapterTest.php` (find/missing, count,
ban/unban, changeRole, anonymize/hardDelete, register duplicate, profile duplicate),
`GoogleAccountLinkerTest.php`, `PasswordResetStoreTest.php`, `IdentityIntakeStoreTest.php`,
`CustomerAddressStoreTest.php`, `WishlistStoreTest.php`, `IdentityAuditPortTest.php`.
Each test starts RED because the adapter does not exist. Handler unit tests use in-memory
fakes (TDD-009); add them where no functional test covers the handler.
`CustomerEmailUniquenessTest` now builds `DbalCustomerAccountAdapter` over
`UnseeingEmailCheckConnection` and imports the moved
`App\Identity\Infrastructure\Persistence\EmailUniqueness` for its index assertion.

**Context gate:**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Unit/Identity tests/Integration/Identity
docker compose exec -T app vendor/bin/phpunit --testsuite=Functional --filter 'Customer|Wishlist|Google|Password|Newsletter|Contact|Quote'
docker compose exec -T app vendor/bin/phpstan analyse src/Identity --no-progress
```

The full Architecture suite still sees the other contexts' DBAL imports; run it once at
Task 3.6.

### 3.2 Catalog (17 classes)

**Ports** (`App\Catalog\Application\Port` unless noted):

| Port | Methods | Adapter | Used by |
|---|---|---|---|
| `CategoryReader` | `find(string $slug): ?Category`; `usedSubLevels(string $slug): list<string>` | `DbalCategoryStore` | AddSubLevel, ReplaceTree, Delete, Hide, Show |
| `CategoryCreator` | `nextSortOrder(): int`; `create(Category $category, string $actorId): void` | `DbalCategoryStore` | CreateCategory |
| `CategoryTreeWriter` | `replaceContent(Category $category, string $actorId): void`; `addSubLevel(Category $category, string $actorId): void` | `DbalCategoryStore` | ReplaceTree, AddSubLevel |
| `CategoryVisibilityWriter` | `setHidden(string $slug, bool $hidden): void` | `DbalCategoryStore` | Hide, Show |
| `CategoryDeletionReader` | `counts(string $slug): array{children:int, products:int}` | `DbalCategoryStore` | DeleteCategory |
| `CategoryDeleter` | `delete(string $slug): void` | `DbalCategoryStore` | DeleteCategory |
| `CategoryHierarchyReader` | `parentMap(): array<string, ?string>` | `DbalCategoryStore` | ValidateParent |
| `ProductPublicationStore` | `status(string $slug): ?ApprovalStatus`; `setPublished(string $slug, bool $published): void` | `DbalAdminProductStore` | SetPublication |
| `ProductReviewStore` | `store(string $id, string $slug, string $authorName, int $rating, string $comment): void` | `DbalAdminProductStore` | SubmitReview |
| `AdminProductWriter` | `create(ProductDraft $draft, string $actorId): void`; `update(string $slug, ProductDraft $draft, string $actorId): void`; `delete(string $slug, string $actorId): void` | `DbalAdminProductStore` | Create/Update/DeleteProduct |
| `ProductDeletionReader` | `exists(string $slug): bool`; `orderCount(string $slug): int` | `DbalAdminProductStore` | DeleteProduct |
| `BannerSetReader` | `current(): BannerSet` | `DbalBannerStore` | Banner handlers |
| `BannerImageStore` | `replace(BannerSet $set): void`; `reorder(BannerSet $set): void`; `remove(string $imageId): void` | `DbalBannerStore` | Banner handlers |

`App\Shared\Application\Port\ProductSearchIndexPort`: `sync(string $slug): void`;
`forget(string $slug): void`; adapter
`App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexAdapter` wraps
`StorefrontProductSearchIndexer`. Phase 4 handlers inject the port, never the Infrastructure
indexer.

Pure services (not ports): `App\Catalog\Application\Service\CategoryHierarchy` with
`assertAcyclic(string $categorySlug, ?string $targetParentSlug, array $parentMap): void`;
`App\Catalog\Application\Service\BannerPayload` with `imagePathsFrom(mixed $body): list<string>`
and `imageIdsFrom(mixed $body): list<string>`. `BannerRules` and
`CategoryHierarchyValidator` are deleted. `ProductDeletionGuard` is replaced by
`ProductDeletionReader` + `DeleteProductHandler`.

`SubmissionAccessPolicy` becomes:
```php
public function __construct(private readonly CatalogSubmissionRepository $submissions) {}
```
and keeps `authorize(kind, slug, ?actorId, bool $actorIsSuperAdmin): ApprovalStatus`, reading
the model instead of SQL.

`ProductDraft` (`App\Catalog\Application\Dto`) fields: `slug`, `name`, `brand`, `ref`,
`category`, `sub`, `priceMinor`, `oldPriceMinor`, `stock`, `stockQuantity`, `material`, `dims`,
`image`, `isNew`, `best`, `headrest`, `description`, `variants` (list of
`{label:string, priceMinor:int}`).

**Handler mapping:**

| Handler/service | Constructor after migration |
|---|---|
| `CreateCategoryHandler` | `CategoryCreator`, `CatalogSubmissionCreator` |
| `ReplaceCategoryTreeHandler` | `CategoryReader`, `CategoryTreeWriter`, `SubmissionAccessPolicy`, `CatalogSubmissionReviewer`, `CatalogSubmissionOwnerEditor` (5; the writer adapter records `CATALOG_CATEGORY_UPDATED` with the actor id) |
| `AddCategorySubLevelHandler` | `CategoryReader`, `CategoryTreeWriter`, `SubmissionAccessPolicy`, `CatalogSubmissionReviewer`, `CatalogSubmissionOwnerEditor` (5) |
| `DeleteCategoryHandler` | `CategoryReader`, `CategoryDeletionReader`, `CategoryDeleter`, `CatalogSubmissionDeleter`, `CatalogAuditPort` (5) |
| `HideCategoryHandler` | `SubmissionAccessPolicy`, `CategoryReader`, `CategoryVisibilityWriter` |
| `ShowCategoryHandler` | `SubmissionAccessPolicy`, `CategoryReader`, `CategoryVisibilityWriter` |
| `SubmitCatalogSubmissionHandler` | `CatalogSubmissionSubmitter` |
| `DecideCatalogSubmissionHandler` | `CatalogSubmissionDecider` |
| `SetProductPublicationHandler` | `ProductPublicationStore`, `CatalogSubmissionRepository`, `CatalogAuditPort`, `ProductSearchIndexPort` |
| `SubmitProductReviewHandler` | `ProductVisibilityPort`, `ProductReviewStore` |
| `BannerReplaceImagesHandler` | `BannerPayload`, `BannerImageStore` |
| `BannerReorderImagesHandler` | `BannerPayload`, `BannerSetReader`, `BannerImageStore` |
| `BannerRemoveImageHandler` | `BannerSetReader`, `BannerImageStore` |

For `ReplaceCategoryTreeHandler`/`AddCategorySubLevelHandler`, the audit/content write order
stays: content write → `CATALOG_CATEGORY_UPDATED` (inside the writer adapter, which receives the
actor id) → reviewer/owner transition (the store writes the approval audit). The writer adapter
injects `CatalogAuditPort`; the handlers stay at 5.

`SetProductPublicationHandler` now owns the post-command search refresh: after the flag write and
audit it calls `ProductSearchIndexPort::sync($slug)`, because `AdminProductController` loses the
`StorefrontProductSearchIndexer` dependency. Four dependencies, still under the cap.

**Tests:** `tests/Integration/Catalog/CategoryStoreAdapterTest.php`,
`AdminProductStoreAdapterTest.php`, `BannerStoreAdapterTest.php`, plus unit tests for
`CategoryHierarchy` (self-parent, descendant cycle, corrupt-loop safeguard) and `BannerPayload`
(invalid body, non-list, non-string). `tests/Unit/Catalog/StorefrontProductSearchIndexAdapterTest.php`
wraps a fake indexer and asserts `sync`/`forget` delegate. Integration tests use the real DB and
assert the exact SQL effects (same columns, same order, same audit rows).

**Context gate:**

```bash
docker compose exec -T app vendor/bin/phpunit --testsuite=Functional --filter 'Catalog|Category|Product|Banner|Brand'
docker compose exec -T app vendor/bin/phpstan analyse src/Catalog --no-progress
```

The full Architecture suite still sees the other contexts' DBAL imports; run it once at
Task 3.6.

### 3.3 Ordering (5 classes)

| Port | Methods | Adapter | Used by |
|---|---|---|---|
| `OrderStateReader` | `find(string $orderId): ?OrderState` | `DbalOrderStateAdapter` | Transition, AutoCancel |
| `OrderTransitionStore` | `transition(string $orderId, int $expectedVersion, OrderStatus $target, int $newVersion): bool`; `appendAudit(string $orderId, OrderStatus $from, OrderStatus $to, int $fromVersion, int $toVersion, string $changedBy, string $reason): void` | `DbalOrderStateAdapter` | Transition |
| `OrderCustomerReader` | `find(string $orderId): ?OrderCustomer` | `DbalOrderStateAdapter` | CancelByCustomer |
| `AdminProxyOrderStore` | `create(AdminProxyOrderData $data): void` | `DbalAdminProxyOrderStore` | CreateAdminProxy |
| `OrderPlacementStore` | `claim(string $key): bool`; `save(Order $order): void` | `DbalOrderPlacementStore` | PlaceOrder (delivered in Task 2.7; this phase only verifies the alias/wiring) |

DTOs: `OrderState` (status `OrderStatus`, version int), `OrderCustomer` (customerId string,
status `OrderStatus`), `AdminProxyOrderData` (all proxy fields + quote totals + lines).

Handler mapping:
- `TransitionOrderStatusHandler`: `OrderStateReader`, `OrderTransitionStore`.
- `AutoCancelOrderHandler`: `OrderStateReader`, `MessageBusInterface`, `StockReservationPort`.
- `CancelOrderByCustomerHandler`: `OrderCustomerReader`, `MessageBusInterface`,
  `StockReservationPort`.
- `CreateAdminProxyOrderHandler`: `AdminProxyOrderStore`, `StorefrontQuoteService`,
  `StockReservationPort`, `PaymentInitiationPort` (4).
- `PlaceOrderHandler`: `StorefrontQuoteService`, `StockReservationPort`,
  `PaymentInitiationPort`, `OrderPlacementStore`, `OutboxPort` (5).

**Tests:** `tests/Integration/Ordering/OrderStateAdapterTest.php`,
`AdminProxyOrderStoreTest.php`, `OrderPlacementStoreTest.php`; existing ordering functional
tests.

**Context gate:**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Integration/Ordering
docker compose exec -T app vendor/bin/phpunit --testsuite=Functional --filter 'Order|Checkout'
docker compose exec -T app vendor/bin/phpstan analyse src/Ordering --no-progress
```

### 3.4 Payment (2 classes)

| Port | Methods | Adapter | Used by |
|---|---|---|---|
| `PaymentStateReader` | `find(string $paymentId): ?PaymentSnapshot` | `DbalPaymentStateAdapter` | Confirm, Refund |

`PaymentSnapshot` DTO: `orderId`, `status` (`PaymentStatus`), `version`, `amountMinor`,
`currency`.

Handler mapping: `ConfirmOfflinePaymentHandler` and `RefundPaymentHandler` replace `Connection`
with `PaymentStateReader`; keep `PaymentLedger`, `OrderSettlementPort`, `OutboxPort`.

**Tests:** `tests/Integration/Payment/PaymentStateAdapterTest.php` (found/missing, exact
version/status/amount); existing payment functional tests.

**Context gate:**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Integration/Payment
docker compose exec -T app vendor/bin/phpunit --testsuite=Functional --filter 'Payment|Refund|Confirm'
docker compose exec -T app vendor/bin/phpstan analyse src/Payment --no-progress
```

### 3.5 Shared (2 classes)

| Port | Methods | Adapter | Used by |
|---|---|---|---|
| `PlatformSettingsWriter` | `save(string $phone, string $whatsapp, int $autoCancelMinutes, array $paymentMethods): void` | `DbalPlatformSettingsWriter` | UpdatePlatformSettings |
| `ShippingPolicyWriter` | `save(int $feeMinor, int $thresholdMinor): void` | `DbalShippingPolicyWriter` | UpdateShippingPolicy |

**Tests:** `tests/Integration/Shared/PlatformSettingsWriterTest.php`,
`ShippingPolicyWriterTest.php` (insert + update + JSON encoding).

**Context gate:**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Integration/Shared
docker compose exec -T app vendor/bin/phpunit --testsuite=Functional --filter 'Settings|Shipping'
docker compose exec -T app vendor/bin/phpstan analyse src/Shared --no-progress
```

### Task 3.6 — Zero-Connection gate

Host shell:

```bash
if grep -rln 'Doctrine\\DBAL\\Connection' src --include='*.php' | grep '/Application/'; then
  echo 'DIP-003: Application class still imports Doctrine\DBAL\Connection' >&2
  exit 1
fi
```

Container:

```bash
docker compose exec -T app vendor/bin/phpstan analyse --no-progress
docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture
```

Acceptance: no Application class imports `Doctrine\DBAL\*` or `App\*\Infrastructure\*`;
Architecture `it_keeps_application_free_of_forbidden_types` green; PHPStan green.

## Phase 4 — SRP-002 controllers (12 controllers)

Each task: add the command/query + handler + tests first (RED), migrate the controller, delete
the direct SQL, then run the controller's functional tests. The controller ends with only the
allowed constructor types. Do not change response shapes/status codes.

### 4.1 `CheckoutOrderController`

- Delete `src/Ordering/Application/Saga/CheckoutSaga.php` and its test if it only wraps
  `commandBus->dispatch`.
- Dispatch `PlaceOrder` directly.
- Add `App\Shared\Application\Query\GetPaymentMethods` (no fields) + handler in
  `Shared\Infrastructure\ReadModel\GetPaymentMethodsHandler` using
  `PlatformSettingsRepository::paymentMethods()`.
- Constructor: `MessengerQuery`, `MessageBusInterface`, `RateLimiterFactoryInterface`,
  `RateLimitChecker`.
- Keep the 409 price-changed mapping from WP6.

**Tests:** existing `CheckoutPayment*`, `CheckoutStock*`, `CustomerCancelOrderTest`; new unit
test for `GetPaymentMethodsHandler` that constructs `GetPaymentMethods` and uses a fake
repository.

### 4.2 `GoogleOAuthController`

- Add queries: `GetGoogleAuthorizationUrl(string $state): ?string`,
  `GetGoogleUser(string $code): ?GoogleUser` (`GoogleUser` DTO: googleId, email, name),
  `GetLoginUser(string $email): CustomerUser`.
- Handlers in `Identity\Infrastructure\ReadModel` use `GoogleOAuthClient` and
  `CustomerProfileUserProvider`.
- `GetGoogleAuthorizationUrl`/`GetGoogleUser` return null when the client is not configured.
  `GetGoogleUser` throws `GoogleOAuthException` on exchange failure.
- Controller: on null → 503; on `GoogleOAuthException` → redirect error; dispatch
  `LinkGoogleAccount`; dispatch `GetLoginUser`; `$security->login(...)`.
- Constructor: `MessageBusInterface`, `MessengerQuery`, `Security`, string.

**Tests:** existing Google OAuth functional tests; add a query-handler test that references
`GetGoogleAuthorizationUrl`, `GetGoogleUser` and `GetLoginUser` and covers the not-configured and
exchange-failure paths.

### 4.3 `AdminSettingsController`

- Replace `PlatformSettingsRepository` with `GetPlatformSettings` (read) and
  `GetPaymentMethods` (current allow-list during update).
- Constructor: `MessengerQuery`, `MessageBusInterface`.

**Tests:** `AdminSettingsTest`, `AdminShippingPolicyTest`.

### 4.4 `AdminBannerController`

- Remove `BannerRules`.
- Commands carry `mixed $body` for replace/reorder; handlers use `BannerPayload` to validate
  and throw `BannerRejectedException` (controller maps 422).
- Constructor: `MessageBusInterface`, `MessengerQuery`.

**Tests:** `AdminCatalogTest` banner cases, `CatalogApprovalDesignTest` if it asserts banner
dependencies.

### 4.5 `WishlistController`

- Remove `StorefrontProductRepository`.
- `AddWishlistItemHandler` uses `ProductExistencePort` and throws
  `ProductNotFoundException`; controller maps 404.
- Constructor: `MessageBusInterface`, `MessengerQuery`.

**Tests:** wishlist functional tests.

### 4.6 `AdminCustomerController`

- Add `GetCustomerAuditTrail(string $customerId)` + DBAL handler returning the same rows.
- Remove `Connection`.
- Constructor: `MessageBusInterface`, `MessengerQuery`.

**Tests:** `AdminCustomerManagementTest` audit route, including one direct
`MessengerQuery` dispatch of `GetCustomerAuditTrail` so the new query class is referenced by a
changed test.

### 4.7 `AdminOrderController`

- Add `GetOrderAuditTrail(string $orderId)` + DBAL handler.
- Remove `Connection`.
- Constructor: `MessageBusInterface`, `MessengerQuery`, `RateLimiterFactoryInterface`,
  `RateLimitChecker`.

**Tests:** `AdminOrderAuditTest` / existing order functional tests, including one direct
`MessengerQuery` dispatch of `GetOrderAuditTrail` so the new query class is referenced by a
changed test.

### 4.8 `AdminPaymentController`

- Add `GetPaymentState(string $paymentId)` + DBAL handler returning
  `{paymentId,status,version,orderStatus}` (unknown fallback preserved).
- Replace `state()` with the query; remove `Connection`.
- Constructor: `MessageBusInterface`, `RateLimiterFactoryInterface`, `RateLimitChecker`,
  `MessengerQuery`.

**Tests:** `AdminPaymentReadTest`, `ConfirmOfflinePaymentTest`, `RefundPaymentTest`, including
one direct `MessengerQuery` dispatch of `GetPaymentState` so the new query class is referenced
by a changed test.

### 4.9 `AdminBrandController`

- Add ports `BrandReader` (`exists`, `countProducts`) and `BrandStore`
  (`create`, `rename` returning `list<string>` affected slugs, `delete`).
- Commands: `CreateBrand(string $name)`, `RenameBrand(string $old, string $new)`,
  `DeleteBrand(string $name)`.
- Handlers: create checks `exists` and maps duplicate to `BrandAlreadyExistsException` (409);
  rename checks old exists and new clash, renames both tables in one transaction, then syncs
  each returned slug through `ProductSearchIndexPort::sync`; delete refuses when
  `countProducts > 0` with `BrandNotEmptyException` (422) and maps missing to
  `BrandNotFoundException` (404).
- Handler deps: `CreateBrandHandler` (`BrandReader`, `BrandStore`), `RenameBrandHandler`
  (`BrandReader`, `BrandStore`, `ProductSearchIndexPort`), `DeleteBrandHandler`
  (`BrandReader`, `BrandStore`).
- Remove the `catch (Throwable)`; the command-bus transaction replaces the manual
  begin/commit/rollBack.
- Constructor: `MessageBusInterface`, `MessengerQuery`.

**Tests:** existing `AdminCatalogTest` brand cases; new `BrandStore` integration test, plus
unit tests for `CreateBrandHandler`, `RenameBrandHandler` and `DeleteBrandHandler` with fake
`BrandReader`/`BrandStore`/`ProductSearchIndexPort` (the functional test never names the
handlers, so the TDD new-class rule needs them).

### 4.10 `AdminPromoCodeController`

- Add `PromoCodeReader` (`exists`, `find`) and `PromoCodeStore` (`create`, `update`, `delete`).
- Commands: `CreatePromoCode`, `UpdatePromoCode`, `DeletePromoCode`.
- `UpdatePromoCode` merges only the provided fields; handler reads current via the reader.
- Duplicate create → `PromoCodeAlreadyExistsException` (409); missing update/delete →
  `PromoCodeNotFoundException` (404).
- Constructor: `MessageBusInterface`, `MessengerQuery`.

**Tests:** existing promo functional tests; new `PromoCodeStore` integration test, plus unit
tests for `CreatePromoCodeHandler`, `UpdatePromoCodeHandler` and `DeletePromoCodeHandler` with
fake `PromoCodeReader`/`PromoCodeStore`.

### 4.11 `AdminProductController`

- Add commands `CreateProduct(ProductDraft, string $actorId)`,
  `UpdateProduct(string $slug, ProductDraft, string $actorId, bool $actorIsSuperAdmin)` and
  `DeleteProduct(string $slug, string $actorId)`; query
  `GetAdminProduct(string $slug, string $requesterId, bool $isAdmin): ?array`.
- `CreateProductHandler`: `CategoryReader` taxonomy check (throws
  `InvalidProductTaxonomyException` with the current message → 422),
  `AdminProductWriter::create`, `CatalogSubmissionCreator::create`, `ProductSearchIndexPort`.
  Duplicate slug → `ProductAlreadyExistsException` (409).
- `UpdateProductHandler`: `SubmissionAccessPolicy`, `AdminProductWriter::update`,
  `CatalogSubmissionReviewer`, `CatalogSubmissionOwnerEditor`, `ProductSearchIndexPort` (5).
  The writer adapter records `CATALOG_PRODUCT_UPDATED`.
- `DeleteProductHandler`: `ProductDeletionReader`, `AdminProductWriter::delete`,
  `InventoryStockPort::remove`, `CatalogAuditPort`, `ProductSearchIndexPort` (5). Missing → 404;
  historical orders → 422 with the current message/advice. Keep the current order:
  `InventoryStockPort::remove` → `AdminProductWriter::delete` → `CatalogAuditPort::record` →
  `ProductSearchIndexPort::forget`. `AdminProductWriter::delete` also removes the
  `storefront_product_view` row and the `catalog_submission` row, because the handler cannot
  take a sixth `CatalogSubmissionDeleter` dependency; document this in the adapter and
  ADR-0018.
- `GetAdminProductHandler`: `StorefrontProductRepository::adminBySlug()` +
  `CatalogSubmissionFinder::one()`; returns null when the caller may not read it.
- Controller keeps request-shape validation (JSON, field types, decimal parsing, variants,
  slug format, stock enum) and builds `ProductDraft`; the taxonomy/duplicate checks move to
  the handler. `show`/`create`/`update`/`setPublication` return the query result.
- Constructor: `MessageBusInterface`, `MessengerQuery`.

**Tests:** `AdminCatalogTest`, `AdminProductListTest`, `AdminProductDecimalPriceTest`,
`AdminCatalogApprovalWorkflowTest`, `AdminProductStockTest`; new unit tests for the three command
handlers with fake ports, plus a test that constructs and dispatches `GetAdminProduct` so the new
query class is referenced by a changed test.

### 4.12 `AdminCategoryController`

- Add query `ValidateCategoryParent(string $categorySlug, ?string $parentSlug): void`;
  handler uses `CategoryHierarchyReader` + `CategoryHierarchy`, throws
  `CategoryHierarchyCycleException` (controller maps 422 `{valid:false,...}`).
- Add `DeleteProduct` to this controller's route (same command as 4.11).
- `respond()` already uses `ListCategories`; keep it.
- Constructor: `MessageBusInterface`, `MessengerQuery`.

**Tests:** `AdminCategoryManagementTest`, `AdminProductCategoryMoveTest`,
`AdminCatalogTest`, `CatalogVisibilitySingleSourceTest`, including one direct `MessengerQuery`
dispatch of `ValidateCategoryParent` so the new query class is referenced by a changed test.

### Task 4.13 — Controller gate

Container:

```bash
docker compose exec -T app vendor/bin/phpunit --testsuite=Functional
docker compose exec -T app vendor/bin/phpunit tests/Architecture/SolidBoundariesTest.php
docker compose exec -T app vendor/bin/phpstan analyse --no-progress
```

Host shell (the app image has no `git`; use `grep`, since neither the host nor the image
ships `rg`):

```bash
if grep -rnE --include='*Controller.php' -- '->(fetch|fetchOne|fetchAllAssociative|fetchAssociative|fetchFirstColumn|fetchNumeric|executeStatement|beginTransaction|commit|rollBack)\(' src; then
  echo 'SRP-002: SQL call in an HTTP controller' >&2
  exit 1
fi
```

Acceptance: all 12 controllers pass `ControllerThinRule`; no SQL in Http; functional suite
green (except any pre-existing Meilisearch test already recorded and fixed in Phase 0).

## Phase 5 — LSP and static analysis

### Task 5.1 — Repository contract tests (LSP-001)

Add abstract tests under `tests/Integration/Contract/`:

- `AbstractOrderRepositoryContractTest` — `save` then `get` returns the same aggregate;
  missing id throws the port's not-found exception; `save` twice updates, does not duplicate.
- `AbstractProductRepositoryContractTest` — same, plus `get` after `changePrice`.
- `AbstractCartRepositoryContractTest` — `findByCustomerId` null/missing, save/read,
  line identity.
- `AbstractCatalogSubmissionRepositoryContractTest` — `find` returns null for missing,
  the stored status/owner for existing.

Concrete subclasses:
`tests/Integration/Ordering/DoctrineOrderRepositoryTest`,
`tests/Integration/Catalog/DoctrineProductRepositoryTest`,
`tests/Integration/Cart/DbalCartRepositoryTest`,
`tests/Integration/Catalog/CatalogSubmissionRepositoryContractTest`.
Each runs against the test PostgreSQL database; no mocks.

### Task 5.2 — Payment port contract test (LSP-003)

`tests/Integration/Payment/PaymentInitiationPortContractTest.php`:
- `open()` creates one pending payment and one ledger row with the exact amount/currency;
- a second `open()` for the same order is idempotent or rejected consistently (assert the
  adapter's documented behavior);
- the amount is not re-derived from the catalog.
Subclass: `DbalPaymentInitiationAdapterTest`. Document LSP-003 as "not applicable until a
second gateway exists".

### Task 5.3 — Strict rules (LSP-002)

1. `docker compose exec -T app composer require --dev phpstan/phpstan-strict-rules` (this edits
   `composer.json`/`composer.lock`; commit both).
2. Add to `phpstan.neon`:
   ```neon
   includes:
       - vendor/phpstan/phpstan-symfony/extension.neon
       - vendor/phpstan/phpstan-strict-rules/rules.neon
   ```
3. Run `vendor/bin/phpstan analyse --no-progress`; fix every finding in production code. No
   `@phpstan-ignore`, no baseline, no exclusions. Common fixes: explicit `in_array(..., true)`,
   strict comparisons, `array_key_exists`, typed closures, no implicit `mixed`.
4. Fix the remaining 9 baseline errors from Phase 0 if any reappear:
   - `StockReservationReleaseTest` slug nullability (6);
   - `ReplayCatalogSubmissionTest`/`ReplayInventoryStockTest` optional `missingView` key (3).

### Task 5.4 — Infection scope (TDD-010)

Update `infection.json.dist`:

```json
"source": {
    "directories": ["src"],
    "excludes": ["Kernel.php", "*/Infrastructure/*"]
}
```

Confirm the filter actually excludes Infrastructure with a small run on one Domain file.
Update `.github/workflows/ci.yml` infection job to mutate only changed
`src/*/Domain/**` + `src/*/Application/**` files, with `--min-msi=85 --min-covered-msi=90`.
If no files changed, the job exits 0 with a clear message.

Run:
```bash
docker compose exec -T app vendor/bin/infection --threads=max --min-msi=85 --min-covered-msi=90
```
Triage every survivor; add tests, do not ignore.

### Task 5.5 — Phase 5 gate

`make solid` and `make tdd` green. Commit:
`test(solid): add repository and payment contracts; enable strict rules`.

## Phase 6 — Documentation and final verification

### Task 6.1 — ADRs

- New `docs/adr/ADR-0018-solid-enforcement.md`: gates (`make solid`, `make tdd`), no-bypass
  policy, PHPStan rules, architecture test, hook corrections, TDD refactor mode, CI jobs.
- New `docs/adr/ADR-0019-domain-dependency-rule.md`: LAY-001 correction (ORM mapping,
  Doctrine collections + Symfony Uid allowed; DBAL/EntityManager/HttpFoundation still banned).
- Amend `docs/adr/ADR-0007-*.md`: status "Superseded by ADR-0018"; note the four legacy
  controllers are migrated.
- Amend `docs/adr/ADR-0014-catalog-approval-write-model.md`: one adapter, six narrow ports +
  repository; transaction carve-out.
- If WP7 added a migration guard decision, reference it from ADR-0018.

### Task 6.2 — Rules and harness docs

- `.agents/rules/layering.md` LAY-001: allowed prefixes and banned prefixes; enforcement
  references `SolidBoundariesTest`, `NoDoctrineInApplicationRule`.
- `.agents/rules/solid.md`: add enforcement references per rule (architecture test/PHPStan
  rule).
- `.agents/rules/tdd.md` TDD-001: reference `bin/tdd-check` and the strengthened
  `tdd-gate.sh`.
- `AGENTS.md`: add `make solid` and `make tdd` to Commands; add the no-bypass policy and
  `git config core.hooksPath .githooks`; update Definition of Done.
- `.agents/phpstan/README.md`: list the 10 rules.
- `bin/harness-check`: expected PHPStan rules 10; hook scripts still 8; hooks.yaml still 8.
- `.agents/hooks/hooks.yaml`: update the `guard-cqrs` rule list (remove OCP-002, note the
  architecture test owns it); add `bin/tdd-check` to the `tdd-gate` description.

### Task 6.3 — Required checks

Configure branch protection (manual, admin):
- `Quality gates`
- `Solid gates`
- `TDD gate`
- `Infection`
- `Prod image`

Record the command/screenshot in the state file. If the repository is not on GitHub, record
the equivalent merge policy in `AGENTS.md`.

### Task 6.4 — Final verification

Run the full sequence and paste the output tails into the state file.

Container:

```bash
docker compose exec -T app vendor/bin/phpunit --testsuite=Unit
docker compose exec -T app vendor/bin/phpunit --testsuite=Integration
docker compose exec -T app vendor/bin/phpunit --testsuite=Projection
docker compose exec -T app vendor/bin/phpunit --testsuite=Functional
docker compose exec -T app vendor/bin/phpunit --testsuite=Contract
docker compose exec -T app vendor/bin/phpunit --testsuite=Architecture
docker compose exec -T app vendor/bin/phpstan analyse --no-progress
docker compose exec -T app vendor/bin/deptrac analyse --config-file=deptrac.yaml
docker compose exec -T app vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec -T app bin/harness-check
docker compose exec -T app bin/solid-check
docker compose exec -T app vendor/bin/infection --threads=max --min-msi=85 --min-covered-msi=90
```

Host shell (needs `git`):

```bash
./bin/tdd-check --self-test
./bin/tdd-check --range "$(cat .git/solid-base)...HEAD"
```

Acceptance: all green; zero `Doctrine\DBAL\Connection` in Application; zero SQL in Http;
zero `match (true)` in Domain/Application; zero interfaces >3 methods; zero constructors >5
deps; PHPStan level 8 + strict 0; Deptrac 0; Infection thresholds met.

### Task 6.5 — Final report

Produce a file-by-file report with:
- every changed/added/deleted file;
- every audit finding closed and the rule ID;
- the exact commands and counts;
- any deviation from the design and why;
- the branch/commit list.

Commit: `docs(solid): record enforcement model and verify all gates`.

## Appendix A — Port inventory (all new interfaces)

| Context | Interface | Methods |
|---|---|---|
| Identity | `CustomerAccountReader` | 2 |
| Identity | `CustomerDeletionReader` | 1 |
| Identity | `CustomerStatusStore` | 2 |
| Identity | `CustomerRoleStore` | 1 |
| Identity | `CustomerDeletionWriter` | 2 |
| Identity | `CustomerRegistrar` | 2 |
| Identity | `CustomerProfileStore` | 1 |
| Identity | `CustomerAddressStore` | 2 |
| Identity | `GoogleAccountLinker` | 3 |
| Identity | `PasswordResetStore` | 2 |
| Identity | `PasswordResetTokenStore` | 3 |
| Identity | `ContactMessageStore` | 1 |
| Identity | `QuoteRequestStore` | 1 |
| Identity | `NewsletterStore` | 1 |
| Identity | `WishlistStore` | 2 |
| Identity | `IdentityAuditPort` | 1 |
| Shared | `ProductExistencePort` | 1 |
| Shared | `ProductSearchIndexPort` | 2 |
| Catalog | `CategoryReader` | 2 |
| Catalog | `CategoryCreator` | 2 |
| Catalog | `CategoryTreeWriter` | 2 |
| Catalog | `CategoryVisibilityWriter` | 1 |
| Catalog | `CategoryDeletionReader` | 1 |
| Catalog | `CategoryDeleter` | 1 |
| Catalog | `CategoryHierarchyReader` | 1 |
| Catalog | `ProductPublicationStore` | 2 |
| Catalog | `ProductReviewStore` | 1 |
| Catalog | `AdminProductWriter` | 3 |
| Catalog | `ProductDeletionReader` | 2 |
| Catalog | `BannerSetReader` | 1 |
| Catalog | `BannerImageStore` | 3 |
| Catalog | `CatalogSubmissionCreator` | 2 |
| Catalog | `CatalogSubmissionSubmitter` | 1 |
| Catalog | `CatalogSubmissionDecider` | 1 |
| Catalog | `CatalogSubmissionReviewer` | 1 |
| Catalog | `CatalogSubmissionOwnerEditor` | 1 |
| Catalog | `CatalogSubmissionDeleter` | 1 |
| Catalog | `BrandReader` | 2 |
| Catalog | `BrandStore` | 3 |
| Promotions | `PromoCodeReader` | 2 |
| Promotions | `PromoCodeStore` | 3 |
| Ordering | `OrderStateReader` | 1 |
| Ordering | `OrderTransitionStore` | 2 |
| Ordering | `OrderCustomerReader` | 1 |
| Ordering | `AdminProxyOrderStore` | 1 |
| Ordering | `OrderPlacementStore` | 2 |
| Payment | `PaymentStateReader` | 1 |
| Shared | `PlatformSettingsWriter` | 1 |
| Shared | `ShippingPolicyWriter` | 1 |

All are ≤3 methods. `CatalogSubmissionRepository` remains the single-method Domain read port.

## Appendix B — Query/command inventory for Phase 4

| New message | Type | Handler location |
|---|---|---|
| `GetAdminProduct` | query | `Catalog\Infrastructure\ReadModel` |
| `ValidateCategoryParent` | query | `Catalog\Infrastructure\ReadModel` |
| `GetCustomerAuditTrail` | query | `Identity\Infrastructure\ReadModel` |
| `GetOrderAuditTrail` | query | `Ordering\Infrastructure\ReadModel` |
| `GetPaymentState` | query | `Payment\Infrastructure\ReadModel` |
| `GetGoogleAuthorizationUrl` | query | `Identity\Infrastructure\ReadModel` |
| `GetGoogleUser` | query | `Identity\Infrastructure\ReadModel` |
| `GetLoginUser` | query | `Identity\Infrastructure\ReadModel` |
| `GetPaymentMethods` | query | `Shared\Infrastructure\ReadModel` |
| `CreateProduct` | command | `Catalog\Application\CommandHandler` |
| `UpdateProduct` | command | `Catalog\Application\CommandHandler` |
| `DeleteProduct` | command | `Catalog\Application\CommandHandler` |
| `CreateBrand` | command | `Catalog\Application\CommandHandler` |
| `RenameBrand` | command | `Catalog\Application\CommandHandler` |
| `DeleteBrand` | command | `Catalog\Application\CommandHandler` |
| `CreatePromoCode` | command | `Promotions\Application\CommandHandler` |
| `UpdatePromoCode` | command | `Promotions\Application\CommandHandler` |
| `DeletePromoCode` | command | `Promotions\Application\CommandHandler` |

Queries follow the existing convention: query DTO in `Application/Query`, handler with
`#[AsMessageHandler(bus: 'query.bus')]`, controller unwraps via `MessengerQuery`.

## Appendix C — Test file map

| Area | New/extended test |
|---|---|
| Architecture gates | `tests/Architecture/SolidBoundariesTest.php`, `TddHarnessTest.php` |
| PHPStan gates | `tests/Unit/PhpStan/*RuleTest.php` + fixtures |
| TDD gate | `tests/Architecture/TddHarnessTest.php` + `bin/tdd-check --self-test` |
| Identity adapters | `tests/Integration/Identity/{CustomerAccountAdapter,GoogleAccountLinker,PasswordResetStore,IdentityIntakeStore,CustomerAddressStore,WishlistStore,IdentityAuditPort}Test.php` |
| Catalog adapters | `tests/Integration/Catalog/{CategoryStoreAdapter,AdminProductStoreAdapter,BannerStoreAdapter,ProductStoreAdapter}Test.php` |
| Ordering adapters | `tests/Integration/Ordering/{OrderStateAdapter,AdminProxyOrderStore,OrderPlacementStore}Test.php` |
| Payment adapter | `tests/Integration/Payment/PaymentStateAdapterTest.php` |
| Shared adapters | `tests/Integration/Shared/{PlatformSettingsWriter,ShippingPolicyWriter}Test.php` |
| LSP contracts | `tests/Integration/Contract/Abstract*ContractTest.php` + four concrete tests; `PaymentInitiationPortContractTest.php` |
| WP6 | `tests/Functional/CheckoutPriceChangeTest.php`, `tests/Unit/Ordering/PlaceOrderPriceChangeTest.php` |
| WP7 | `tests/Architecture/MigrationReadModelGuardTest.php` |

## Appendix D — Baseline (record before Phase 1)

Fill from the Phase 0 final run:

| Gate | Baseline |
|---|---|
| Unit | 334 |
| Integration | 106 |
| Projection | 37 |
| Contract | 16 |
| Functional | 50 files, 2 known failures before WP3/WP7 |
| Architecture | 2 failures before WP3 |
| PHPStan level 8 | 9 errors (3 test files) |
| Deptrac | 0 |
| `make solid` | not present |
| `make tdd` | not present |

## Appendix E — Legacy class → replacement map (40 classes in scope)

Every Application class in the remediation inventory is listed here with the handler,
port or split that replaces its DBAL dependency. At Task 0.5, 37 still import
`Doctrine\DBAL\*` (36 `Connection`, plus `EmailUniqueness`'s exception type); the three marked
*already DBAL-free* were cleaned by WP3.

### Identity (14)

| Legacy class | Replaced by |
|---|---|
| `BanCustomerHandler` | `CustomerAccountReader`, `CustomerStatusStore`, `IdentityAuditPort` |
| `ChangeCustomerRoleHandler` | `CustomerAccountReader`, `CustomerRoleStore`, `IdentityAuditPort` |
| `CustomerProfileCommandHandler` | split into `UpdateCustomerProfileHandler`, `SaveCustomerAddressHandler`, `DeleteCustomerAddressHandler` (SRP-001) |
| `DeleteCustomerWithAnonymizationHandler` | `CustomerAccountReader`, `CustomerDeletionReader`, `CustomerDeletionWriter`, `IdentityAuditPort` |
| `EmailUniqueness` | moved to `Identity\Infrastructure\Persistence\EmailUniqueness` (shared by `DbalCustomerAccountAdapter` and `DbalGoogleAccountLinker`) |
| `LinkGoogleAccountHandler` | `GoogleAccountLinker` |
| `RegisterCustomerHandler` | `CustomerRegistrar` |
| `RequestPasswordResetHandler` | `PasswordResetStore`, `PasswordResetTokenStore`, `MailerInterface`, `LoggerInterface` |
| `ResetPasswordHandler` | `PasswordResetTokenStore`, `PasswordResetStore` |
| `SubmitContactMessageHandler` | `ContactMessageStore` |
| `SubmitQuoteRequestHandler` | `QuoteRequestStore`, `StorefrontCategoryPort` |
| `SubscribeNewsletterHandler` | `NewsletterStore` |
| `UnbanCustomerHandler` | `CustomerStatusStore`, `IdentityAuditPort` |
| `WishlistCommandHandler` | split into `AddWishlistItemHandler`, `RemoveWishlistItemHandler` (SRP-001) |

### Catalog (17)

| Legacy class | Replaced by |
|---|---|
| `AddCategorySubLevelHandler` | `CategoryReader`, `CategoryTreeWriter`, `SubmissionAccessPolicy`, `CatalogSubmissionReviewer`, `CatalogSubmissionOwnerEditor` |
| `BannerRemoveImageHandler` | `BannerSetReader`, `BannerImageStore` |
| `BannerReorderImagesHandler` | `BannerPayload`, `BannerSetReader`, `BannerImageStore` |
| `BannerReplaceImagesHandler` | `BannerPayload`, `BannerImageStore` |
| `CreateCategoryHandler` | `CategoryCreator`, `CatalogSubmissionCreator` |
| `DeleteCategoryHandler` | `CategoryReader`, `CategoryDeletionReader`, `CategoryDeleter`, `CatalogSubmissionDeleter`, `CatalogAuditPort` |
| `HideCategoryHandler` | `SubmissionAccessPolicy`, `CategoryReader`, `CategoryVisibilityWriter` |
| `ReplaceCategoryTreeHandler` | `CategoryReader`, `CategoryTreeWriter`, `SubmissionAccessPolicy`, `CatalogSubmissionReviewer`, `CatalogSubmissionOwnerEditor` |
| `SetProductPublicationHandler` | `ProductPublicationStore`, `CatalogSubmissionRepository`, `CatalogAuditPort`, `ProductSearchIndexPort` |
| `ShowCategoryHandler` | `SubmissionAccessPolicy`, `CategoryReader`, `CategoryVisibilityWriter` |
| `SubmitProductReviewHandler` | `ProductVisibilityPort`, `ProductReviewStore` |
| `BannerRules` | `BannerPayload` (pure Application service; no DBAL) |
| `CategoryHierarchyValidator` | `CategoryHierarchy` (pure Application service; no DBAL) |
| `ProductDeletionGuard` | `ProductDeletionReader` + `DeleteProductHandler` (Phase 4.11) |
| `SubmitCatalogSubmissionHandler` | `CatalogSubmissionSubmitter` (*already DBAL-free* after WP3) |
| `DecideCatalogSubmissionHandler` | `CatalogSubmissionDecider` (*already DBAL-free* after WP3) |
| `SubmissionAccessPolicy` | `CatalogSubmissionRepository` (*already DBAL-free* after WP3) |

### Ordering (5)

| Legacy class | Replaced by |
|---|---|
| `AutoCancelOrderHandler` | `OrderStateReader`, `MessageBusInterface`, `StockReservationPort` |
| `CancelOrderByCustomerHandler` | `OrderCustomerReader`, `MessageBusInterface`, `StockReservationPort` |
| `CreateAdminProxyOrderHandler` | `AdminProxyOrderStore`, `StorefrontQuoteService`, `StockReservationPort`, `PaymentInitiationPort` |
| `PlaceOrderHandler` | `StorefrontQuoteService`, `StockReservationPort`, `PaymentInitiationPort`, `OrderPlacementStore`, `OutboxPort` (Task 2.7) |
| `TransitionOrderStatusHandler` | `OrderStateReader`, `OrderTransitionStore` |

### Payment (2)

| Legacy class | Replaced by |
|---|---|
| `ConfirmOfflinePaymentHandler` | `PaymentStateReader` + existing `PaymentLedger`, `OrderSettlementPort`, `OutboxPort` |
| `RefundPaymentHandler` | `PaymentStateReader` + existing `PaymentLedger`, `OrderSettlementPort`, `OutboxPort` |

### Shared (2)

| Legacy class | Replaced by |
|---|---|
| `UpdatePlatformSettingsHandler` | `PlatformSettingsWriter` |
| `UpdateShippingPolicyHandler` | `ShippingPolicyWriter` |

## Plan self-review

- **Spec coverage:** every section of the design maps to a phase: §8.1→Phase 3, §8.2→Phase 4,
  §8.3→Phase 2, §8.4→Phase 2.5, §8.5→Phase 2.7+4, §8.6→Phase 5, §8.7→Phase 1, §8.8→Phase 2.8
  +6.1, §9.1→1.2/1.3, §9.2→1.1, §9.3→1.4/1.6, §9.4→1.5, §9.5→1.2/1.5, §10→phases,
  §13→6.4.
- **Granularity:** each task names files, signatures, tests, commands, and a commit. The large
  DIP-003 phase is split per context and per port, so a task is one RED/GREEN cycle.
- **Type consistency:** every port in Appendix A has an explicit signature in §3 or §4; the same
  names are used by the handlers, adapters and tests. DTO field names are pinned where the
  handler reads them.
- **No hidden exemptions:** the only historical exception is the frozen three-file WP7
  migration allowlist required by spec-run-15 R33 (`Version20261017000002`,
  `Version20261018000000`, `Version20261029000003`). That allowlist belongs to the
  spec-run-15 feature, not to the SOLID gates, and the guard fails if an allowlisted file
  disappears. The SOLID gates have no exclusions.
- **Complete inventory:** Appendix E maps all 40 remediation classes to a handler, port or split;
  a class cannot be dropped from the remediation without deleting its Appendix E row.
- **Proportion:** the plan front-loads the gates (Phase 1) so the rest of the work is driven by
  failing checks, not by a subjective review.

## Review focus

When reviewing this plan, check:
1. Does Phase 0 leave the working tree clean and the baseline actually green?
2. Are the 12 controllers in Phase 4 all covered, including the 4 ADR-0007 legacy ones?
3. Does every one of the 40 Application classes in Appendix E appear in a handler mapping, and
   are the three WP3-cleaned classes still covered by their split ports?
4. Are the port signatures compatible with the WP3 post-split code (especially
   `CatalogSubmissionCreator::import` and the reviewer/owner split)?
5. Does the TDD gate's refactor mode allow the mechanical moves without allowing a new class
   with no test?
6. Are the gate commands runnable in the app image (no git) and on the host (git)?
7. Does the LAY-001 allowance (ORM mapping, `Doctrine\Common\Collections`, Symfony Uid) leave
   zero Domain findings while still banning DBAL, EntityManager and HttpFoundation?
8. Is `EmailUniqueness` moved out of Application into Infrastructure, with
   `CustomerEmailUniquenessTest` constructing `DbalCustomerAccountAdapter` and importing the
   moved helper?
9. Is ISP-001 split as 8 methods → 6 single-purpose ports + the 1-method repository, with no
   port over 3 methods?

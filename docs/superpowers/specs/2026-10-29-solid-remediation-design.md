# SOLID Remediation and Enforcement — Design

Date: 2026-10-29
Project: ecommerce-core (`E-commerce/`)
Status: awaiting approval

## 1. Intent

The 2026-10-29 audit found that the backend does **not** respect its own SOLID
rulebook (`.agents/rules/solid.md`): 5 of 5 principles have confirmed
violations, with systemic DIP-003 (39 Application classes depend on
`Doctrine\DBAL\Connection`) and SRP-002 (7 controllers run SQL). The same audit
found that the rules are only partially enforced: the PHPStan rule does not ban
`Connection`, the guard hooks only inspect changed files, and no architecture
test covers SRP/ISP/DIP.

The user wants two outcomes:

1. **Fix every confirmed SOLID violation** in `E-commerce/`.
2. **Make the rules permanent gates.** From now on, neither the agent nor a
   human can merge code with a SOLID violation, and no production change can
   pass without TDD evidence.

This document defines the target architecture, the enforcement design, the
work breakdown, and the definition of done.

## 2. Goals

- Zero confirmed SOLID violations in `src/`.
- Zero legacy allowlists: the four ADR-0007 controllers are migrated, not
  exempted.
- A deterministic, CI-blocking gate for every SOLID rule (`make solid`).
- A deterministic, CI-blocking gate for TDD (`make tdd`).
- Behavior preservation: existing HTTP contracts, database schema, order and
  payment semantics, and the 139 existing test files stay valid.
- Every new abstraction has a contract test (LSP-001/LSP-003).
- PHPStan level 8 + strict rules and Deptrac pass with zero errors.

## 3. Non-goals

- Rewriting Identity, Payment, or Catalog into full DDD aggregates. The fix is
  dependency inversion, not a domain-model rewrite.
- Database schema changes or data migrations.
- Frontend changes (`Zidane_front_web`).
- Performance tuning beyond preserving current query behavior.
- Event sourcing.
- Enforcing subjective TDD rules that cannot be checked mechanically (for
  example "one behavior per test"). TDD-001/TDD-002/TDD-010 are enforced;
  TDD-003..009 remain review rules backed by the existing hook heuristics.

## 4. Anti-goals

- A refactor that changes an HTTP response shape, status code, SQL result, or
  transaction outcome.
- A gate with an allowlist, baseline file, `@phpstan-ignore`, or path exclusion
  that lets an existing violation pass.
- A "TDD gate" that only checks a markdown line and can be satisfied without a
  real test.
- Editing tests to make a gate green when production code is wrong.

## 5. Constraints

- Docker-only execution (INF-002); PostgreSQL 16 (INF-001); PHP 8.5.
- `doctrine_transaction` on `command.bus` is the transaction boundary
  (`config/packages/messenger.yaml`). Adapters do not commit or roll back.
- ADR-0012 and ADR-0014 transaction/audit semantics stay intact: the audit row
  and the state change commit together.
- The working tree currently has unfinished spec-run-15 work (modified
  `AdminProductController`, untracked `CatalogSubmissionStore`, migrations).
  WP1, WP2 and WP5a are recorded as done; WP4 (inventory) is in progress and
  WP3/WP6 are not done. It must be committed, finished, or stashed before the
  refactor starts.
- The current baseline is **red**: Architecture 2 failures, Functional 2
  failures, PHPStan 9 errors (3 test files, all in the unfinished inventory/
  projection work). Unit (334), Integration (106), Projection (37) and
  Contract (16) are green. Phase 0 restores a green baseline.

## 6. Audit baseline

| Rule | Findings |
|---|---|
| DIP-003 | 39 Application classes inject `Doctrine\DBAL\Connection`; 35 of 38 command handlers. |
| SRP-002 | 7 controllers run SQL; 3 are outside the ADR-0007 legacy exemption. |
| SRP-001 / OCP-001 | 4 handler classes handle 2–4 messages each. |
| ISP-001 | `CatalogSubmissionStore` has 8 methods. |
| ISP-003 | 4 services exceed 5 constructor dependencies. |
| OCP-002 | 2 `match (true)` expressions in Domain/Application. |
| LSP-001 | 4 repository implementations, 0 shared contract tests. |
| LSP-002 | PHPStan fails with 9 errors (3 test files, spec-run-15 work); strict rules are not installed. |
| LSP-003 | No `PaymentGateway` implementations; the payment port has no contract test. |
| LAY-001 | 4 Domain files import Doctrine ORM/Collections/Symfony Uid; the rule contradicts `AGENTS.md` and `doctrine.yaml`. |

Clean: OCP-003, ISP-002, DIP-001, DIP-002, SRP-003, LAY-004.

## 7. Approaches considered

### A. Ports/adapters, behavior-preserving, all legacy migrated — CHOSEN

Move every vendor/database dependency out of Application behind narrow ports;
adapters own SQL in Infrastructure. Migrate all controllers, including the four
legacy ones, to command/query dispatch. Add hard gates.

- Cost: large but mechanical; each port is independently testable.
- Risk: medium; existing functional tests provide characterization coverage.
- Result: every rule satisfied without changing behavior.

### B. Full DDD/CQRS rewrite

Build aggregates and ORM repositories for every context, then rewrite handlers
against them.

- Rejected: weeks of domain modeling not requested by the user; high regression
  risk on order/payment semantics; conflicts with ADR-0012/0014 decisions.

### C. Targeted cleanup plus ADR exceptions

Fix SRP-001, ISP-001, and the 3 non-legacy controllers; write an ADR accepting
DBAL in Application.

- Rejected: the user explicitly asked to fix all violations and to stop letting
  violations pass.

## 8. Target architecture

### 8.1 DIP-003 — Application depends on ports, never on Doctrine or Infrastructure

- Every Application class that currently injects `Doctrine\DBAL\Connection`
  gets one or more ports.
- Ports live in `src/<Context>/Application/Port/` (use-case ports) or
  `src/<Context>/Domain/Repository/` (aggregate repositories).
- Each port has **at most 3 methods** and serves one use case.
- Adapters live in `src/<Context>/Infrastructure/Persistence/` (write) or
  `.../ReadModel/` (read) and are bound in `config/services.yaml`.
- Handlers orchestrate: read via a port → apply domain rule → write via a port.
  They do not build SQL and do not know table names.
- `doctrine_transaction` is the default transaction boundary. Adapters never
  call `beginTransaction`, `commit`, or `rollBack` on the normal command path.
  A multi-statement adapter method is already inside the middleware
  transaction.
- **Transaction carve-out:** an adapter that is the transaction owner for a use
  case callable outside `command.bus` (the submission store, the replay
  command) may use `Connection::transactional()` internally. This preserves
  ADR-0014 and `CatalogSubmissionStoreTest`'s "one call is one transaction"
  guarantee. The carve-out is documented in the new ADR; it is not available to
  ordinary command-path adapters.
- Audit writes stay in the same transaction: an adapter may receive
  `CatalogAuditPort`/`IdentityAuditPort` and call it inside its method, or the
  handler calls the audit port after the write port; both run inside the
  command-bus transaction.
- Search indexing becomes a `ProductSearchIndexer` port; best-effort semantics
  stay (an index failure never fails the write).
- Idempotency for `PlaceOrder` becomes an `IdempotencyStore` port with
  `claim(string $key): bool`. The handler keeps the early return.
- **Legacy write target:** the four ADR-0007 controllers keep writing the same
  denormalized tables (`storefront_product_view`, `storefront_category`,
  `storefront_brand`, `storefront_promo_code`). The new ports are persistence
  abstractions around those writes, not new domain aggregates. Database schema
  and HTTP contracts do not change. This is a deliberate boundary: the goal is
  dependency inversion, not a Catalog domain-model rewrite.

Representative mapping (the plan enumerates all 39):

| Handler | Port(s) | Adapter |
|---|---|---|
| `RegisterCustomerHandler` | `CustomerRegistrar` | `DbalCustomerRegistrar` |
| `BanCustomerHandler` | `CustomerAccountReader`, `CustomerBanner` | `DbalCustomerAccountReader`, `DbalCustomerBanner` |
| `CreateCategoryHandler` | `CategoryCreator` | `DbalCategoryStore` |
| `SubmitProductReviewHandler` | `ProductReviewStore` | `DbalProductReviewStore` |
| `TransitionOrderStatusHandler` | `OrderStatusReader`, `OrderStatusWriter` | `DbalOrderStatusStore` |
| `PlaceOrderHandler` | `IdempotencyStore` + existing ports | `DbalIdempotencyStore` |
| `UpdatePlatformSettingsHandler` | `PlatformSettingsWriter` | `DbalPlatformSettingsWriter` |

### 8.2 SRP-002 — Controllers only translate HTTP ↔ bus

- **Scope is exhaustive: every controller under `src/*/Infrastructure/Http/`.**
  There is no controller allowlist.
- A controller constructor may contain only: `MessageBusInterface`,
  `MessengerQuery`, rate-limiter factories/checkers, `Security`,
  `LoggerInterface`, `ClockInterface`, and scalar configuration.
- Forbidden constructor types: `Connection`, `EntityManagerInterface`,
  repositories, `*ReadModel*`, `*Search*`, domain services, validators,
  infrastructure clients.
- All reads go through `query.bus`; all writes through `command.bus`.
- Request-shape validation stays in the controller (400). Business validation
  moves to the command handler/domain (422 via domain exceptions).
- The four ADR-0007 controllers are migrated. ADR-0007 is superseded by a new
  ADR that records the migration.
- Controllers that need extra work to fit the rule:
  - `CheckoutOrderController`: delete `CheckoutSaga` (a one-line command-bus
    wrapper) and dispatch `PlaceOrder` directly; move
    `PlatformSettingsRepository::paymentMethods()` to a `PaymentMethods` query.
  - `GoogleOAuthController`: keep `Security` and `MessengerQuery`; move the
    token exchange and user lookup behind a `GetGoogleUser` query handler. The
    OAuth state/session handling remains HTTP glue in the controller.
  - `AdminBannerController`: move `BannerRules` validation into the banner
    command handlers; `BannerRules` becomes a pure domain/application service
    with no DBAL.
  - `AdminSettingsController`: move `paymentMethods()` and the settings read
    behind a query.
  - `WishlistController`: move the `StorefrontProductRepository::bySlug` check
    into `AddWishlistItemHandler`.
  - `AdminProductController` / `AdminCategoryController`: `Connection`,
    `StorefrontProductRepository`, `StorefrontProductSearchIndexer`,
    `SubmissionAccessPolicy`, `CatalogAuditPort`, `CategoryHierarchyValidator`
    and `ProductDeletionGuard` leave the constructor; the routes dispatch
    commands/queries.
- New query handlers:
  - `GetCustomerAuditTrail` (Identity ReadModel)
  - `GetOrderAuditTrail` (Ordering ReadModel)
  - `GetPaymentState` (Payment ReadModel)
  - `GetAdminProduct` (Catalog ReadModel; replaces `adminPayload` in the
    controller)
  - `ValidateCategoryParent` (Catalog query; replaces the validator call)
  - `GetGoogleUser` (Identity query; wraps the OAuth exchange)
  - `PaymentMethods` (Shared query; replaces direct settings access)
- New command handlers for the legacy CRUD routes: product create/update,
  category create/update/visibility/delete, brand create/rename/delete,
  promo-code create/update/delete.
- New write ports for those commands persist to the same tables (see §8.1).
- Phase 4 therefore covers 12 controllers: the 7 SQL controllers
  (`AdminProduct`, `AdminCategory`, `AdminBrand`, `AdminPromoCode`,
  `AdminCustomer`, `AdminOrder`, `AdminPayment`) plus `CheckoutOrder`,
  `GoogleOAuth`, `AdminBanner`, `AdminSettings`, `Wishlist`.

### 8.3 SRP-001 / OCP-001 — One handler per message

- Split `CartCommandHandler` into `AddCartItemHandler`,
  `UpdateCartItemHandler`, `RemoveCartItemHandler`, `ClearCartHandler`.
- Split `CustomerProfileCommandHandler` into `UpdateCustomerProfileHandler`,
  `SaveCustomerAddressHandler`, `DeleteCustomerAddressHandler`.
- Split `WishlistCommandHandler` into `AddWishlistItemHandler`,
  `RemoveWishlistItemHandler`.
- Split `CustomerProfileFinder` into `GetCustomerProfileHandler`,
  `ListCustomerAddressesHandler`.
- Shared private helpers become small Application services or ports; no
  inheritance, no abstract base handler.

### 8.4 ISP-001 — Narrow ports

Split `CatalogSubmissionStore` into:

- `CatalogSubmissionCreator` — `create`, `import`
- `CatalogSubmissionRepository` (existing, Domain) — `find`
- `CatalogSubmissionSubmitter` — `submit`
- `CatalogSubmissionDecider` — `decide`
- `CatalogSubmissionReviewer` — `reviewerSave`
- `CatalogSubmissionOwnerEditor` — `ownerModified`
- `CatalogSubmissionDeleter` — `delete`

All six new interfaces are implemented by the existing
`DbalCatalogSubmissionStore`, aliased in `services.yaml`. Transaction semantics
and ADR-0014 behavior are unchanged. ADR-0014 is amended: one implementation,
many narrow interfaces.

### 8.5 ISP-003 — Dependency cap

- Service constructors are limited to 5 dependencies.
- `PlaceOrderHandler` drops `Connection` (idempotency port) and keeps:
  quotes, stock, payments, orders, outbox = 5.
- `AdminProductController` and `AdminCategoryController` lose their SQL/domain
  dependencies when migrated; they end with the bus, query, rate limiter, and
  scalar config only.
- `CheckoutOrderController` moves `PlatformSettingsRepository` behind a
  `PaymentMethods` query; the controller keeps the bus, query, saga, limiter,
  checker = 5.

### 8.6 LSP

- **LSP-001:** add abstract contract tests:
  - `tests/Integration/Contract/OrderRepositoryContractTest.php`
  - `tests/Integration/Contract/ProductRepositoryContractTest.php`
  - `tests/Integration/Contract/CartRepositoryContractTest.php`
  - `tests/Integration/Contract/CatalogSubmissionRepositoryContractTest.php`
  Each concrete adapter test extends the abstract test. New ports with one
  implementation get a contract test too.
- **LSP-002:** install `phpstan/phpstan-strict-rules`; fix all findings; keep
  level 8. Fix the current 2 `ReplayCatalogSubmissionTest` offset errors.
- **LSP-003:** there is no `PaymentGateway` implementation today. Add a
  contract test for `PaymentInitiationPort` (the existing port) and document
  LSP-003 as not applicable until a second gateway exists.

### 8.7 DIP-001 / DIP-002 — Keep clean, enforce

- All interfaces stay in Domain/Application.
- No service location outside `Kernel`/compiler passes.
- Add architecture tests so the current clean state cannot regress.

### 8.8 Rule correction — LAY-001

`LAY-001` currently forbids all Doctrine imports in Domain, but `AGENTS.md` and
`config/packages/doctrine.yaml` require ORM-mapped aggregates in
`src/<Context>/Domain/Model`. The rule is wrong, not the code.

Amend LAY-001 and `guard-domain-imports.sh`:

- Allowed in Domain/Model: `Doctrine\ORM\Mapping` attributes,
  `Doctrine\Common\Collections` collection types (required by ORM to-many
  associations), `Symfony\Component\Uid` value objects.
- Still forbidden in Domain: `Doctrine\DBAL\*`, `Doctrine\ORM\EntityManager*`,
  `Doctrine\Persistence\*`, `Symfony\Component\HttpFoundation\*`,
  `App\*\Infrastructure\*`, `App\*\Application\*`.

Record the change in a new ADR. This removes 4 false-positive findings without
weakening DIP-001.

## 9. Enforcement design

### 9.1 Static analysis (PHPStan)

Extend `.agents/phpstan/`:

1. **`NoDoctrineInApplicationRule`** — every class in `src/*/Application/**`
   must not type-hint or import `Doctrine\DBAL\*`, `Doctrine\ORM\*`,
   `Doctrine\Persistence\*`, `Symfony\Component\HttpFoundation\*`, or
   `App\*\Infrastructure\*`.
2. **`ControllerThinRule`** — every class in `App\*\Infrastructure\Http\*`
   ending in `Controller` may inject only the allowed list in §8.2.
3. **`OneHandlerPerClassRule`** — at most one `#[AsMessageHandler]` attribute
   per class.
4. **`InterfaceMethodLimitRule`** — interfaces in Domain/Application have at
   most 3 methods.
5. **`ConstructorDependencyLimitRule`** — service classes (Controller, Handler,
   Subscriber, Adapter, Store, Repository, Finder, Indexer, Checker, Guard,
   Policy, Service) have at most 5 constructor dependencies.
6. **Extend `NoInfrastructureInHandlerRule`** — add `Connection`,
   `Doctrine\DBAL\*`, `Doctrine\Persistence\*`, `HttpFoundation\*` to the
   forbidden types.

Each rule gets unit tests under `tests/Unit/PhpStan/` using PHPStan's
`RuleTestCase`. The tests are written first (RED), then the rule.

### 9.2 Architecture tests

Add `tests/Architecture/SolidBoundariesTest.php`. It parses `src/` with
`nikic/php-parser` (already a PHPStan dependency) and asserts:

- Application has no forbidden imports or constructor types.
- Controllers have no forbidden constructor types and no
  `Connection`/repository/read-model/SQL usage.
- At most one `#[AsMessageHandler]` per class.
- Domain/Application interfaces have ≤3 methods.
- Service constructors have ≤5 dependencies.
- Ports live in Domain/Application; implementations live in Infrastructure.
- No `ContainerInterface`, `->get(`, or `static::getContainer()` in `src/`
  outside `Kernel`/compiler passes.
- No `match (true)` or `match` on type strings in Application/Domain
  (OCP-002), replacing the current guard-hook heuristic.

The test uses data providers so a failure names the exact file and rule.

### 9.3 Guard hooks

- `guard-domain-imports.sh` and `guard-cqrs.sh` keep their current rules but:
  - run over **all** `src/**` files, not only changed files, in CI and
    `pre-finish`;
  - lose the `match (true)` false positive (the architecture test owns OCP-002);
  - keep `ERR-003` and `CMD-003` checks.
- Add `bin/solid-check`:
  ```
  phpunit --testsuite Architecture
  phpstan analyse --no-progress
  deptrac analyse --config-file=deptrac.yaml
  bash .agents/hooks/guard-domain-imports.sh $(find src -name '*.php')
  bash .agents/hooks/guard-cqrs.sh $(find src -name '*.php')
  ```
  Any non-zero exit fails.
- Add `make solid` that runs `bin/solid-check` in the app container.
- CI: a new `solid` job runs `make solid` **in parallel** with the test jobs.
  It is a required status check, not a dependency of the test jobs, so the
  regression suites keep running while the remediation branch is red. The
  `solid` job starts PostgreSQL and applies migrations because
  `CatalogApprovalDesignTest` boots the kernel.

### 9.4 TDD enforcement

**`bin/tdd-check`** (new, executable, host-side bash — the app image has no
`git`):

- Input: `--staged` (pre-commit), `--range <base>...HEAD` (CI), or
  `--self-test` (fixture directories).
- **Behavior mode (default):** changed `src/**/*.php` files must be accompanied
  by at least one changed `tests/**/*.php` file that either references the
  changed class short name/FQCN or lives in the same bounded context
  (`tests/<Suite>/<Context>/...`).
- **Refactor mode (`--refactor`):** the changed class must already be
  referenced by a test anywhere under `tests/`, and the commit subject must
  start with `refactor:`. No new class is allowed in this mode. This is the
  REFACTOR step of the TDD cycle: the safety net already exists, so no
  artificial test edit is required. The full suite and Infection still gate the
  change.
- A new class (`--diff-filter=A`) always requires a new test that references
  it; refactor mode is rejected for additions.
- If `src/` changed and no test changed in behavior mode → fail.
- If a changed class has no matching test → fail with the file and the expected
  test location.
- Exempt: `src/Kernel.php` and pure configuration. No other exemptions.
- `--self-test` runs the script against fixture repositories (src-only change,
  src+test change, new class with/without a test, refactor with/without
  coverage) and asserts pass/fail. It runs on the host in CI, not inside the
  container.

**Strengthen `.agents/hooks/tdd-gate.sh`:**

- A `RED:` line must include a test path under `tests/`, a quoted assertion,
  `cmd:` and `→ FAIL`.
- The referenced test file must exist and be modified/added in the working
  tree.
- The state file must exist and the task id must be supplied.
- Block otherwise.

**CI:**

- New `tdd` job on pull requests: runs `bin/tdd-check --range
  origin/<base>...HEAD` on the host (checkout has git).
- The existing Infection job stays and mutates changed
  `src/*/Domain/**` and `src/*/Application/**` files with
  `--min-msi=85 --min-covered-msi=90` (TDD-010). Infrastructure adapters are
  covered by contract/integration tests, not mutation thresholds.
- `pre-finish.sh` runs `bin/tdd-check`, the Architecture suite, and
  `bin/solid-check`.

**Local:**

- `make tdd` runs the host `bin/tdd-check` (staged or `HEAD~1`) and then all
  six PHPUnit suites plus Infection on changed Domain/Application files.
  This is the authoritative TDD gate; `make solid` is the SOLID gate.
- Document `git config core.hooksPath .githooks` and add
  `.githooks/pre-commit` that runs `bin/tdd-check --staged` and the fast
  static part of `bin/solid-check` so an agent cannot commit a violation
  locally.

### 9.5 Gate tests

The gates themselves are TDD'd:

- `bin/tdd-check --self-test` runs the script against fixture directories (a
  src-only change, a src+test change, a new class with/without a test, a
  refactor with/without pre-existing coverage) and asserts pass/fail. It runs
  on the host in CI, so it does not need git in the app image.
- PHPStan rule tests cover each new rule.
- `tests/Architecture/SolidBoundariesTest.php` is the executable spec of the
  rulebook.

## 10. Work breakdown

### Phase 0 — Green baseline and safety net

Two possible bases; this is the one open question for the user (§15):

- **0A (recommended): finish spec-run-15 first.** WP1, WP2 and WP5a are done;
  WP4 (inventory) is in progress and WP3/WP6 are not done. Finish them, then
  fix the 2 Architecture failures and the 2 Functional failures and get
  PHPStan to zero. The refactor touches `AdminProductController` and
  `CatalogSubmissionStore`, so starting from a finished feature avoids
  rework and conflicts.
- **0B: stash the unfinished work and branch from the last commit
  (`4df3f04`).** Fix the pre-existing failures on that base, start the
  refactor, and rebase spec-run-15 afterwards. Faster to start, but the two
  overlapping files will conflict and the current spec-run-15 context is
  lost until rebased.

In either case:

- Record the baseline (suites, PHPStan, Deptrac).
- Add characterization tests where a migrated handler has no direct coverage.

Gate: all six suites + PHPStan + Deptrac green.

### Phase 1 — Gates first (RED)

- Write `tests/Architecture/SolidBoundariesTest.php` and
  `tests/Architecture/TddHarnessTest.php`.
- Write PHPStan rule tests.
- Add `bin/solid-check`, `bin/tdd-check`, `make solid`, `make tdd`, CI jobs.
- Run the gates: they fail on the current code. Record the failure as the RED
  for the remediation.

### Phase 2 — Structural fixes (SRP-001, OCP-001, ISP-001, ISP-003, OCP-002)

- Split the 4 multi-message handler classes.
- Split `CatalogSubmissionStore` into 6 narrow ports + the existing repository.
- Reduce the 4 fat constructors.
- Replace `match (true)` in `ApprovalStatus` and `ReadinessService` with
  explicit logic.
- Fix `CMD-003` and `ERR-003` hooks.

Gate: Architecture suite passes for SRP/ISP/OCP.

### Phase 3 — DIP-003 by context

Order: Identity (13) → Catalog (17) → Ordering (5) → Payment (2) → Shared (2).

For each handler/service:

1. Write the contract test for the new port (RED).
2. Add the port interface.
3. Add the Infrastructure adapter.
4. Move the SQL from the Application class to the adapter.
5. Bind the port in `services.yaml`.
6. Run the context's functional tests + Architecture suite.

Gate: zero `Doctrine\DBAL\Connection` in Application.

### Phase 4 — SRP-002 controllers (12 controllers)

1. Non-SQL but fat controllers: `CheckoutOrder` (delete `CheckoutSaga`, move
   settings), `GoogleOAuth` (move exchange behind `GetGoogleUser`),
   `AdminBanner` (move `BannerRules`), `AdminSettings` (move settings read),
   `Wishlist` (move product check).
2. Non-legacy SQL controllers: `AdminCustomer`, `AdminOrder`, `AdminPayment`
   move their audit/state reads to query handlers.
3. The 4 ADR-0007 legacy controllers (`AdminProduct`, `AdminCategory`,
   `AdminBrand`, `AdminPromoCode`) move to command/query dispatch.
4. Delete controller SQL; controllers end with only allowed dependencies.

Gate: every controller passes `ControllerThinRule`; no SQL in
`src/*/Infrastructure/Http/**`.

### Phase 5 — LSP and static analysis

- Add the four repository contract tests; make each adapter test extend them.
- Add the `PaymentInitiationPort` contract test.
- Install `phpstan/phpstan-strict-rules`; fix all findings.
- Re-run Infection on touched Domain+Application code; meet MSI thresholds.

Gate: `make solid` and `make tdd` green.

### Phase 6 — Documentation and final verification

- Supersede ADR-0007 (legacy admin write path is migrated).
- Amend ADR-0014 (one submission adapter, many narrow ports).
- Add an ADR for the LAY-001 correction and the enforcement model.
- Update `AGENTS.md` with the new gates and commands.
- Run the full CI sequence locally; attach the evidence.

## 11. TDD strategy

- **New behavior** (ports, adapters, query handlers, gate scripts): classic
  RED → GREEN. The test is committed before the implementation.
- **Behavior-preserving refactor** (moving SQL, splitting handlers): a
  characterization test runs green before the refactor and stays green after.
  The existing 139 test files plus the new contract tests are the safety net.
  This is the REFACTOR step of the TDD cycle, not a bypass.
- **Gates**: the architecture/PHPStan/TDD tests fail first (RED), then the code
  changes until they pass. This is the TDD cycle for the rulebook itself.
- **No test edits to reach GREEN.** If a gate finds a real violation, the
  production code changes. If a test's expectation is wrong (for example the
  hardcoded file list in `CatalogVisibilitySingleSourceTest`), the test is
  updated only when the production change is the intended one, and the reason
  is recorded in the task state.

## 12. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Large diff across 39 Application classes | Ports/adapters are mechanical; one context per commit; full suite after each context. |
| Behavior drift when moving SQL | Characterization tests per handler; compare query results before/after; run Functional suite per context. |
| Transaction/audit semantics break | `doctrine_transaction` remains the boundary; adapters never commit; contract tests assert audit+state atomicity. |
| Uncommitted spec-run-15 work conflicts | Phase 0 commits/stashes it; if it must stay in progress, do the refactor in a worktree from the same base. |
| PHPStan strict-rules surface many findings | Fix incrementally by context; no exclusions; the gate stays red until clean. |
| Infection runtime | Run on changed files per commit; full run only at the end. |
| TDD gate false positives | The same-context fallback; fixture tests for the gate; no silent bypass. |
| Gate rules conflict with existing ADRs | ADRs are amended/superseded explicitly; no rule is silently ignored. |

## 13. Definition of Done

- `make solid` green: Architecture suite, guard scripts over all `src/`,
  PHPStan level 8 + strict, Deptrac 0.
- `make tdd` green: host `bin/tdd-check`, Unit + Integration + Projection +
  Functional + Contract + Architecture suites, Infection thresholds on
  changed Domain/Application files. `make tdd` is the authoritative TDD gate;
  `make solid` is the authoritative SOLID gate; `make ci` runs both.
- Every audit finding in §6 closed or explicitly reclassified with a written
  ADR (expected: all closed).
- No allowlists, baselines, `@phpstan-ignore`, or path exclusions.
- ADR-0007 superseded; ADR-0014 amended; LAY-001 corrected; enforcement ADR
  written.
- `AGENTS.md` documents `make solid`, `make tdd`, and the no-bypass policy.
- Final report lists every changed file and the command output proving green.

## 14. Calls made by the designer

These are decisions I made; check them while reading:

1. **Ports/adapters, not full aggregates.** DIP-003 is fixed by moving SQL, not
   by inventing domain models for Identity/Payment.
2. **Seven narrow submission interfaces, one implementation.** This satisfies
   ISP-001 and preserves ADR-0014's single-writer/transaction rule.
3. **`doctrine_transaction` remains the only transaction boundary.** No
   `TransactionManager` port, no nested transactions.
4. **The four legacy controllers are migrated.** ADR-0007 is superseded; the
   user asked for zero exceptions.
5. **LAY-001 is corrected, not the code.** ORM mapping attributes and Doctrine
   collection types in Domain/Model are allowed; DBAL/EntityManager/HttpFoundation remain banned.
6. **TDD gate = co-change + reference heuristic + mutation testing.** It cannot
   prove the test was written first, but it blocks the "no test at all" case
   and Infection blocks weak tests.
7. **One branch, phased commits, gates enabled before the fixes.** The branch
   stays red until Phase 6; it is not merged until `make solid` and `make tdd`
   are green.
8. **`ControllerThinRule` is exhaustive.** All 12 controllers migrate; there is
   no controller allowlist. `CheckoutSaga` is deleted and `GoogleOAuth` moves
   its exchange behind a query.
9. **Legacy commands write the same tables through ports.** No new domain
   aggregate, no schema change; the goal is DIP, not a Catalog rewrite.
10. **TDD gate has a controlled `--refactor` mode.** It requires pre-existing
    test coverage and a `refactor:` commit subject; new classes always need a
    new test. This keeps behavior-preserving moves honest without artificial
    test edits.
11. **`bin/tdd-check` is host-side.** The app image has no git; pre-commit uses
    `--staged`, CI uses a range, and `--self-test` runs on the host.
12. **The submission store keeps `Connection::transactional()`.** It is the
    documented transaction-owner carve-out for a use case callable outside
    `command.bus`; ordinary command-path adapters do not get it.
13. **Infection scope is Domain + Application (TDD-010).** Infrastructure
    adapters are covered by contract/integration tests, not mutation
    thresholds.
14. **The CI `solid` job runs in parallel and is required.** Test jobs keep
    running while the branch is red; merge is blocked until every required
    check passes.

## 15. Open question

1. Which Phase 0 base do you want: **0A** — finish the in-progress spec-run-15
   inventory/projection work first, then start the SOLID remediation
   (recommended; avoids conflicts on `AdminProductController` and
   `CatalogSubmissionStore`), or **0B** — branch from the last commit
   `4df3f04`, start the remediation immediately, and rebase spec-run-15 later?

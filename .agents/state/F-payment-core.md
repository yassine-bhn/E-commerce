# Task F-payment-core

Type: feature
Started: 2026-10-22T22:05:00+01:00
Requirement: Close the payment half of O-05/O-07/O-08/O-19 and RP1.1..RP1.6 from `spec-run-5/gap-analysis.md` §5.4: a payment aggregate with an independent lifecycle, an append-only ledger, offline receipt confirmation, refunds and a configurable method allow-list.

## Spec traceability

- `spec-run-5/gap-analysis.md` §5.4 (O-05, O-07, O-08, O-19), §9 step 3 (depends on increments 1 and 2, both delivered by `F-inventory-settings`)
- BRS v1.0 §二.4 (independent payment lifecycle and ledger), §三.1.6-7 (offline receipt confirmation), §七 RP1.1-RP1.6, the legal transition table (§七.2)
- ADR-0001 (Payment context, `PaymentAuthorized`/`PaymentAuthorizationFailed` events, append-only `payment_ledger`, event sourcing rejected)
- ADR-0004 (payment authorization async; `VoidPayment` compensation)

## Human approvals

APPROVED: migrations (MIG-001) — user — 2026-10-22T18:39:12+01:00 (standing approval from the increment selection; exercised by `Version20261022000006`)
APPROVED: src/Payment (SEC-001) — user — 2026-10-22T18:39:12+01:00 (granted in the same approval question, unused by `F-inventory-settings`; exercised by this increment)
APPROVED: no new Composer/NPM dependency — user — 2026-10-22T18:39:12+01:00 (none planned: no PSP is integrated in this increment)

## Assumptions

- A1: no payment service provider is chosen yet. This increment records offline receipts (bank transfer, cash on delivery); card authorization and provider callbacks (O-06) need a provider decision and a new dependency, so they stay out.
- A2: the payment record is created synchronously inside the place-order transaction. ADR-0004 rejects a synchronous *authorization*, not a synchronous record: no gateway call happens on the request path.
- A3: the payment amount equals the order total captured at placement and never changes (RP1.5); the order totals are already immutable snapshots.
- A4: confirming a receipt carries the payment's own `expectedVersion`; the order transition is guarded by `status = 待支付` in the same command transaction. Two aggregates move in one handler, as accepted under A2 of `F-inventory-settings` (AGG-002).
- A5: the ledger is append-only by construction: no `UPDATE`/`DELETE` statement targets `payment_ledger` anywhere in `src/`.
- A6: refunds are offline records too: amount at most the authorized amount, original currency, no gateway call. Provider refunds belong to the same later increment as O-06.
- A7: the allow-list default holds the two methods the backend already receives (`Carte bancaire`, `Virement / Paiement à la livraison`) plus `Espèces à la livraison`, so no existing caller breaks.
- A8: an order that is no longer `待支付` cannot be confirmed as paid; the endpoint answers 409 and the ledger records nothing. RC8's "revive the cancelled order" needs a compensation path that does not exist yet — recorded as debt.

## Gates

GATE: PLAN ok — 2026-10-22T22:05:00+01:00 — test-planner (adopted in-session; subagents unavailable)
GATE: PATTERN skipped — 2026-10-22T22:06:00+01:00 — orchestrator — ADR-0001 already accepts the Payment context, its two cross-context events and the append-only ledger; ADR-0004 already fixes the saga shape. No bus, aggregate boundary or bounded-context boundary moves, so no new ADR is raised.
GATE: REFACTOR ok — 2026-10-22T21:12:00+00:00 — refactorer (adopted) — the version-guarded UPDATE + ledger INSERT was duplicated in `ConfirmOfflinePaymentHandler` and `RefundPaymentHandler`. Extracted to `Domain\Model\PaymentLedger` (interface), `Domain\Model\PaymentTransition` (value object) and `Infrastructure\Persistence\DbalPaymentLedger`, aliased in `config/services.yaml` like the other Domain repositories. Behavior-preserving: 11 payment tests / 115 assertions before and after, and the guarded write is now the only path that can move a payment.
GATE: SPEC-TESTS ok — 2026-10-22T21:29:00+00:00 — integration-test-agent + projection-test-agent + e2e-contract-test-agent (adopted). Integration: `tests/Integration/Payment/PaymentTransitionConcurrencyTest.php` (1 test, 10 assertions, deterministic over 3 consecutive runs) proves two real backends cannot both apply version 1 — the loser gets SQLSTATE 55P03 while the row is locked and `PaymentVersionConflictException` after the commit, and the ledger keeps exactly two rows. Also repaired two pre-existing tests the increment invalidated: `OrderConcurrencyAndProxyOrderTest` (handler gained a 4th dependency) and `PlaceOrderOutboxTest` (its fixed order id now also owns a payment row, so the purge has to include it). Projection: none — payment is read through a DBAL join over the write tables, no `*_view` table exists, so there is nothing to replay. E2e-contract: the admin payment API is not consumed by the storefront, and `AdminPaymentReadTest` already pins its exact JSON shape, so no `tests/Contract` file was added.

## Behavior plan

### Behavior 1: Checkout creates a pending payment that carries the locked order total
- Level: Functional
- Test path: tests/Functional/CheckoutPaymentTest.php
- Rule IDs: [ECO-003, CMD-005, MIG-001]
- Depends on: none

### Behavior 2: A proxy order creates the same pending payment record
- Level: Functional
- Test path: tests/Functional/AdminProxyOrderPaymentTest.php
- Rule IDs: [ECO-003, CMD-005]
- Depends on: 1

### Behavior 3: An admin confirms an offline receipt with the expected version
- Level: Functional
- Test path: tests/Functional/ConfirmOfflinePaymentTest.php
- Rule IDs: [AGG-001, CMD-005, EVT-002, SEC-002]
- Depends on: 1

### Behavior 4: A stale version is refused with 409 and writes nothing
- Level: Functional
- Test path: tests/Functional/ConfirmOfflinePaymentTest.php
- Rule IDs: [AGG-001, ERR-002]
- Depends on: 3

### Behavior 5: Confirming the same receipt twice is refused and the ledger keeps one entry
- Level: Functional
- Test path: tests/Functional/ConfirmOfflinePaymentTest.php
- Rule IDs: [ECO-005, AGG-001]
- Depends on: 3

### Behavior 6: A checkout payment method outside the configured allow-list is refused with 422
- Level: Functional
- Test path: tests/Functional/CheckoutPaymentMethodTest.php
- Rule IDs: [ERR-001, QRY-003]
- Depends on: 1

### Behavior 7: A refund records the movement and moves a paid order to refunded
- Level: Functional
- Test path: tests/Functional/RefundPaymentTest.php
- Rule IDs: [AGG-001, ECO-005, EVT-002]
- Depends on: 3

### Behavior 8: An admin reads the payment with its ledger, a customer cannot
- Level: Functional
- Test path: tests/Functional/AdminPaymentReadTest.php
- Rule IDs: [QRY-001, QRY-002, QRY-004, SEC-002]
- Depends on: 1

## RED record

RED: tests/Functional/CheckoutPaymentTest.php — 2026-10-22T22:12:41+01:00 — Error: SQLSTATE[42P01] relation "payment" does not exist at line 73. The checkout request itself answered 201 (first assertion passed), so only the persistence is missing. Migration `Version20261022000006` was not yet applied.
RED: tests/Functional/CheckoutPaymentTest.php — 2026-10-22T22:14:10+01:00 — Failure after applying the migration to app_test and dev: "checkout must record the payment it is waiting for" — Failed asserting that false is of type array (1 test, 2 assertions, 1 failure). `payment` and `payment_ledger` exist but stay empty: nothing in `PlaceOrderHandler` writes them.
GREEN: tests/Functional/CheckoutPaymentTest.php — 2026-10-22T22:21:35+01:00 — OK (1 test, 12 assertions). Added `Shared\Application\Port\PaymentInitiationPort` + `Payment\Infrastructure\Persistence\DbalPaymentInitiationAdapter` (insert payment + opening ledger row) and called it from `PlaceOrderHandler` after `$this->orders->save($order)`. Autowiring resolved the interface to the single implementation, as it does for `StockReservationPort`.
RED: tests/Functional/AdminProxyOrderPaymentTest.php — 2026-10-22T22:26:02+01:00 — Failure: "a proxy order owes money too, so it opens a payment" — Failed asserting that false is of type array (1 test, 2 assertions, 1 failure). `CreateAdminProxyOrderHandler` writes the order and its audit trail with raw SQL and never opens a payment.
GREEN: tests/Functional/AdminProxyOrderPaymentTest.php — 2026-10-22T22:28:44+01:00 — OK (1 test, 13 assertions). `CreateAdminProxyOrderHandler` now injects `PaymentInitiationPort` and opens the payment with the quoted total and `createdByAdminId` as ledger actor.
RED: tests/Functional/ConfirmOfflinePaymentTest.php — 2026-10-22T22:41:09+01:00 — Failure: assertResponseStatusCodeSame(200) got 404 "No route found for POST /api/v1/admin/payments/{id}/confirm" (1 test, 2 assertions, 1 failure). The same client re-login to the admin role already passes the `^/api/v1/admin` firewall, so the only gap is the endpoint.
GREEN: tests/Functional/ConfirmOfflinePaymentTest.php — 2026-10-22T22:52:37+01:00 — OK (1 test, 21 assertions). Added `PaymentStatus` + `IllegalPaymentStateTransitionException` + `PaymentNotFoundException`, `PaymentAuthorized`, `Shared\Application\Port\OrderSettlementPort` implemented by `Ordering\Infrastructure\Persistence\DbalOrderSettlementAdapter` (guarded `UPDATE ... RETURNING version` + audit row), the `ConfirmOfflinePayment` command/handler (payment update + ledger append + outbox append in one command transaction) and `Payment\Infrastructure\Http\AdminPaymentController` (rate-limited, `ROLE_ADMIN`).
RED: tests/Functional/ConfirmOfflinePaymentTest.php::testAStaleExpectedVersionIsRefusedAndWritesNothing — 2026-10-22T22:31:46+01:00 — Failure: assertResponseStatusCodeSame(409) got 200 with body {"paymentId":...,"status":"authorized","version":2,"orderStatus":"已支付"}. `ConfirmOfflinePaymentHandler` read `version` but never compared it with `expectedVersion`.
GREEN: tests/Functional/ConfirmOfflinePaymentTest.php — 2026-10-22T22:36:20+01:00 — OK (2 tests, 29 assertions). Added `PaymentVersionConflictException`, a version comparison before any write, `AND version = :expected_version` on the update with a 0-rows check, and the 409 `payment-version-conflict` mapping in the controller.
RED: tests/Functional/ConfirmOfflinePaymentTest.php::testConfirmingTheSameReceiptTwiceIsRefusedAndKeepsOneLedgerEntry — 2026-10-22T22:39:12+01:00 — Failure: assertResponseStatusCodeSame(409) got 500 because `IllegalPaymentStateTransitionException` had no controller mapping.
GREEN: tests/Functional/ConfirmOfflinePaymentTest.php — 2026-10-22T22:40:05+01:00 — OK (3 tests, 37 assertions). The controller maps `IllegalPaymentStateTransitionException` to 409 `payment-not-confirmable`; the ledger keeps exactly one `authorized` row and the order and outbox are untouched on the second click.
RED: tests/Functional/CheckoutPaymentMethodTest.php — 2026-10-22T22:47:31+01:00 — 2 failures: (1) a checkout with `Bitcoin` answered 201 instead of 422; (2) the settings PUT response had no `paymentMethods` key (null). 2 tests, 4 assertions.
GREEN: tests/Functional/CheckoutPaymentMethodTest.php — 2026-10-22T22:56:18+01:00 — OK (2 tests, 8 assertions). `PlatformSettingsRepository` now reads and decodes the allow-list (with a default fallback), `UpdatePlatformSettings`/handler persist it, `AdminSettingsController` validates it on PUT (optional field, so older clients keep the list) and returns it on GET, and `CheckoutOrderController` refuses a method outside it with 422 `payment-method-not-allowed` before any reservation. `AdminSettingsTest`/`AdminShippingPolicyTest`/`StorefrontReferenceContactTest` still green (11 tests).
RED: tests/Functional/RefundPaymentTest.php — 2026-10-22T23:03:22+01:00 — Failure: 404 "No route found for POST /api/v1/admin/payments/{id}/refund" (1 test, 3 assertions, 1 failure). The confirm leg of the fixture already passes.
GREEN: tests/Functional/RefundPaymentTest.php — 2026-10-22T23:12:40+01:00 — OK (1 test, 20 assertions; with the confirm file 4 tests, 58 assertions). Added `PaymentRefunded`, `RefundPayment` command/handler (same version + transition guards, ledger append, outbox append), `Shared\Application\Port\OrderSettlementPort::markRefunded` implemented with a `FOR UPDATE` read, the order enum decision and a guarded update, and `POST /api/v1/admin/payments/{id}/refund` (reason required).
RED: tests/Functional/AdminPaymentReadTest.php — 2026-10-22T20:59:39+00:00 — 2 failures of 3 tests (9 assertions): `testAdminReadsThePaymentWithItsLedger` and `testACustomerCannotReadThePayment` both got 404 `No route found for "GET /api/v1/admin/payments/{id}"`. The third test (`testAnUnknownPaymentIsNotFound`) passes vacuously today and must keep returning 404 once the route exists.
GREEN: tests/Functional/AdminPaymentReadTest.php — 2026-10-22T21:04:12+00:00 — OK (3 tests, 24 assertions). Added `Payment\Application\Query\GetPaymentDetail` (paymentId + requesterId + isAdmin, QRY-004), `Payment\Infrastructure\ReadModel\GetPaymentDetailHandler` (DBAL join on the order for the ownership filter, ledger ordered by version) on `query.bus`, and `GET /api/v1/admin/payments/{id}` in the existing controller via `MessengerQuery`.
QUALITY: phpunit 491 tests / 2943 assertions (OK, exit 0) — cs-fixer 0 of 411 files — phpstan `No errors` (one finding fixed in passing: `array_values()` on an already-list in `UpdatePlatformSettingsHandler`) — deptrac `Errors 0` (3656 violations are the pre-existing allow-list warnings, not errors).
ATTEMPTS: 0

## REVIEW — round 1 (five roles adopted read-only over the increment delta)

Files read for the review: `DbalPaymentLedger`, `PaymentTransition`, `PaymentLedger`, `ConfirmOfflinePaymentHandler`, `RefundPaymentHandler`, `DbalPaymentInitiationAdapter`, `AdminPaymentController` (full), `GetPaymentDetailHandler`, `GetPaymentDetail`, `OrderSettlementPort`, `DbalOrderSettlementAdapter`, `PaymentAuthorized`, `PaymentRefunded`, `CheckoutOrderController` (payment-method arm), `PlatformSettingsRepository`, `Version20261022000006`, `PaymentTransitionConcurrencyTest`, all six payment functional tests.

- code-reviewer — CR-001 MINOR: `DbalPaymentLedger::record()` throws `versionMismatch($id, $expected, $expected)`; after a real race the third argument should be the version the row actually holds, otherwise the 409 message tells the operator "actual version is 1" when it is 2. CR-002 NIT: `CheckoutPaymentTest::tearDown()` deletes the order, lines, reservation, payment and audit rows but not the `outbox` row for the order, so each run leaks one row (contrast `AdminPaymentReadTest`, which purges it).
- architecture-reviewer — AR-001 INFO/no action: `OrderSettlementPort` is a synchronous cross-context call inside the command transaction while `PaymentAuthorized` also goes to the outbox. Consistent today because nothing consumes the event, and a future consumer cannot corrupt the order (the `markPaid` guard only matches `待支付`), but it must be idempotent. AR-002 INFO: `payment.order_id` carries no FK to `ordering_orders`, the same deliberate cross-context decoupling as `inventory_reservation`; the read model joins instead. AR-003 INFO: the guarded write is now the only path that can move a payment, which is what AGG-001 asked for once the REFACTOR landed.
- security-reviewer — SEC-003 checked clean: `GET /api/v1/admin/payments/{id}` sits behind the class-level `ROLE_ADMIN`, the query carries `requesterId` + `isAdmin` and filters on the order's owner (QRY-004), every statement is parameterized, and the payload carries no PII beyond method and amount. Both POSTs are rate-limited and require `expectedVersion`; the GET is not rate-limited, matching the other admin reads.
- performance-reviewer — PRF-001 NIT: `idx_payment_ledger_payment (payment_id, created_at)` does not serve `ORDER BY version ASC`, but a payment has a handful of rows. PRF-002 INFO: the read is two queries (payment join order, then ledger), no N+1. `paymentMethods()` adds one primary-key lookup per checkout.
- test-reviewer — TDD-011 MINOR: the non-admin branch of `GetPaymentDetailHandler` is unreachable through HTTP (the only caller passes `isAdmin: true`) and no test exercises it, so the ownership filter is unproven code. TDD-012 NIT: `PaymentStatus::FAILED` has no producer and no test; it belongs to the deferred O-06 provider work and is recorded as debt rather than a gap.

FIXES clusters: F-1 = CR-001 + CR-002 (write-side accuracy and test hygiene); F-2 = TDD-011 (prove the ownership filter).

### FIXES round 1 — F-1 and F-2 — 2026-10-22T21:44:00+00:00

- F-1/CR-001: `DbalPaymentLedger::record()` now resolves the losing side of the guarded update through a private `currentVersion()`, so the 409 says "actual version is 2" instead of echoing the expected 1. `PaymentTransitionConcurrencyTest` asserts the message. Mutation check: restoring the old argument makes the new assertion fail with the exact old string (`Expected version 1, but actual version is 1`), restoring the fix passes.
- F-1/CR-002: `CheckoutPaymentTest::tearDown()` purges the `outbox` row of the created order, like `AdminPaymentReadTest` already did.
- F-2/TDD-011: added `tests/Integration/Payment/GetPaymentDetailAuthorizationTest.php` — a customer reads the payment of their own order, gets `null` for another customer's, and an admin reads any. The branch was already correct, so this is a characterization test rather than a RED→GREEN pair; its teeth are proven by mutation: replacing the guard with `if (false && !$query->isAdmin)` fails the "must be null" assertion (`Failed asserting that an array is null`), restoring it passes.
- Verification: full suite `OK (492 tests, 2950 assertions)`; cs-fixer `0 of 412 files`; phpstan `No errors`; deptrac `Errors 0` (3658 allowed, 0 errors).

### REVIEW re-run — 2026-10-22T21:47:00+00:00

The five roles re-read the FIXES delta (two production-side edits — `DbalPaymentLedger` and the read-model guard mutation, reverted to the original logic; two test files; no config, migration or route change) and confirmed both clusters are closed with no new finding. CR-001's message is now pinned by an assertion that fails on the old code; TDD-011's branch is proven and load-bearing. Gate REVIEW closes.

## Closure

DONE — 2026-10-22T21:50:00+00:00.

### Definition of Done

- Suites: `php bin/phpunit` → `OK (492 tests, 2950 assertions)`, exit 0 — Unit, Integration, Functional, Contract and Architecture in the one run.
- Static analysis: `phpstan analyse --no-progress` → `[OK] No errors`; `php-cs-fixer fix --dry-run` → `0 of 412 files`; `deptrac analyse` → `Errors 0` (3658 allowed, 177 warnings, all pre-existing multiple-layer notices).
- Migrations (MIG-001 standing approval): `Version20261022000006` applied to `app_test` and to the dev database, left uncommitted with the rest of the tree.
- ADR: none required — PATTERN skipped because ADR-0001 already accepts the context, its events and the append-only ledger, and ADR-0004 already fixes the saga shape.
- TDD: every `src/**` behavior change carries a `RED:` line except the REFACTOR (behavior-preserving, proven by identical 11 tests / 115 assertions before and after) and F-2 (test-only). `ATTEMPTS: 0` — no behavior needed a third cycle.
- State/audit: gates, RED record, REVIEW, FIXES, REVIEW re-run and this closure written; `.agents/audit/F-payment-core.log` carries the gate lines.
- Gates: PLAN → PATTERN skipped → RED/GREEN ×8 → REFACTOR → SPEC-TESTS → REVIEW (5 roles) → FIXES (F-1, F-2) → REVIEW re-run → DONE.

### Behaviors delivered

All 8 planned behaviors shipped: O-05 (a payment aggregate with an independent lifecycle and an append-only ledger, opened by checkout and by proxy orders), O-07 (offline receipt confirmation with the legal transition table), O-08 (refund), O-19 (operator-configurable payment method allow-list enforced at checkout), and RP1.1–RP1.6 (version increment, base-version submit, no illegal transition, no implicit retry — 409 rather than a silent second application, amount frozen at placement, full audit trail). RP1.7 is only half closed: the ledger is the data a reconciliation job needs, but no job exists (see debt).

### Recorded debt (not fixed in this increment)

| Rule | Finding | Why it is debt |
|---|---|---|
| O-06 / A1 | No payment service provider, no card authorization, no webhook endpoint | Needs a provider decision and a new dependency; the standing approval covers neither |
| O-20 / RP1.7 | No reconciliation job over `payment_ledger` | A reconciliation needs a provider statement to compare against, so it waits for O-06 |
| RC8 / A8 | A paid order that is cancelled cannot be revived; only refunded | The revive path is a compensation saga (`VoidPayment`/`ReleaseStock`), which ADR-0004 assigns to a later increment |
| AR-001 | `PaymentAuthorized` / `PaymentRefunded` reach the outbox but have no consumer | Intentional: ADR-0001 names them as the future Payment → Ordering signal; a consumer must be idempotent because the order already moves synchronously through `OrderSettlementPort` |
| TDD-012 | `PaymentStatus::FAILED` has no producer and no test | It exists for the provider failure path (O-06) |
| EVT-002 | Neither `DbalOrderSettlementAdapter` nor the pre-existing `TransitionOrderStatusHandler` appends `OrderStatusChanged` | Pre-existing gap; the order status is transactionally consistent but not announced. Adding the event changes the outbox contract, so it belongs in its own task |
| PRF-001 | `idx_payment_ledger_payment (payment_id, created_at)` does not serve `ORDER BY version` | A payment has a handful of ledger rows; revisit only if the table grows |
| AR-002 | `payment.order_id` has no FK to `ordering_orders` | Deliberate cross-context decoupling, same as `inventory_reservation`; the read model joins instead |
| TDD-010 | Infection could not run | No coverage driver in the image (`pcov`, `phpdbg`, `xdebug` all absent); MSI ≥ 85 stays open for an image built with `--build-arg INSTALL_XDEBUG=true` |

### Open questions for the human

1. Proxy orders bypass the storefront allow-list: `AdminProxyOrderPaymentTest` uses `Virement bancaire / WhatsApp`, which the operator cannot enable through the settings endpoint. Is the operator free to record any method on a proxy order (current behaviour), or should the admin endpoint validate too?
2. RC8: when a paid order is cancelled, should the system always refund, or offer a "revive" compensation? The answer decides whether the next increment is a refund-only path or a saga.
3. EVT-002: schedule the `OrderStatusChanged` outbox append as its own task? It touches the pre-existing transition handler as well, so it is a bus-contract change rather than a silent fix.



# Task V-backend-feature-gap
Type: review-only
Started: 2026-10-22T17:57:40+01:00
Requirement: What features are missing in the backend? (Corrected prompt, `spec-run-5/spec.md`)

## Spec traceability
- Spec: spec-run-5/spec.md (version 1)
- Prompt archive: spec-run-5/prompt.md
- Stage 4 summary: coverage ok — R1–R14 met; only `spec-run-5/**` created, no `src/**`, `tests/**` or config changes
- Requirements: R1, R2, R3, R4, R5, R6, R7, R8, R9, R10, R11, R12, R13, R14

## Gates
GATE: SPEC ok — 2026-10-22T17:57:40+01:00 — orchestrator
GATE: REVIEW ok — 2026-10-22T17:59:37+01:00 — orchestrator (5 roles adopted in-session; 1 major finding fixed)
GATE: DONE — 2026-10-22T17:59:37+01:00 — orchestrator

## Reviews
Subagent dispatch failed with `40506 model inherit not found or has been deprecated`; per the spec skill's Stage 5 fallback, each role was adopted in-session by reading `.agents/agents/<name>.md` in full. No reviewer wrote any file.

| Role | Result | Findings | Evidence |
|------|--------|----------|----------|
| architecture-reviewer | ok | none | 8 contexts, 3 empty; `CheckoutSaga` is a dispatch wrapper only; outbox relay and Meilisearch present; ADR-0007 legacy exception accepted. Rules LAY-001–004, BUS-001/002, EVT-001/002 checked clean. |
| code-reviewer | ok | none | 20+ claims spot-checked: `STOCKS = ['in','order','pickup','out']`; `basisPoints 1..10000`; `IsGranted` matrix; admin route table; migration columns; `PlaceOrder` writes no audit row. Counts re-checked after the security fix. |
| security-reviewer | 1 major | I-21 | `GET /api/v1/storefront/orders/track/{orderNumber}` is public, unthrottled and calls `GetStorefrontOrder($orderNumber, null)`; handler skips the ownership predicate when the id is null (SEC-002/QRY-004). Fixed in `gap-analysis.md` §5.7; counts moved 40→41. No card data, secrets or injection findings. |
| performance-reviewer | ok | none | Order OCC (`version`, 409), no catalog version predicate, no stock lock, non-atomic last-super-admin count: all described correctly. No statement claims a lock or index that does not exist. |
| test-reviewer | 1 minor | evidence only | `tests/Unit/Identity/UserManagementRulesTest.php:97-108` encodes anonymization without a status check (strengthens I-06); `tests/Functional/StorefrontApiTest.php:255` covers public tracking (I-21 is intentional but contradicts the matrix). No report claim is contradicted by a test. |

## Stage 4 coverage
See `spec-run-5/coverage.md` — R1–R14 met; 41 unique gaps (27 must / 13 should / 1 could), 13 implemented baseline rows.

## Closure (review-only DoD)
- Unit, Integration, Projection, Functional, Contract suites: N/A — no production code, tests, config or migrations changed.
- phpstan, php-cs-fixer, deptrac, infection: N/A for the same reason; not run.
- Reviews: five roles, one major finding fixed in the report, one evidence-strengthening note applied.
- ADR: none required.
- State file at DONE; audit log complete.
- Residual risks: assumption A2 (empty contexts may be deliberate) and A5 (partial counts as a gap) remain live.

## Human approvals
None required: review-only, no production code, migration, dependency or `src/Payment/**` change.

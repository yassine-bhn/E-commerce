# Task F-fix-six-high-audit-findings

Type: feature
Started: 2026-10-29T01:20:00+01:00
Requirement: Use the repository's `/spec` workflow, then fix the six confirmed HIGH-severity findings H1–H6 from the preceding audit: approval write model, reviewer-save audit, inventory balance, TND millimes, checkout reconfirmation, and projection replay.

## Spec traceability

- Spec: `spec-run-15/spec.md` (version 2)
- Prompt archive: `spec-run-15/prompt.md`
- Audit context: `spec-run-15/context.md`
- Stage 4 summary: three analysts + synthesizer completed; spec v2 resolves the user's decimal-DT requirement (millimes, not whole dinars) and the approval behaviour fork (keep reviewer-save, route it through the write model).
- Requirements: R1–R37
- Work packages: WP1–WP7

## Decisions carried from the spec

- H1: `catalog_submission` keyed by `(kind, slug)` is the approval authority; synchronous projection into both view tables; outbox event per transition; operator backfill; `projection:replay catalog-submission`.
- H2: a Super Admin save remains the approval, but it goes through the write model and writes `CATALOG_SUBMISSION_APPROVED` in addition to the content-update audit entry.
- H3: `inventory_stock` (`on_hand`, `reserved`) is authoritative; the view's `stock_quantity` is a synchronous cache; atomic reserve/release; operator backfill; `projection:replay inventory-stock`.
- H4: TND has three decimals (millimes). Existing whole-dinar data is converted ×1000 once by `Version20261029000003`. Admin input accepts decimal DT; storage and APIs remain integer minor units.
- H5: cart exposes current price and `priceChanged`; checkout requires the full current quote and returns 409 without side effects or idempotency-key consumption.
- H6: `bin/console projection:replay <name>` with `catalog-product-view`, `catalog-submission`, `inventory-stock` and `--dry-run`; migration read-model guard.

## Human approvals

APPROVED: decimal TND (millimes, 3 decimals) — user — 2026-10-29T01:10:00+01:00 — "je veux ajputer par exemple 15.3 dt or 153894.32 dt"
APPROVED: migrations `Version20261029000001`, `Version20261029000002`, `Version20261029000003` (MIG-001) — user — 2026-10-29T01:15:00+01:00 — SQL preview dry-run passed and rolled back

## Assumptions

- A1: Only H1–H6 are in scope; medium/low audit findings stay out.
- A2: TND has three decimals for this business; sub-millime amounts are rejected.
- A3: The reviewer-save behaviour is intentional; the defect is its silence/mislabel, not the feature.
- A4: Product/category content remains in the existing view tables; only approval and stock columns are replayable.
- A5: `stockQuantity` in the admin form means available quantity; `on_hand = available + reserved`.
- A6: A tracked product with no `inventory_stock` row fails closed after the backfill.
- A7: Publication (`published`/`hidden`) stays outside `catalog_submission`.
- A8: `confirmedQuote` is an inline JSON object; no signed token or server-side quote store.
- A9: Backfills are operator-run, dry-run by default, idempotent.
- A10: `_minor` names stay; their meaning becomes millimes for TND.
- A11: Tests may be updated only for the intentional contract changes in the spec.

## Gates

GATE: SPEC ok — 2026-10-29T01:20:00+01:00 — orchestrator — spec v2 synthesized, user decisions folded in
GATE: PLAN ok — 2026-10-29T01:22:00+01:00 — orchestrator — WP1 delegated first; WP5a money backend delegated next
GATE: RED/GREEN/REFACTOR ok — 2026-10-29T01:45:00+01:00 — WP1 worker — projection:replay + catalog-product-view; 8 tests / 39 assertions, Projection suite 28/110, phpstan and deptrac clean
GATE: WP5a dispatched — 2026-10-29T01:50:00+01:00 — orchestrator — millimes migration + Currency/Money + admin price parsing + backend test conversion
GATE: WP5a ok — 2026-10-29T02:20:00+01:00 — WP5a worker — migration applied dev+test, Unit 321/321, Integration 82/82, Functional 311/313 (3 known unrelated failures)
GATE: WP2 dispatched — 2026-10-29T02:25:00+01:00 — orchestrator — approval write model, store, projector and catalog-submission replay; brief at spec-run-15/wp2-brief.md
GATE: WP2 ok — 2026-10-29T03:05:00+01:00 — WP2 worker — migration 000001 applied dev+test; store/projector/replay green (Unit 13/59, Integration 15/154, Projection 5/41); review APPROVED, minors closed (rejected reviewer-save, non-pending decide, trimmed reason, ADR text)
GATE: WP4 dispatched — 2026-10-29T03:10:00+01:00 — orchestrator — inventory_stock, atomic reserve/release, admin stock port, backfill and inventory-stock replay; brief at spec-run-15/wp4-brief.md
GATE: WP4 ok — 2026-10-29T03:55:00+01:00 — WP4 worker — migration 000002 applied dev+test; Integration 106/106, Projection 37/37, Unit 334/334, Contract 16/16; review APPROVED, replay report directions corrected; Functional 2 known failures, Architecture 2 WP3-owned
GATE: WP3 dispatched — 2026-10-29T04:00:00+01:00 — orchestrator — route all approval paths through the WP2 store, approval reads from catalog_submission, deletion cleanup, backfill import and design/fixture updates; brief at spec-run-15/wp3-brief.md
GATE: WP5b ok — 2026-10-29T04:35:00+01:00 — WP5b worker — decimal DT helpers, SettingsPanel, ProductSheet, Listing, admin API types; RED missing helpers + formatDT "15 300 DT"; GREEN 27 assertions; typecheck/lint/build pass; review APPROVED (one latent negative-sign nit, no caller)
GATE: WP3 ok — 2026-10-29T05:00:00+01:00 — WP3 worker (finished by orchestrator) — import() added; all approval paths routed through store; reads from catalog_submission; deletion cleanup; backfill rewritten; dev 6399 imported/0 approved, test 43 imported/4 approved; Unit 334/334, Integration 107/107, Projection 37/37, Architecture 30/30, Functional 315 (1 flaky customer pagination, unrelated); PHPStan clean; cs-fixer clean
GATE: WP6 ok — 2026-10-29T06:00:00+01:00 — WP6 worker — cart priceChanged + currentUnitPrice; quote endpoint lines/promoCode/deliveryMethod; PlaceOrder confirmedQuote + PriceChangedException 409; frontend warning + manual confirm + idempotency reuse; Unit 334/334, Integration 107/107, Projection 37/37, Functional 315/315, Contract 16/16, Architecture 30/30; phpstan/deptrac/csf clean; npm typecheck/lint/build pass
GATE: WP7 ok — 2026-10-29T06:30:00+01:00 — orchestrator — MigrationReadModelGuardTest (3 tests), release.sh inventory backfill, DATABASE-ARCHITECTURE + runbook docs; full regression Unit 334/334 Integration 107/107 Projection 37/37 Functional 315/315 Contract 16/16 Architecture 30/30; phpstan 0, deptrac 0, cs-fixer 0, harness-check ok

# WP1 — Baseline, categories and verification method

## Baseline
- Primary: `docs/BUSINESS-REQUIREMENTS-SPECIFICATION.md` v1.0 (approved for implementation). It defines the permission matrix, business concepts, catalog/promotion/user/checkout rules (RG1.1–RG4.1), race conditions (RC1–RC13), payment/order concurrency rules (RP1.1–RP1.7) and the legal order/payment state table.
- Secondary, reported separately: claims in `AGENTS.md` and `docs/adr/**` that the code does not satisfy. These are architecture-conformance findings, not baseline features.
- Not used: the storefront repository, README marketing copy, and existing tests as a requirements source.

## Scope
- `E-commerce/` only: `src/**`, `config/**`, `migrations/**`, registered routes, and the test tree where it evidences behavior.
- The public HTTP API is the observable boundary; CQRS internals are inspected where they implement a business rule.

## Finding categories
| Category | Meaning |
|----------|---------|
| absent | No implementation of the baseline item exists. |
| partial | Some implementation exists, but the baseline rule cannot be satisfied end to end. |
| contradictory | An implementation exists but enforces different behavior, permissions or limits than the baseline. |
| intentionally deferred | No implementation and repository evidence (ADR, comment, explicit spec decision) says it was deferred on purpose. |
| extra | Implemented capability outside the BRS; listed once, never counted as a gap. |

## Verification method
1. Whole-tree search in `src/`, `config/` and `migrations/` for each capability (class names, table names, route paths, keywords). A feature is never declared absent from a single directory listing.
2. Registered routes read from the running stack: `docker compose exec app php bin/console debug:router` (dev database and app container are up; checked 2026-10-22).
3. Schema from `migrations/*.php` (table and column definitions) — the migrations are the source of truth for stored fields.
4. Each finding records at least one path/symbol, route or table as evidence.
5. Cross-check of `AGENTS.md` lines 8–23 and `docs/adr/**` against the code for the conformance section.

## Limits
- No end-to-end payment, concurrency or load test was executed; runtime claims are limited to route registration and source behavior.
- Seed/fixture data was not audited beyond the seeded shipping policy and catalog fixtures.
- The analysis reflects the working tree on 2026-10-22, not a released tag.

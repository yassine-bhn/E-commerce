# Spec: Backend missing-feature gap analysis
Version: 1

## Goal
Produce an evidence-backed inventory of the features the `E-commerce` backend does not provide, measured against the approved business requirements, with each finding classified, prioritized and traced to code — without changing any production code.

## Corrected prompt
What features are missing in the backend?

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The report states its baseline explicitly: `docs/BUSINESS-REQUIREMENTS-SPECIFICATION.md` v1.0 defines required business behavior; `AGENTS.md` and the ADRs are used only for the separate architecture-conformance section. | constraint | "missing features" requires a stated baseline | must |
| R2 | The analysis is scoped to the `E-commerce` backend; the `Zidane_front_web` storefront is not assessed. | constraint | "in backend" | must |
| R3 | Every finding is classified as absent, partial, contradictory, or intentionally deferred, and capabilities outside the baseline are listed separately as extras. | functional | "missing features" is broader than "not implemented" | must |
| R4 | Every finding cites its baseline item (permission-matrix row, RG/RC/RP rule, state-table transition or BRS section) and at least one verifiable evidence point: source path with symbol, registered route, table or migration. | quality | the answer must be auditable | must |
| R5 | The permission matrix, catalog rules RG1.1–RG1.5, promotion rules RG2.1–RG2.5, user rules RG3.1–RG3.6, checkout rule RG4.1, race conditions RC1–RC13, concurrency rules RP1.1–RP1.7 and the order/payment state table are each assessed as implemented, partial, contradictory or absent. | functional | "features" includes business rules, not only endpoints | must |
| R6 | All eight bounded contexts are covered, and contexts containing no implementation (currently Payment, Inventory, Shipping) are named explicitly. | functional | "in backend" | must |
| R7 | Every finding states its business impact and a priority of must, should or could. | quality | the result must support planning | must |
| R8 | A feature is declared absent only after whole-tree verification across `src/`, `config/` and `migrations/` (and the registered route table); the verification method is stated in the report. | quality | prevents false "missing" claims | must |
| R9 | Claims in `AGENTS.md` or the ADRs that the code does not satisfy are reported in a separate architecture-conformance section. | quality | repository expectations exceed the baseline | should |
| R10 | Capabilities implemented beyond the baseline (for example wishlist, reviews, newsletter, quote requests) are listed once as extras and not counted as gaps. | quality | prevents scope confusion | should |
| R11 | The report ends with a suggested implementation sequence ordered by dependency and business risk, without effort estimates. | quality | the reader needs to know what to build first | should |
| R12 | Unverified areas, assumptions and evidence limits are stated at the end of the report. | quality | honesty about what was not exercised | should |
| R13 | No production code, route, migration, configuration or test is created or modified in this run. | constraint | the prompt is a question, not an implementation request | must |
| R14 | The report is written in English and saved to `spec-run-5/gap-analysis.md`. | constraint | repository convention for spec runs | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The BRS in `docs/` is the authoritative definition of required platform behavior. | low |
| 2 | Empty `src/Payment`, `src/Inventory` and `src/Shipping` contexts are unfinished scope, not deliberate deferrals; the report flags this ambiguity. | med |
| 3 | The expected deliverable is an analysis report; implementing the gaps becomes separate feature tasks. | low |
| 4 | The current working tree (source, routes, migrations) represents the real backend behavior closely enough for a gap analysis. | low |
| 5 | A partial implementation still counts as a gap when the business rule cannot be satisfied end to end. | med |
| 6 | `AGENTS.md` and the ADRs are treated as internal expectations worth checking, not as a second requirements baseline. | low |

## Open questions
- [minor] Should effort estimates be included? Default if unanswered: no — sequence by dependency and risk only.
- [minor] Should capabilities implied by the storefront but absent from the BRS be counted? Default if unanswered: no — listed as open questions, not gaps.
- [minor] Is a different baseline intended (frontend feature list, roadmap)? Default if unanswered: BRS v1.0, because it is the only approved requirement source in the repository.
- [minor] Should implementation follow this run? Default if unanswered: no — separate feature tasks after the report is reviewed.

## Out of scope
- Implementing, prototyping or scheduling any missing feature.
- Changes to `src/**`, `tests/**`, `migrations/**`, routes, configuration or the storefront repository.
- Performance profiling, penetration testing, mutation testing and code-quality review.
- Effort estimates, budgets or staffing.
- Replacing or extending the BRS itself.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Fix the baseline, categories and verification method | R1, R8, R12 | BRS, AGENTS.md, ADRs, route table | Baseline and method section | `spec-run-5/sections/baseline-method.md` | none | Baseline is named, the four finding categories and extras rule are defined, and the verification method is stated |
| WP2 | Assess catalog and promotions | R3–R7 (RG1.x, RG2.x, RC4–RC6, permission rows for catalog/promotions) | Catalog and Promotions source, routes, migrations, BRS | Catalog/promotions findings | `spec-run-5/sections/catalog-promotions.md` | WP1 | Every assigned rule has a status, evidence, impact and priority |
| WP3 | Assess ordering, payment, inventory and shipping | R3–R7 (order state table, RC1–RC3, RC7–RC10, RP1.x) | Ordering/Payment/Inventory/Shipping source, routes, migrations, BRS | Order-lifecycle findings | `spec-run-5/sections/order-lifecycle.md` | WP1 | Every assigned transition, race condition and concurrency rule has a status, evidence, impact and priority |
| WP4 | Assess identity, access control, audit and platform configuration | R3–R7 (RG3.x, RG4.1, permission rows, BRS §五.4) | Identity source, `security.yaml`, routes, BRS | Identity/platform findings | `spec-run-5/sections/identity-platform.md` | WP1 | Every assigned rule and platform capability has a status, evidence, impact and priority |
| WP5 | Consolidate, cross-check and publish the report | R1–R14 | WP1–WP4 sections, AGENTS.md, ADRs | Consolidated gap report | `spec-run-5/gap-analysis.md` | WP1, WP2, WP3, WP4 | No finding is duplicated or unverified; extras and architecture-conformance items are separated; sequence and unverified areas are stated |

## Decisions (synthesizer only)
| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Baseline | Unknown; assumption that one exists in the repo | BRS v1.0 | BRS + AGENTS.md/ADRs; marked BLOCKING | BRS v1.0 as the single requirements baseline; AGENTS.md/ADRs only for a separate conformance section | The BRS is the only approved requirement source; mixing it with architecture notes would blur "required" and "claimed" |
| Deliverable | Written answer | Report | Report vs implementation; marked BLOCKING | Report at `spec-run-5/gap-analysis.md`, no code changes | The prompt is interrogative; implementing would be scope creep and a wrong guess is not destructive if the report comes first |
| Finding categories | Missing, partials listed separately | Absent/partial/contradictory | Absent/partial/contradictory/deferred + extras | Four categories plus extras | Partial features and permission contradictions are the majority of real gaps here; a binary view would mislead |
| Coverage depth | Minimal enumeration | Full rule groups + state table | Full rule groups + state table + verification | Full BRS coverage including RC/RP rules and the state table | Business behavior, not endpoint count, is what the BRS requires |
| Verification | Not specified | Evidence per gap | Whole-tree search + registered routes | Whole-tree verification with stated method | Three contexts are empty; the main false-negative risk is looking only in the obvious directory |
| Architecture claims | Not addressed | Separate section | Separate section | Separate conformance section | `AGENTS.md` claims an async payment saga and ledger that the code does not contain; this is useful but not a BRS feature |
| Extras | Not addressed | List once | List once | List once, excluded from gaps | Wishlist, reviews, newsletter and quote requests are implemented but not BRS requirements |
| Ranking | Not requested | Sequence by risk | Sequence by dependency/risk, no effort | Suggested sequence, no estimates | Planning value without inventing effort data |
| Unverified areas | Not addressed | Not addressed | Required | Required closing section | Runtime behavior was not exercised for every claim; the limits must be visible |

## Changelog (synthesizer and orchestrator only)
- v1: created by merging analyst-a (literal), analyst-b (intent) and analyst-c (skeptic). No blocking questions retained: the two items analyst-c marked blocking are resolved by repository evidence and safe defaults (BRS baseline, report-only deliverable), both recorded in Decisions.

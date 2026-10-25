# Spec: Backend missing-feature assessment with explicit baseline and evidence
Version: 1

## Goal
Answer the question without guessing: state what "missing" is measured against, verify each claimed absence against the repository, and separate genuinely absent features from partial implementations, contradictions and deliberate deferrals.

## Corrected prompt
What features are missing in the backend?

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The answer states the baseline it measures "missing" against; if the baseline is not named by the requester, the report says which repository document was used and why. | constraint | "missing" has no meaning without a baseline | must |
| R2 | Each finding is classified as absent, partial, contradictory, or intentionally deferred; the four are never merged into one "missing" bucket. | functional | "missing features" is ambiguous | must |
| R3 | Each finding is verified against the repository before publication; a feature is only called missing after searching the whole `src/`, `config/` and `migrations/` trees, not one expected directory. | quality | avoid false claims caused by looking in the wrong place | must |
| R4 | Each finding cites at least one piece of verifiable evidence (file and symbol, registered route, table, or migration) and the baseline item it fails. | quality | the answer must be auditable | must |
| R5 | All requirement groups in the chosen baseline are assessed, including behavioral requirements such as race conditions and state-transition rules, not only CRUD endpoints. | functional | "features" includes business rules | must |
| R6 | Repository claims that the code contradicts (for example architecture statements in `AGENTS.md` or ADRs) are reported separately from missing baseline features. | quality | the repository contains more expectations than the baseline | should |
| R7 | Capabilities outside the baseline are listed once as extras and are not presented as gaps. | quality | avoid scope confusion | should |
| R8 | Unverified areas and assumptions are listed at the end of the report so readers know what was not checked (for example runtime behavior not exercised). | quality | honesty about evidence limits | should |
| R9 | No production code, route, migration, configuration or test is changed. | constraint | the prompt is a question | must |
| R10 | The report is written in English and saved under `spec-run-5/`. | constraint | repository convention | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The business requirements document in `docs/` is the intended baseline. | high |
| 2 | Empty bounded-context directories are incomplete work rather than an intentional architectural placeholder. | med |
| 3 | A report is the expected deliverable and no implementation is requested in this run. | med |
| 4 | The requester cares about business-rule gaps, not only absent HTTP endpoints. | med |
| 5 | Partial implementations are relevant because they still block the business flow. | low |

## Open questions
- [BLOCKING] What is the baseline for "missing"? Default if unanswered: `docs/BUSINESS-REQUIREMENTS-SPECIFICATION.md` v1.0, plus `AGENTS.md`/ADRs for architecture claims.
- [BLOCKING] Is the expected deliverable an analysis report or an implementation? Default if unanswered: report only; implementation would be separate feature tasks.
- [minor] Do contradictions and permission mismatches count as "missing features"? Default if unanswered: yes, reported in their own category.
- [minor] Should features implied by the storefront but absent from the baseline be included? Default if unanswered: no, list them as open questions or extras.
- [minor] Should the report rank work? Default if unanswered: yes, by dependency and business risk, without effort estimates.
- [minor] Are empty contexts deliberately deferred? Default if unanswered: treated as missing, with the ambiguity flagged.

## Out of scope
- Implementing or prototyping any missing feature.
- Changing tests, migrations, routes or configuration.
- Storefront repository analysis.
- Performance profiling, penetration testing or mutation testing.
- Effort estimates, budgets and staffing.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Fix the baseline and the verification method | R1, R2, R8 | BRS, AGENTS.md, ADRs | Baseline list and method note | `spec-run-5/sections/baseline-method.md` | none | Baseline is named, categories are defined, and search method is stated |
| WP2 | Assess catalog and promotions with whole-tree search | R3–R5 (RG1.x, RG2.x, RC4–RC6) | Catalog/Promotions source, routes, migrations | Findings section | `spec-run-5/sections/catalog-promotions.md` | WP1 | Every finding has category, evidence and baseline reference |
| WP3 | Assess ordering, payment, inventory and shipping with whole-tree search | R3–R5 (order state table, RC1–RC3, RC7–RC10, RP1.x) | Ordering/Payment/Inventory/Shipping source, routes, migrations | Findings section | `spec-run-5/sections/order-lifecycle.md` | WP1 | Every finding has category, evidence and baseline reference |
| WP4 | Assess identity, access control, audit and platform configuration | R3–R5 (RG3.x, RG4.1, permission matrix, §五.4) | Identity source, security config, routes | Findings section | `spec-run-5/sections/identity-platform.md` | WP1 | Every finding has category, evidence and baseline reference |
| WP5 | Adversarial cross-check and consolidated report | R2, R4, R6–R10 | WP1–WP4, AGENTS.md, ADRs | Consolidated report with unverified areas | `spec-run-5/gap-analysis.md` | WP2, WP3, WP4 | No finding is duplicated or unverified; extras, contradictions and deferrals are separated; unverified areas are stated |

# Spec: Backend gap analysis against the approved business requirements
Version: 1

## Goal
Give the reader an evidence-backed list of the backend capabilities the platform still lacks, so the next development cycle can be planned from facts instead of assumptions. The answer must make clear which approved business requirements are not served by the current code.

## Corrected prompt
What features are missing from the backend?

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The analysis uses the approved business requirements document as the definition of "required", and states that baseline explicitly. | constraint | "missing features" implies a requirement baseline | must |
| R2 | The analysis covers all eight bounded contexts of the backend and names the ones that contain no implementation. | functional | "in backend" | must |
| R3 | Every gap is classified as absent, partial, or contradictory to the requirement. | functional | "missing" covers more than "not written yet" | must |
| R4 | Every gap cites concrete evidence: source path with symbol, registered route, or database table/migration. | quality | answer must be trustworthy | must |
| R5 | Every gap states its business impact and a priority of must, should or could. | quality | the result should support planning | must |
| R6 | The permission matrix, catalog rules RG1.x, promotion rules RG2.x, user rules RG3.x, checkout rule RG4.1, race-condition table RC1–RC13, concurrency rules RP1.x and the order/payment state table are each assessed as implemented, partial or missing. | functional | the baseline defines these as required behavior | must |
| R7 | Capabilities implemented beyond the baseline are listed once, separately, so they are not mistaken for gaps. | quality | avoid mixing extras with missing scope | should |
| R8 | Claims made in `AGENTS.md` and the ADRs that the code does not satisfy are reported in a separate architecture-conformance section. | quality | repository architecture claims are part of the expected system | should |
| R9 | The report ends with a suggested implementation sequence ordered by business risk and dependency. | quality | the reader needs to know what to build first | should |
| R10 | No production code, route, migration, configuration or test is changed; this is an analysis deliverable. | constraint | the prompt asks a question | must |
| R11 | The report is written in English and saved to `spec-run-5/gap-analysis.md`. | constraint | repository convention for spec runs | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | `docs/BUSINESS-REQUIREMENTS-SPECIFICATION.md` v1.0 is the authoritative statement of what the platform must do. | low |
| 2 | Empty `src/Payment`, `src/Inventory` and `src/Shipping` contexts are unfinished scope, not deliberate deferrals. | med |
| 3 | The user wants analysis to plan the next features, not an implementation in this run. | low |
| 4 | The current working tree (routes, migrations, tests) represents the real backend behavior closely enough for a gap analysis. | low |
| 5 | Partial behavior counts as missing for planning purposes when a business rule cannot be satisfied end to end. | med |

## Open questions
- [minor] Should the report include effort estimates? Default if unanswered: no, only risk and dependency ordering.
- [minor] Should frontend expectations that are not in the business requirements be treated as gaps? Default if unanswered: no, list them under extras or open questions.
- [minor] Should the report name specific filenames for every gap? Default if unanswered: yes, at least one evidence path per gap.
- [minor] Is implementation expected after the report? Default if unanswered: no, implementation becomes separate feature tasks.

## Out of scope
- Writing or changing production code, tests, migrations or configuration.
- Reviewing the storefront repository.
- Security, performance or mutation testing of the existing code.
- Redesigning bounded contexts or proposing new architecture beyond naming what the approved requirements need.
- Delivery estimates and staffing.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Assess catalog and promotions requirements | R3–R5 (RG1.x, RG2.x, RC4, RC5, RC6) | Catalog and Promotions source, routes, migrations, BRS | Gap section | `spec-run-5/sections/catalog-promotions.md` | none | Every RG1/RG2 rule and related race condition has a status and evidence |
| WP2 | Assess order, payment, inventory and shipping requirements | R3–R5 (order state table, RC1–RC3, RC7–RC10, RP1.x) | Ordering/Payment/Inventory/Shipping source, routes, migrations, BRS | Gap section | `spec-run-5/sections/order-lifecycle.md` | none | Every state transition, race condition and concurrency rule has a status and evidence |
| WP3 | Assess identity, access control and platform configuration requirements | R3–R5 (RG3.x, RG4.1, permission matrix, §五.4) | Identity source, security config, routes, BRS | Gap section | `spec-run-5/sections/identity-platform.md` | none | Every user/access rule and platform-configuration capability has a status and evidence |
| WP4 | Consolidate, rank and publish the report | R1, R2, R6–R11 | WP1–WP3 sections, AGENTS.md, ADRs | Consolidated gap analysis | `spec-run-5/gap-analysis.md` | WP1, WP2, WP3 | Every requirement group is covered once, every gap has evidence and priority, and the sequence is stated |

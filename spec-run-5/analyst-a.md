# Spec: Backend missing-feature inventory
Version: 1

## Goal
A written answer that names the features missing from the backend, so the reader knows what the backend does not yet provide.

## Corrected prompt
What features are missing in the backend?

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The result identifies features that are missing from the backend. | functional | "what are the missing features" | must |
| R2 | The result is scoped to the backend of this project and does not answer for the storefront. | constraint | "in backend" | must |
| R3 | The missing features are enumerated individually rather than summarized in one sentence. | functional | "what are the features" (plural list question) | must |
| R4 | The result is produced without changing backend code, because the prompt asks a question rather than requesting an implementation. | constraint | interrogative form of the prompt | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Missing" is relative to a statement of required features that already exists inside the repository. | high |
| 2 | The backend is the `E-commerce/` repository; `Zidane_front_web/` is not backend. | low |
| 3 | A written report file is an acceptable form of answer. | low |
| 4 | The current revision of the repository is the subject; no historical release is compared. | low |

## Open questions
- [BLOCKING] What is the baseline for "missing" (business requirements, frontend expectations, roadmap)? Default if unanswered: `docs/BUSINESS-REQUIREMENTS-SPECIFICATION.md`, the only approved requirement source in the repository.
- [minor] Should the answer be chat-only or written to a file? Default if unanswered: a file under `spec-run-5/`.
- [minor] Do partially implemented features count as missing? Default if unanswered: list them separately from fully missing features.
- [minor] Is an implementation of the missing features requested? Default if unanswered: no, analysis only.

## Out of scope
- Implementing any missing feature, route, migration or test.
- Storefront features and storefront repository changes.
- Code-quality review except where a defect causes a feature to be absent.
- Estimating delivery effort in days or sprints.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Inventory what the backend actually provides | R1, R2 | `src/`, `config/routes/`, migrations, route table | Capability inventory with evidence paths | `spec-run-5/sections/capabilities.md` | none | Every bounded context is listed with its implemented commands, queries, HTTP routes and tables |
| WP2 | Establish the required-feature baseline | R3 | `docs/BUSINESS-REQUIREMENTS-SPECIFICATION.md`, `AGENTS.md` | Ordered list of required capabilities | `spec-run-5/sections/baseline.md` | none | Every requirement group in the baseline has an ID and a source reference |
| WP3 | Produce the enumerated answer | R1–R4 | WP1, WP2 | Gap report listing each missing feature | `spec-run-5/gap-analysis.md` | WP1, WP2 | Each missing feature is named separately and traced to a baseline item and to code evidence |

# Spec: E-commerce system design and class diagrams
Version: 1

## Goal
Produce authoritative system design documentation for **ecommerce-core**: bounded contexts, domain class diagrams with UML-style relations (association, composition, aggregation, inheritance, dependency), and cross-context integration—aligned with ADR-0001, BRD, and existing `src/**` where implemented.

## Corrected prompt
In the E-commerce project, create the system design documentation, including class diagrams and explicit relations between classes (within and across bounded contexts).

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | Document the 8 bounded contexts + Shared kernel and how they interact (context map). | functional | "system design" | must |
| R2 | Provide per-context **domain** class diagrams (aggregates, entities, value objects, enums, key ports). | functional | "class diagram" | must |
| R3 | Label relations between classes: type (assoc/composition/aggregation/inheritance/implements/depends-on), role names, and cardinalities where meaningful. | functional | "relations between classes" | must |
| R4 | Show CQRS layering (Domain ← Application ← Infrastructure) and write vs read model boundaries. | constraint | implied by project | must |
| R5 | Document cross-context links via domain events and checkout saga (ADR-0001, ADR-0004)—not direct aggregate references. | constraint | project architecture | must |
| R6 | Distinguish **implemented today** vs **target model** (from BRD/migrations) in the design doc. | quality | faithful to codebase | should |
| R7 | Store deliverables under `docs/system-design/` and link from spec traceability. | constraint | harness layout | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | Design-only deliverable; no new production code in this task (`spec-only` intent). | low |
| 2 | Class diagrams focus on **domain model**; HTTP controllers and DBAL handlers appear only in a separate application/infrastructure view. | low |
| 3 | English documentation; business state labels may mirror BRD/code (e.g. Chinese order statuses). | low |

## Open questions
- none (defaults: target model follows BRD + ADRs; diagrams in Mermaid UML classDiagram syntax)

## Out of scope
- Implementing missing aggregates or migrations
- Full sequence diagrams for every API endpoint
- Front-end component design
- Payment provider integration details (PCI tokens only per SEC-001)

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Context map + architecture overview | R1, R4, R5 | ADR-0001, AGENTS.md | `docs/system-design/README.md` | docs/system-design/ | none | Reader sees contexts, buses, saga entry |
| WP2 | Domain class diagrams per context | R2, R3, R6 | BRD, migrations, src/Domain | `docs/system-design/DOMAIN-CLASS-DIAGRAMS.md` | same | WP1 | Each context has diagram + relation table |
| WP3 | Cross-context event + relation matrix | R3, R5 | ADR-0001 | section in WP2 or `INTEGRATION.md` | same | WP2 | Event table maps to class IDs |
| WP4 | Spec traceability | R7 | this spec | `.agents/state/F-system-design-class-diagrams.md` | .agents/state/ | WP1–WP3 | State file GATE SPEC ok |

## Decisions (synthesizer only)
| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Diagram notation | PlantUML only | Mermaid classDiagram | Mixed | Mermaid | Renders in GitHub/Cursor without extra tooling |
| Scope of classes | Code-only | BRD target only | Both layers | Both layers | R6; honest about implementation gap |
| Language | English | Chinese | Bilingual headers | English + BRD terms | User prompt in English |

## Changelog (synthesizer and orchestrator only)
- v1: created

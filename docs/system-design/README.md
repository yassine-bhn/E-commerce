# System design — `ecommerce-core`

Authoritative domain-oriented design for the modular monolith. Requirements trace: [spec-run/spec.md](../../spec-run/spec.md) (R1–R7).

| Document | Contents |
|----------|----------|
| [ARCHITECTURE-OVERVIEW.md](./ARCHITECTURE-OVERVIEW.md) | Context map, CQRS layers, buses, checkout saga |
| [DOMAIN-CLASS-DIAGRAMS.md](./DOMAIN-CLASS-DIAGRAMS.md) | Per-context class diagrams and relation tables |
| [CROSS-CONTEXT-INTEGRATION.md](./CROSS-CONTEXT-INTEGRATION.md) | Domain events, anti-corruption, ID-only references |

**Related:** [ADR-0001](../adr/ADR-0001-bounded-contexts.md), [ADR-0004](../adr/ADR-0004-checkout-saga.md), [BUSINESS-REQUIREMENTS-SPECIFICATION.md](../BUSINESS-REQUIREMENTS-SPECIFICATION.md), [AGENTS.md](../../AGENTS.md).

**Legend — implementation status**

- **Implemented** — class or table exists in `src/**` or migrations today.
- **Target** — specified in BRD/ADR; scaffold or read-model only so far.

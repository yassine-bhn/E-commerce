# ADR-0019 — Domain dependency rule (LAY-001 correction)

Date: 2026-11-07 · Status: accepted · Task: G-solid-remediation (Phase 3; plan
`docs/superpowers/plans/2026-10-29-solid-remediation.md`)

## Context

The original LAY-001 read "Domain must not import Symfony, Doctrine or infrastructure namespaces"
without exceptions. Three legitimate dependencies made the rule unenforceable as written:

1. **Doctrine ORM mapping attributes.** Domain aggregates (`Order`, `Product`, `Cart`) are mapped
   with `#[ORM\Entity]`/`#[ORM\Table]`/`#[ORM\Column]` attributes — `use Doctrine\ORM\Mapping as ORM`
   is the mapping itself, not a framework coupling.
2. **Doctrine collections.** `Order`'s OneToMany lines are typed
   `Doctrine\Common\Collections\Collection`/`ArrayCollection` per the ORM contract.
3. **Symfony Uid.** Identity value objects (`CartId`, `CartLineId`, `OrderId`) wrap
   `Symfony\Component\Uid\Uuid` — the RFC 4122 identity primitive, not the HTTP framework.

Enforcing the unexceptional rule produced false findings and invited a rule-vs-code drift
(see `ADR-0018` §5 and `Guard-domain-imports.sh`).

## Decision

`src/*/Domain/**` MUST NOT import from Application, Infrastructure, Symfony HttpKernel/
HttpFoundation, PSR, Meilisearch, or Doctrine — **except**:

- `Doctrine\ORM\Mapping` (mapping attributes, including `use Doctrine\ORM\Mapping as ORM;`);
- `Doctrine\Common\Collections\` (ORM to-many associations);
- `Symfony\Component\Uid\` (identity value objects).

Everything else stays banned, including `Doctrine\DBAL`, `Doctrine\Persistence`,
`Symfony\Component\HttpFoundation`, and every `App\*\Infrastructure\*` / `App\*\Application\*`
import.

## Enforcement

- `tests/Architecture/SolidBoundariesTest::it_keeps_application_free_of_forbidden_types` (RED at
  Phase 1, green since Phase 3);
- `.agents/hooks/guard-domain-imports.sh` (LAY-001), whose allowlist is an anchored exclude over
  the `grep -n` output (`^([^:]*:)?[0-9]+:use …` — GNU grep omits the filename for a single file
  argument);
- `tests/Architecture/SolidBoundariesTest::it_keeps_ports_in_inner_layers` for the port-placement
  half.

## Consequences

Domain stays unit-testable without the framework; the ORM mapping remains declarative metadata on
the aggregate rather than a persistence coupling. Application (`LAY-002`) gains no equivalent
exception — since Phase 3 no Application class imports Doctrine at all.

# ADR-0018 — SOLID gate enforcement model and corrections

Date: 2026-11-01 · Status: accepted · Task: G-solid-remediation Phase 1-2
(docs/superpowers/plans/2026-10-29-solid-remediation.md)

## Context

The remediation plan introduces mechanical SOLID gates (architecture suite, PHPStan rules,
`bin/solid-check`, `bin/tdd-check`, CI jobs). Several rulebook checks had drifted from their
rules; this ADR records the enforcement decisions so the gates stay strict without false
positives.

## Decisions

1. **TDD-005 is test-scoped.** `.agents/rules/tdd.md` scopes determinism to `tests/**`; the
   `guard-cqrs.sh` `src/**` branch (`new DateTime`, `time()` — 35 findings, all false positives
   in Infrastructure adapters that own their clock) is removed. Production clock injection is a
   separate concern and is not part of this remediation.
2. **OCP-002 is owned by the architecture test.** `guard-cqrs.sh` drops its `match (true)` grep;
   `it_forbids_match_true_in_application_and_domain` (plus the PHPStan rule) is the authority.
   Phase 2.6 removed the two real findings.
3. **ECO-004 flags the read-then-write setters only.** `->setStockQuantity(` and `->setReserved(`
   are the read-then-write patterns; `InventoryStockPort::setAvailable()` is the atomic
   conditional update and is never flagged.
4. **CMD-003 requires exactly one binding.** Every
   `src/*/Application/CommandHandler/*Handler.php` must declare exactly one
   `#[AsMessageHandler(bus: 'command.bus')]` and `__invoke(...): void`. Query handlers
   (`Infrastructure/ReadModel`) are exempt: they may be named `handleX` and return DTOs. The
   architecture test owns the one-handler-per-class rule; the hook is the fast local check.
5. **LAY-001 allowances.** Domain may import `Doctrine\ORM\Mapping` (mapping attributes,
   including `use Doctrine\ORM\Mapping as ORM;`), `Doctrine\Common\Collections\` (ORM to-many
   associations) and `Symfony\Component\Uid\`; every other Symfony/Doctrine/PSR/Meilisearch and
   every `App\*\Infrastructure\*` / `App\*\Application\*` import is banned.
6. **TDD attribution for entry points** (Phase 1 gate, recorded here because it changes the
   rulebook's §9.4 reading): HTTP controllers are attributed to a changed `tests/Functional/**`
   test and ReadModel handlers to a same-context or functional test, because functional tests
   exercise routes through the kernel and never import controller classes. Domain, Application
   and Persistence keep the per-class rule; new concrete classes always need a referencing test.
7. **pre-finish runs the SOLID gates.** `.agents/hooks/pre-finish.sh` requires
   `bin/solid-check --fast` (PHPStan, Deptrac, guard hooks — the Architecture suite needs the
   migrated test DB and stays in `make solid`/CI). No git-dependent `bin/tdd-check` call lives
   in the container hook (the app image has no git); the `commit-msg` hook and the CI `tdd` job
   are the TDD gate.
8. **PHPStan rule tests.** Fixture classes live in `.agents/phpstan/fixtures/` (composer
   autoload-dev classmap, outside the analysed paths) and the rule identifiers are asserted via
   `RuleTestCase::gatherAnalyserErrors()`, because the phar's `analyse()` comparison renders
   only message/line/tip.

## Consequences

`guard-domain-imports.sh` over `src/**` reports only the Application DBAL/HTTP imports that
Phase 3 removes; `guard-cqrs.sh` over `src/**` reports only ERR-003 catch-all findings that
Phase 4 removes. The SOLID gates keep no allowlist of their own.

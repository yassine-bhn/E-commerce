# Harness PHPStan rules

SOLID enforcement rules for the rulebook in `.agents/rules/solid.md`:
SRP-001, SRP-002, ISP-001, ISP-003, DIP-003 plus the reference
implementations for CMD-003, QRY-001, EVT-002, ECO-001.

Registered in the project root `phpstan.neon` via `services:` tags.
Autoloaded from this directory through Composer `autoload-dev`
(`Agents\PhpStan\`). Rule unit tests use `PHPStan\Testing\RuleTestCase`
against fixtures in `fixtures/` (autoloaded through the Composer classmap,
never analysed by the main run).

Rules and identifiers:

| Rule | Identifier | Rule ID |
|---|---|---|
| NoDoctrineInApplicationRule | agents.dip003.application | DIP-003 |
| ControllerThinRule | agents.srp002.controller | SRP-002 |
| OneHandlerPerClassRule | agents.srp001.oneHandler | SRP-001 |
| InterfaceMethodLimitRule | agents.isp001.methodLimit | ISP-001 |
| ConstructorDependencyLimitRule | agents.isp003.dependencyLimit | ISP-003 |
| NoInfrastructureInHandlerRule | agents.dip003.noInfrastructure | DIP-003 |

When application code lives under `src/`, run:

```bash
docker compose exec app vendor/bin/phpstan analyse
```

(host: `vendor/bin/phpstan analyse` after `composer install`)

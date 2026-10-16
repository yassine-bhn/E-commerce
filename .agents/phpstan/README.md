# Harness PHPStan rules

Reference implementations for CMD-003, QRY-001, EVT-002, ECO-001, and DIP-003.

Registered in the project root `phpstan.neon` via `services:` tags. Autoloaded from this directory through Composer `autoload-dev` (`Agents\PhpStan\`).

When application code lives under `src/`, run:

```bash
docker compose exec app vendor/bin/phpstan analyse
```

(host: `vendor/bin/phpstan analyse` after `composer install`)

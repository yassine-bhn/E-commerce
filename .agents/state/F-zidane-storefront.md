# Task F-zidane-storefront
Type: feature
Started: 2026-10-17T00:20:00+01:00
Requirement: Backend API parity with Zidane_front_web storefront (catalog, cart quote, checkout, devis, reference data).

## Gates
GATE: PLAN ok — 2026-10-17T00:20:00+01:00 — orchestrator

## RED record
RED: tests/Unit/Shared/Domain/DeliveryPolicyTest.php — (domain policy) — cmd: vendor/bin/phpunit --testsuite=Unit → PASS
RED: tests/Functional/AuthenticationTest.php — "login returns customer identity with 200" — cmd: docker compose exec app vendor/bin/phpunit --filter AuthenticationTest → FAIL
ATTEMPTS: 2

## Rulings
Ruling: Login-success RED first, wrong-password/banned after GREEN — one minimal test validates wiring — costs slower failure-path feedback, caught by next cycle.
Ruling: Defer logout, access_control, role_hierarchy, login rate limiting until after first GREEN — uncertain Symfony 8 logout semantics — costs protected endpoints stay header-based one more cycle.
Ruling: Migrations are config/schema, not production logic; TDD gate applies to src only; still recorded RED — keeps gate honest.
Ruling: LoginController/LogoutController no-op routes required because Symfony routing runs before firewall — authenticator cannot intercept an unrouted path — costs two no-op controllers.

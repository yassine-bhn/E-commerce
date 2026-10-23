# Task F-remember-me
Type: feature
Started: 2026-10-23T14:11:56+01:00
Requirement: Implement a "Remember Me" feature: when the user checks "Remember me" on the login form, the user stays authenticated after closing and reopening the browser.

## Spec traceability
- Spec: spec-run-7/spec.md (v3 — v2: R15 reworded at REFACTOR after measuring `SignatureRememberMeHandler` rolling rotation; v3: R13 clear-on-OAuth, R2 strict boolean, WP1 ownership, after REVIEW)
- Prompt archive: spec-run-7/prompt.md
- Stage 4 summary: coverage R1–R15 all pass; Stage 3 touched config/packages/security.yaml, src/Identity/Infrastructure/Security/LoginAuthenticator.php, tests/Functional/RememberMeTest.php, .env, .env.prod.example, Zidane_front_web/src/routes/connexion.tsx, Zidane_front_web/src/lib/api/types.ts
- Requirements: R1, R2, R3, R4, R5, R6, R7, R8, R9, R10, R11, R12, R13, R14, R15

## Gates
GATE: SPEC ok — 2026-10-23T14:11:56+01:00 — orchestrator
GATE: PLAN ok — 2026-10-23T14:24:00+01:00 — test-planner
GATE: SPEC-TESTS fail — 2026-10-23T14:40:00+01:00 — e2e-contract-test-agent (1 intended RED: malformed cookie → 500)
GATE: SPEC-TESTS ok — 2026-10-23T14:40:00+01:00 — integration-test-agent (R10 prod cookie policy, 18 assertions)
GATE: GREEN ok — 2026-10-23T14:58:00+01:00 — implementer (MalformedRememberMeCookieSubscriber; all suites + phpstan + cs-fixer + deptrac clean)
GATE: REFACTOR ok — 2026-10-23T15:10:00+01:00 — refactorer (suite green after every step; spec defect R15 escalated to orchestrator)
GATE: SPEC v2 — 2026-10-23T15:16:00+01:00 — orchestrator (R15 rolling expiry)
GATE: FIXES ok — 2026-10-23T15:26:00+01:00 — e2e-contract-test-agent (R15 assertion reworked, 2 consecutive green filter runs) + implementer (dangling WF-001 citation removed)
GATE: REVIEW ok — 2026-10-23T15:52:00+01:00 — code-reviewer + architecture-reviewer + security-reviewer + performance-reviewer + test-reviewer (0 high; 6 med: OAuth stale cookie, compose.prod lifetime not forwarded, R8 expired clause untested, decoder duplicated in guard, guard untested at unit level, no ADR for the credential)
GATE: SPEC v3 — 2026-10-23T15:54:00+01:00 — orchestrator (R13 clear-on-OAuth, R2 strict boolean, WP1 ownership, 3 Decision rows)
GATE: RED — 2026-10-23T16:02:00+01:00 — unit-test-writer (array-valued cookie → BadRequestException) + e2e-contract-test-agent (OAuth stale cookie, non-boolean `remember_me` accepted)
GATE: GREEN — 2026-10-23T16:20:00+01:00 — implementer (guard delegates to `RememberMeDetails::fromRawCookie()`, cookie name parameterised, strict boolean, OAuth disabled badge, compose env) + ADR-0011
GATE: REVIEW re-run — 2026-10-23T16:34:00+01:00 — code-reviewer (1 medium: bare `RememberMeBadge()` let `?_remember_me=1` enable the OAuth badge and issue a 14-day cookie)
GATE: RED — 2026-10-23T16:41:00+01:00 — e2e-contract-test-agent (`testCallbackCannotBeForcedToIssueARememberMeCookieByARequestParameter`: "Failed asserting that actual size 1 matches expected size 0.")
GATE: GREEN — 2026-10-23T16:47:00+01:00 — implementer (`RememberMeBadge(['_remember_me' => false])`, guard docblock corrected) + unit-test-writer (mock→stub, 2 extra data cases)
GATE: DONE — 2026-10-23T17:05:00+01:00 — orchestrator

## Definition of Done (AGENTS.md)
| Check | Status | Evidence |
|-------|--------|----------|
| Unit suite | pass | 209 tests, 551 assertions |
| Integration suite | pass | 59 tests, 413 assertions |
| Functional suite | pass | 229 tests, 1567 assertions |
| Contract suite | pass | 16 tests, 495 assertions |
| Projection suite | pass | 14 tests, 53 assertions |
| Architecture suite | pass | 3 tests, 29 assertions |
| phpstan | pass | `[OK] No errors` |
| php-cs-fixer | pass | 0 of 417 files fixable |
| deptrac | pass | 0 violations, 0 errors |
| harness-check | pass | 17 agents, 14 domain skills + 14 superpowers, 14 rule files, 8 hooks, 5 PHPStan rules |
| infection MSI | n/a | touched paths are `config/`, `Infrastructure/Security/`, `Infrastructure/Http/` — DoD requires MSI on touched Domain+Application only |
| ADR | pass | `docs/adr/ADR-0011-remember-me-credential.md` (credential); guard needs none |
| Reviews | pass | 5 reviewers at REVIEW (0 high) + focused re-review of the FIXES delta |
| State + audit complete | pass | this file + `.agents/audit/F-remember-me.log` |
| Requirements traced to tests | pass | `spec-run-7/coverage.md`, R1–R15 all pass |

## RED record
RED: tests/Functional/RememberMeTest.php — "A flagged login must set the remember-me cookie. Failed asserting that null is an instance of class Symfony\Component\HttpFoundation\Cookie." — cmd: docker compose exec app vendor/bin/phpunit --testsuite Functional --filter RememberMeTest → FAIL (6 failures, 4 passes)
RED: tests/Functional/RememberMeTest.php:357 `testMalformedExpirySegmentIsRejectedWithJson401` — "Failed asserting that the Response status code is 401. HTTP/1.1 500 Internal Server Error … RememberMeDetails::__construct(): Argument #2 ($expires) must be of type int, string given" — cmd: docker compose exec app vendor/bin/phpunit --testsuite Functional --filter testMalformedExpirySegmentIsRejectedWithJson401 → FAIL (1 failure)
RED: tests/Unit/Identity/Infrastructure/Security/MalformedRememberMeCookieSubscriberTest.php — case 6 array-valued cookie: "Symfony\Component\HttpFoundation\Exception\BadRequestException: Input value "REMEMBERME" contains a non-scalar value." at MalformedRememberMeCookieSubscriber.php:50 (via InputBag.php:46) — cmd: docker compose exec app vendor/bin/phpunit tests/Unit/Identity/Infrastructure/Security/MalformedRememberMeCookieSubscriberTest.php → 8 tests, 7 pass, 1 error
RED: tests/Functional/GoogleOAuthTest.php::testSuccessfulCallbackClearsARememberMeCookieFromAPasswordLogin — "A Google login must clear the remember-me cookie an earlier password login left in the browser. Failed asserting that actual size 0 matches expected size 1." — reason: GoogleOAuthController::callback() calls Security::login() without a RememberMeBadge, so RememberMeListener returns before clearRememberMeCookie()
RED: tests/Functional/RememberMeTest.php::testNonBooleanRememberMeIsRejectedWith400@"yes"|@1|@[] — "A remember_me value that is neither a boolean nor absent must be rejected, not coerced. Failed asserting that the Response status code is 400. HTTP/1.1 200 OK" — reason: filter_var(..., FILTER_VALIDATE_BOOL) coerces
RED: tests/Functional/GoogleOAuthTest.php::testCallbackCannotBeForcedToIssueARememberMeCookieByARequestParameter — "A request parameter must not make the Google callback issue a remember-me cookie. Failed asserting that actual size 1 matches expected size 0." — reason: a bare `RememberMeBadge()` let CheckRememberMeConditionsListener read `?_remember_me=1` and enable the badge
ATTEMPTS: 3

## PATTERN decision (orchestrator)
No PATTERN gate for the malformed-cookie guard: it is a request-level input guard inside `src/Identity/Infrastructure/Security/`, not a new pattern, bus, aggregate or bounded-context boundary — no ADR required (orchestration §Human approval gates, DoD ADR line).
The credential mechanism itself **is** ADR-worthy (architecture-reviewer, REVIEW): a 14-day bearer credential with no server-side revocation, sliding renewal, password-hash coupling, firewall-wide scope on `main` (including `/api/v1/admin`) and a prod `SameSite=None` dependency. Routed to pattern-selector for `docs/adr/ADR-0011-remember-me-credential.md`; it changes no bus/aggregate/boundary, so no human approval gate.

## REVIEW outcome (5 reviewers, 0 high)
Accepted and fixed in the FIXES cycle: OAuth stale cookie (R13), `REMEMBER_ME_LIFETIME` missing from `compose.prod.yaml` (R11), R8 expired-cookie test missing, guard duplicating the vendor decoder, guard untested at unit level, cookie-name coupling.
Deferred to follow-up tasks (pre-existing or requiring product/human input, not blockers for this feature): `customer_profile` `lower(email)` predicate has no matching expression index (needs a migration → MIG-001 human approval); `APP_SECRET` is now the sole signing key of the credential (dedicated `REMEMBER_ME_SECRET` + deploy entropy assertion); product questions still open: should admins receive a 14-day credential at all, and are prod `shop.`/`api.` hosts on one registrable domain (decides `SameSite=Lax` vs `None`+CSRF guard).
Process debt: the first RED recorded 6 behaviors in one batch instead of one (TDD-003) — accepted for this run, split in future cycles.

## Stage 3 inventory (spec-run-7/coverage.md)
- Backend: `remember_me` block on the `main` firewall (signature handler, `%env(int:REMEMBER_ME_LIFETIME)%`, `name: '%remember_me_cookie_name%'`, lax/secure-in-prod), `when@prod` override, `RememberMeBadge` with `_remember_me` from the JSON body, strict boolean validation, disabled badge on the OAuth callback, `MalformedRememberMeCookieSubscriber` delegating to the vendor decoder.
- Tests: `tests/Functional/RememberMeTest.php` (20 tests), `tests/Functional/GoogleOAuthTest.php` (10 tests incl. stale-cookie clear and the `_remember_me=1` override attempt), `tests/Unit/Identity/Infrastructure/Security/MalformedRememberMeCookieSubscriberTest.php` (10 data cases), `tests/Integration/Identity/RememberMeCookiePolicyTest.php` (prod cookie policy).
- Config: `REMEMBER_ME_LIFETIME=1209600` in `.env`, `.env.prod.example` and forwarded by `compose.prod.yaml`; `remember_me_cookie_name` in `config/services.yaml`.
- ADR: `docs/adr/ADR-0011-remember-me-credential.md`.
- Storefront: "Se souvenir de moi" checkbox in `connexion.tsx`, `remember_me?: boolean` on `LoginInput`, flag forwarded only on the login tab.

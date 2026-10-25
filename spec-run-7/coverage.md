# Coverage — Remember Me (spec v3)

Checked against `spec-run-7/prompt.md` and `spec-run-7/spec.md` (Version: 3). "Evidence" is a test
name in `E-commerce/tests/Functional/RememberMeTest.php` unless stated otherwise.

| Req | WP | Status | Evidence |
|-----|----|--------|----------|
| R1 — checkbox "Se souvenir de moi", unchecked, accessible label | WP2 | pass | `Zidane_front_web/src/routes/connexion.tsx`: `<label htmlFor="remember_me">` wrapping a native checkbox bound to `remember` state (default `false`); `npx tsc --noEmit` and `npx eslint` clean. Manual: no JS test runner in `Zidane_front_web` (see Gaps). |
| R2 — flag sent in the login request; non-boolean rejected with 400 | WP1, WP2 | pass | `connexion.tsx` sends `remember_me: remember` (a real boolean); `LoginInput` typed; `LoginAuthenticator::isRememberMeRequested()` requires `is_bool` and throws `BadRequestHttpException` otherwise. Evidence: `testNonBooleanRememberMeIsRejectedWith400@"yes"`, `…@1`, `…@[]` (400 and no cookie), plus the flagged/unflagged cookie delta in R3/R4. The contract test only pins the identity shape (it would pass with the flag ignored), so it is not cited as R2 evidence. |
| R3 — flagged login sets a persistent cookie | WP1 | pass | `testFlaggedLoginIssuesAPersistentHttpOnlyCookie` (expiry > now + 1 day, `HttpOnly`, `Path=/`), `testIssuedCookieLifetimeMatchesTheConfiguredRememberMeLifetime` |
| R4 — unflagged login issues none and clears an old one, body unchanged | WP1 | pass | `testUnflaggedLoginIssuesNoRememberMeCookie` (data cases: field absent and `false`), `testUnflaggedLoginClearsAPreviouslyIssuedRememberMeCookie`; `AuthenticationTest::testLoginWithValidCredentialsReturnsCustomerIdentity` still green |
| R5 — cookie-only request authenticates and starts a session | WP1 | pass | `testRememberMeCookieAloneAuthenticatesAfterRestart` (fresh kernel, only `REMEMBERME` replayed, `/auth/me` 200 + identity), `testCookieOnlyLoginStartsASession` (session cookie now present) |
| R6 — no cookies means 401 | WP1 | pass | `testWithoutAnyCookieTheVisitorStaysAnonymous` |
| R7 — failed flagged login sets no cookie | WP1 | pass | `testFailedFlaggedLoginIssuesNoRememberMeCookie` |
| R8 — tampered/truncated/expired cookie → 401 JSON, never 5xx/redirect | WP1 | pass | Functional: `testTamperedCookieIsRejectedWithJson401`, `testTruncatedCookieIsRejectedWithJson401`, `testExpiredCookieIsRejectedWithJson401`, `testMalformedExpirySegmentIsRejectedWithJson401` (was a 500 `TypeError`), `testInvalidCookieOnARoleUserEndpointReturnsTheEntryPointJson401` (entry-point parity); the shared helper also asserts no `Location` header. Unit: `tests/Unit/Identity/Infrastructure/Security/MalformedRememberMeCookieSubscriberTest.php`, 10 data cases (valid kept; non-integer expiry, 3 segments, bad-base64 identifier, base64-of-malformed and empty string dropped; array-valued cookie kept without throwing; a cookie under another name untouched; no cookie and sub-request are no-ops) |
| R9 — logout clears the cookie | WP1 | pass | `testLogoutClearsTheRememberMeCookie` (`RememberMeListener` on `LogoutEvent`) |
| R10 — HttpOnly, Path=/, Secure prod, SameSite mirroring | WP1 | pass | `testFlaggedLoginIssuesAPersistentHttpOnlyCookie` (test env: `lax`, not secure); `tests/Integration/Identity/RememberMeCookiePolicyTest.php::testTheRememberMeCookieMirrorsTheSessionCookiePolicyPerEnvironment` boots a prod kernel (`FreshProductionKernel`) and proves `SameSite=None` + `Secure` + `HttpOnly` + `Path=/` on the POST logout clearing cookie, cross-checked against `session.storage.options` |
| R11 — configurable lifetime, default 14 days, documented | WP1 | pass | `security.yaml` uses `%env(int:REMEMBER_ME_LIFETIME)%`; `REMEMBER_ME_LIFETIME=1209600` in `.env` and `.env.prod.example`; forwarded to the production container by `compose.prod.yaml` (`${REMEMBER_ME_LIFETIME:-1209600}`), verified with `docker compose -f compose.prod.yaml config --no-interpolate -q`; `testIssuedCookieLifetimeMatchesTheConfiguredRememberMeLifetime` now reads the expected value from the booted environment instead of pinning the literal |
| R12 — cold-start restore, no client-side secret | WP3 | pass | `Zidane_front_web/src/lib/store.tsx` restore effect calls `getCurrentUser()` then `getCustomerProfile()` through `apiGet` (`credentials: "include"`) and sets `authReady`; persistence keeps `user: null` in `localStorage`. Manual: no JS test runner (see Gaps). |
| R13 — OAuth / register stay session-only and clear an older cookie | WP1, WP2 | pass | `tests/Functional/GoogleOAuthTest.php::testSuccessfulCallbackClearsARememberMeCookieFromAPasswordLogin` (valid password-login cookie seeded, OAuth callback for a different customer returns a clearing cookie and the following cold start is anonymous), `testSuccessfulCallbackIssuesNoRememberMeCookie`, and `testCallbackCannotBeForcedToIssueARememberMeCookieByARequestParameter` (a bare badge let `?_remember_me=1` on the callback issue a 14-day cookie; the badge now pins `_remember_me => false`); register auto-login calls `apiLogin({ email, password })` without the flag, so it goes through `LoginAuthenticator` with the badge present-but-disabled, the same clear path as R4 |
| R14 — no CORS change | WP1 | pass | `CorsSubscriber` untouched; `tests/Functional/CorsTest.php` asserts the explicit `STOREFRONT_CORS_ORIGIN` allow-list and `Access-Control-Allow-Credentials: true` (including on a remember-me auto-login request) |
| R15 — auto-login keeps the credential valid (rolling, never cancelled) | WP1 | pass | `testAutoLoginReissuesTheCookieWithoutCancellingIt` (consumed cookie's `getExpiresTime()` ≤ re-issued one, re-issued value still accepted — no wire-format parsing, no `time()`), `testSecondRestartStillAuthenticatesAndKeepsTheCookie` (asserts a non-empty re-issued cookie whose expiry is ≥ the first re-issue) |

## Verification runs (final)

- `--testsuite Unit` → OK (209 tests, 551 assertions).
- `--testsuite Integration` → OK (59 tests, 413 assertions).
- `--testsuite Functional` → OK (229 tests, 1567 assertions).
- `--testsuite Contract` → OK (16 tests, 495 assertions).
- `--testsuite Projection` → OK (14 tests, 53 assertions).
- `--testsuite Architecture` → OK (3 tests, 29 assertions).
- `phpstan analyse` → no errors. `php-cs-fixer fix --dry-run` → 0 of 417 fixable. `deptrac analyse` → 0 violations.
- `bash bin/harness-check` → ok (17 agents, 14 domain skills + 14 superpowers, 14 rule files, 8 hook scripts + hooks.yaml, 5 PHPStan rules).
- RED history: 6 failures before the firewall/authenticator change; 500 instead of 401 before `MalformedRememberMeCookieSubscriber`; `BadRequestException` on an array-valued cookie before the guard read the bag directly; stale cookie surviving a Google login before the disabled badge; 200 instead of 400 for a non-boolean `remember_me`; a 14-day cookie issued by the OAuth callback when `?_remember_me=1` was appended.

## Files touched

- `config/packages/security.yaml` (firewall `remember_me` + `name` + `when@prod` cookie policy)
- `config/services.yaml` (`remember_me_cookie_name` parameter + subscriber argument)
- `src/Identity/Infrastructure/Security/LoginAuthenticator.php` (`RememberMeBadge`, strict `isRememberMeRequested()`)
- `src/Identity/Infrastructure/Security/MalformedRememberMeCookieSubscriber.php` (new — R8 guard, delegates to the vendor decoder)
- `src/Identity/Infrastructure/Http/GoogleOAuthController.php` (disabled `RememberMeBadge` — R13)
- `compose.prod.yaml` (forwards `REMEMBER_ME_LIFETIME`)
- `tests/Functional/RememberMeTest.php`, `tests/Functional/GoogleOAuthTest.php`
- `tests/Unit/Identity/Infrastructure/Security/MalformedRememberMeCookieSubscriberTest.php`
- `tests/Integration/Identity/RememberMeCookiePolicyTest.php`, `.../FreshProductionKernel.php`
- `tests/Functional/CorsTest.php`, `tests/Contract/Storefront/HttpApiContractTest.php`
- `.env`, `.env.prod.example` (`REMEMBER_ME_LIFETIME`)
- `Zidane_front_web/src/routes/connexion.tsx`, `Zidane_front_web/src/lib/api/types.ts`
- `docs/adr/ADR-0011-remember-me-credential.md` (decision record for the credential)

## Gaps / notes

- No browser-level E2E was run: the "browser restart" is simulated at kernel level (session cookie dropped, remember cookie replayed in a fresh client with a fresh cookie jar). A manual check in a real browser is still advisable before release.
- R1, R12 and the client half of R2 have no automated test: `Zidane_front_web` ships no test runner and adding one is a new dependency outside this spec. They are verified by typecheck, lint and code inspection; a Playwright/Vitest smoke test is a follow-up needing approval.
- `infection` is only required by the Definition of Done on touched Domain+Application code; this change lives in `config/`, `Infrastructure/Security/` and `Infrastructure/Http/`, so mutation gating does not apply.
- Deferred after REVIEW, each recorded in `docs/adr/ADR-0011-remember-me-credential.md`: a `lower(email)` expression index on `customer_profile` (needs a migration → MIG-001), a dedicated `REMEMBER_ME_SECRET` with a deploy-time entropy assertion, the product decision on whether admins may hold a 14-day credential, and confirmation of whether prod `shop.`/`api.` share a registrable domain (which would make `SameSite=Lax` both sufficient and stricter than `None`).
- Deployment note: `var/cache/prod` may hold the pre-change compiled container — run `cache:clear --env=prod` (or rebuild the image) on release so the prod cookie policy is live.
- Pre-existing, untouched: `config/packages/security.yaml` still carries an unrelated `(WP3, R25)` comment from an earlier spec run.

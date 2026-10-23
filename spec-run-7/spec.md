# Spec: Remember Me on the storefront login form
Version: 3

## Goal
A customer who ticks "Se souvenir de moi" when signing in is still signed in after closing the browser and reopening it — the storefront resolves the identity on cold start and protected pages work without re-entering a password — while an unticked login keeps today's session-only behavior and the current cookie/CORS security posture is not weakened.

## Corrected prompt
Implement a "Remember Me" feature: when the user checks "Remember me" on the login form, the user stays authenticated after closing and reopening the browser.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The storefront login form (`Zidane_front_web/src/routes/connexion.tsx`) renders a checkbox labelled "Se souvenir de moi", unchecked by default, with an accessible label bound to it. | functional | "checks Remember me on the login form" | must |
| R2 | The checkbox state is sent in the same `POST /api/v1/auth/login` JSON request as the credentials, as an optional boolean `remember_me` field (absent/false when unticked); a value that is neither a boolean nor absent is rejected with a 400 rather than coerced. | functional | "when the user checks Remember me on the login form" | must |
| R3 | When `remember_me` is true and the credentials are valid, the login response sets a persistent remember-me cookie (`REMEMBERME`) with a future `Expires`/`Max-Age` and `Path=/`. | functional | "they stay authenticated after closing and reopening the browser" | must |
| R4 | When `remember_me` is absent or false and the credentials are valid, no persistent cookie is issued, any pre-existing remember-me cookie is cleared, and the login response status/body are unchanged from today. | functional | "when the user checks" (conditional) | must |
| R5 | A request that carries only the remember-me cookie (no session cookie) is authenticated: `GET /api/v1/auth/me` returns 200 with the customer identity and starts a fresh session. | functional | "stay authenticated after closing and reopening the browse[r]" | must |
| R6 | A request carrying neither the session cookie nor a valid remember-me cookie stays unauthenticated (401 on protected endpoints such as `GET /api/v1/auth/me`). | functional | conditional reading of "when the user checks" | must |
| R7 | A failed login attempt with `remember_me: true` sets no remember-me cookie. | functional | "when the user checks Remember me" presumes successful authentication | must |
| R8 | A tampered, truncated or expired remember-me cookie does not authenticate and never yields a 5xx or an HTML redirect; JSON callers receive the firewall's 401 JSON. | constraint | "stay authenticated" must not degrade into accepting any cookie | must |
| R9 | Logout clears the remember-me cookie in the same response, and a following cold start is unauthenticated. | functional | a user-controlled "remember" must be revocable | must |
| R10 | The remember-me cookie is `HttpOnly`, `Path=/`, `Secure` in production, and its `SameSite` policy mirrors the session cookie per environment (`lax` outside production, `none` in production where `framework.session.cookie_samesite` is already `none`). | constraint | not stated; must not regress the existing cookie policy in `framework.yaml` | must |
| R11 | The remember-me lifetime is a configuration value (not hardcoded in code), defaults to 14 days, and is documented in `.env` and `.env.prod.example`. | quality | "closing and reopening" says nothing about duration | should |
| R12 | On storefront cold start the app resolves the signed-in customer from the backend (`GET /api/v1/auth/me` + customer profile via the existing `StoreProvider` restore path) so a remembered customer is rendered as signed in; no remember-me secret is read from or written to `localStorage`/`sessionStorage`. | functional | "they stay authenticated" | must |
| R13 | Google OAuth logins and the post-registration auto-login keep their current session-only behavior: they issue no remember-me cookie and they clear any remember-me cookie an earlier login left in that browser. | constraint | "on the login form" scopes the feature; a surviving cookie would sign the next visit in as the previous customer | must |
| R14 | No CORS origin is added or reflected: `CorsSubscriber` keeps its explicit `STOREFRONT_CORS_ORIGIN` allow-list and `Access-Control-Allow-Credentials: true`. | constraint | not stated; the feature must not widen CORS | must |
| R15 | A cookie-based auto-login never cancels the remember-me cookie: it re-issues a valid cookie whose expiry is not earlier than the one it consumed (rolling expiry), so a second and every later browser restart stays authenticated. | quality | "closing and reopening" must keep working repeatedly | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "browse" is a typo for "browser". | low |
| 2 | "Stay authenticated" means the storefront shows the same customer and protected pages work on the next visit; it does not require preserving in-memory tab state. | low |
| 3 | Persistence is a separate remember-me cookie; the session cookie stays a browser-session cookie, so unticked logins behave exactly as today. A long-lived session cookie is a different design and is not implemented. | med |
| 4 | Mechanism: Symfony's `remember_me` firewall with the `signature` handler (HMAC over user + expiry with `%kernel.secret%`). It needs no new table/migration and invalidates automatically when the customer's password changes. | med |
| 5 | Production serves the storefront and the API on different domains (`deploy/Caddyfile` uses separate `STOREFRONT_DOMAIN` and `APP_DOMAIN`), which is why the session cookie is already `SameSite=None; Secure` in prod; the remember-me cookie follows the same per-environment policy. No new cross-site exposure is introduced. | med |
| 6 | The `main` firewall is shared by storefront and admin routes; a remembered customer still only carries their own `ROLE_*`, so no privilege escalation is possible. | low |
| 7 | The storefront label follows existing French copy. | low |
| 8 | Functional tests run against the existing Postgres test database and can simulate a browser restart by discarding the session cookie and replaying only the remember-me cookie in a fresh client. | med |
| 9 | Blocked customers (`customer_profile.status`) are out of scope: login does not check status today, so remember-me is not required to be stricter. | low |
| 10 | Remember-me is offered only on the login tab, not on the register tab that auto-logs-in. | low |

## Open questions
- [minor] Should changing the password invalidate the remembered credential? Default: yes, and it happens for free with the `signature` handler — no extra work planned.
- [minor] Should the checkbox be pre-ticked or remembered across visits? Default: no, always unchecked on page load.
- [minor] Should a guest-to-signed-in paint flash on cold start be eliminated? Default: acceptable; `authReady` already exists and no loading-skeleton work is planned.
- [minor] Should the cookie name stay `REMEMBERME`? Default: yes.

Gate note: the two questions marked BLOCKING by analyst C were resolved from repository evidence instead of asking the user — mechanism from the absence of any remember-me table/migration plus the password-change property (decision D1), and production topology from `deploy/Caddyfile` + `.env.prod.example` + the existing `when@prod` session cookie policy (decision D2). No user answer is pending.

## Out of scope
- Admin/back-office remember-me and device/session management UI (list or revoke remembered devices).
- Switching the session cookie to a long-lived cookie or migrating to token/JWT auth.
- Password reset, registration, Google OAuth flow changes, CSRF hardening of state-changing API routes, account status enforcement.
- Storefront redesign of the login page beyond adding the checkbox.
- Remember-me for the Wishlist/cart guest merge logic.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Backend: flagged login issues the persistent cookie, cookie-only requests authenticate, logout clears it, OAuth/register stay session-only and clear an older cookie | R2, R3, R4, R5, R6, R7, R8, R9, R10, R11, R13, R15 | `config/packages/security.yaml`, `config/services.yaml`, `src/Identity/Infrastructure/Security/LoginAuthenticator.php`, `src/Identity/Infrastructure/Http/GoogleOAuthController.php`, `.env`, `.env.prod.example`, `compose.prod.yaml`, existing `tests/Functional/AuthenticationTest.php` | `remember_me` firewall block with `signature` handler and env-driven lifetime, `RememberMeBadge` on the login and OAuth passports, malformed-cookie guard, functional tests | `config/packages/security.yaml`, `config/services.yaml`, `src/Identity/Infrastructure/Security/`, `src/Identity/Infrastructure/Http/GoogleOAuthController.php`, `.env`, `.env.prod.example`, `compose.prod.yaml`, `tests/Functional/RememberMeTest.php`, `tests/Unit/Identity/Infrastructure/Security/`, `tests/Integration/Identity/RememberMeCookiePolicyTest.php`, `tests/Integration/Identity/FreshProductionKernel.php` | none | `tests/Functional/RememberMeTest.php` fails before the change and passes after it, covering flagged login, unflagged login, cookie-only `/auth/me`, no-cookie 401, failed flagged login, tampered/truncated/expired cookie, logout, and two simulated restarts |
| WP2 | Storefront: checkbox, request field, no client-side secret | R1, R2, R4, R12, R13, R14 | `connexion.tsx`, `src/lib/api/storefront.ts`, `src/lib/api/types.ts` | Ticked-state forwarding and typed `remember_me?: boolean` input | `Zidane_front_web/src/routes/connexion.tsx`, `Zidane_front_web/src/lib/api/storefront.ts`, `Zidane_front_web/src/lib/api/types.ts` | none | Typecheck passes; the form submits `remember_me: true` only when ticked; the register and Google buttons are untouched |
| WP3 | Storefront: cold-start restore proven against the remember-me cookie | R12, R15 | `src/lib/store.tsx`, auth guard components | Verification note plus the minimal change if a gap exists | `Zidane_front_web/src/lib/store.tsx` | WP1, WP2 | Written evidence that boot restore calls `/api/v1/auth/me` with `credentials: include` and sets `authReady`, and that no secret is persisted client-side; any needed change is applied |
| WP4 | Coverage reconciliation | all | WP1–WP3 | Mapping table R1–R15 → test/artifact | `spec-run-7/coverage.md` | WP1, WP2, WP3 | Every requirement maps to at least one test or verified artifact, with pass/fail recorded |

## Decisions (synthesizer only)
| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Mechanism | Firewall remember-me + token table migration, no mechanism preference | Firewall remember-me, Symfony machinery acceptable | signature (default) vs persistent token table (BLOCKING) | `signature` handler with `%kernel.secret%` | No schema change or migration to maintain; invalidates on password change; the prompt asks for behavior, not server-side revocation. Persistent tokens would add a table and a migration for no requirement. |
| Prod cross-site topology | Implied same-site | Flagged as high-risk assumption | BLOCKING question | Mirror the session policy: `samesite: lax` outside prod, `none` + `secure` in prod | `deploy/Caddyfile` proxies `STOREFRONT_DOMAIN` and `APP_DOMAIN` separately and `framework.yaml` already sets `cookie_samesite: none` + `cookie_secure: true` under `when@prod`, so the cross-site case is the existing reality, not a new exposure. |
| Logout / invalidation | Not raised | Requirement R7 | Requirement R8 | Requirement kept (R9) | A "remember" the user cannot revoke is a defect; Symfony's `RememberMeListener` clears on `LogoutEvent` and `TokenDeauthenticatedEvent`, so it costs no design work. |
| Unflagged login clears an old cookie | Not raised | Not raised | Not raised | Explicit requirement (R4) | `RememberMeListener` clears the cookie before checking the badge's enabled state, so passing `_remember_me => false` on the badge gives the correct "unchecked means forget me" semantics instead of silently keeping an older cookie. |
| Lifetime | Config value, default 1 year | Configurable, > one session | Bounded, shorter than forever | `REMEMBER_ME_LIFETIME` env, default 1209600 (14 days) | The prompt asks only to survive restarts; 14 days is a bounded convenience window and the value stays ops-tunable. |
| Remember-me badge always present | Not raised | Not raised | Not raised | Add `RememberMeBadge` with `['_remember_me' => $flag]` on every login passport | Guarantees both the "issue" and the "clear" paths from one code path, and keeps the badge decision inside the authenticator (JSON body, not a form parameter). |
| Storefront cold-start work | Reuse existing restore | Reuse existing restore | Reuse existing restore | Verification package, change only if a gap is found | `src/lib/store.tsx` already calls `getCurrentUser()` then `getCustomerProfile()` with `credentials: include`; the feature needs the cookie, not a new restore path. |
| Auto-login rotation | Not raised | Not raised | "does not rotate" (R10 of C) | Rolling expiry accepted: v1 could not hold it. Symfony's `SignatureRememberMeHandler` re-issues the cookie (new expiry + HMAC) on every cookie-based login | Rotation is the framework's normal sliding-expiration behaviour, it never cancels the credential, and persistence across restarts is what the prompt asks for. Byte-equality was measurable only inside one wall-clock second, i.e. it was a flaky assertion, not a behaviour. |
| Label language | "Se souvenir de moi" | "Se souvenir de moi" | "Se souvenir de moi" | "Se souvenir de moi" | Storefront UI copy is French. |
| OAuth stale cookie | Not raised | R7 "session-only" | Not raised | OAuth and register logins clear an older remember-me cookie (R13) | `Security::login()` without a `RememberMeBadge` makes `RememberMeListener` return before clearing, so a password-login cookie survives a Google login in the same browser and the next cold start authenticates as the previous customer. Attaching a disabled badge is the same clear semantics the password path already has. |
| Cookie name coupling | Not raised | Not raised | Not raised | Declare `remember_me.name` in `security.yaml` from one `remember_me_cookie_name` parameter, injected into the malformed-cookie guard | The guard must find the cookie the firewall reads; leaving the name implicit in two places fails silently (guard becomes a no-op, R8 500 returns). |
| Malformed-cookie guard implementation | private mirror accepted | private mirror accepted | private mirror accepted | Delegate to `RememberMeDetails::fromRawCookie()` and treat `AuthenticationException`/`TypeError` as "remove the cookie" | A hand-copied parser drifts from the vendor on upgrade: too strict logs every remembered customer out, too lax restores the 500. Delegating makes the guard self-adapting and is ~8 lines. |

## Changelog (synthesizer and orchestrator only)
- v1: created from analyst-a, analyst-b and analyst-c.
- v2: R15 reworded after the REFACTOR gate measured a rolling expiry/signature rotation by `SignatureRememberMeHandler`; the "does not rotate" clause of v1 was unachievable with the chosen `signature` handler and unnecessary for persistence. Decision row "Auto-login rotation" added.
- v3: after the REVIEW gate. R13 now requires OAuth/register logins to clear an older remember-me cookie (measured cross-account identity confusion), R2 now rejects a non-boolean `remember_me` instead of coercing it, WP1 owns the guard/unit/integration files it actually produced, and three Decision rows were added (OAuth stale cookie, cookie-name coupling, guard delegation to the vendor decoder).

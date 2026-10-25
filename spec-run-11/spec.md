# Spec: HttpOnly session cookie as the only credential (no localStorage session token)
Version: 3

## Goal
The customer's session is carried only by a cookie the API emits with `HttpOnly` (`Secure` + `SameSite` per environment), nothing session-bearing is readable or storable from JavaScript, and tests fail if either guarantee regresses — while sign-in, reload, remember-me and logout keep behaving exactly as today.

## Corrected prompt
Secure the session token by carrying it in an HttpOnly cookie instead of storing it in localStorage.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The session credential is delivered only through a `Set-Cookie` response header; no JSON response body (login, register, Google callback, `/me`) contains the session identifier or any credential that authenticates a later request. | functional | "using an HttpOnly cookie instead of localStorage" | must |
| R2 | The session cookie is emitted with the `HttpOnly` attribute in every environment, proven by an assertion on the raw `Set-Cookie` header of the login response. | constraint | "HttpOnly cookie" | must |
| R3 | The session cookie is `Secure` in production only, `SameSite=None` in production and `SameSite=Lax` outside production (dev, test), proven from kernels booted per environment rather than from the YAML text. | constraint | "secure sessionToken" | must |
| R4 | The dev/test setup keeps working over plain HTTP: `localhost:3000` -> `localhost:8081` stays a valid credentialed cross-origin call under `SameSite=Lax` (ports are ignored by SameSite). | constraint | "secure" | must |
| R5 | The storefront never writes a credential to `localStorage` or `sessionStorage`; the persisted `ziden-store-v1` payload holds only non-credential guest UI state (guest cart, guest wishlist, language), with no identity and no account data (`user`, `orders`, `addresses` written empty and stripped on load). | functional | "instead of localStorage" | must |
| R6 | Auth material left in `ziden-store-v1` by an older build is dropped on load: a stored `user` object can never produce a signed-in UI. | functional | "instead of localStorage" | must |
| R7 | Signed-in state comes only from `GET /api/v1/auth/me`: 200 sets the user, 401 clears the user (and its roles) instead of leaving the restored value, and `authReady` gates auth-dependent rendering. | functional | "sessionToken" (the session is the credential) | must |
| R8 | Logout expires the session cookie and the remember-me cookie, and authenticated endpoints return 401 afterwards. | functional | "secure" | must |
| R9 | Every storefront call that needs the session is sent with `credentials: "include"`, whatever the verb (`GET`/`POST`/`PUT`/`PATCH`/`DELETE`). | functional | "HttpOnly cookie" | must |
| R10 | Regression tests pin the contract: the login `Set-Cookie` attributes, the exact login JSON key set, and the per-environment session cookie policy; dropping `HttpOnly`, leaking a token field, or changing the prod flags turns a test red. | quality | "HttpOnly cookie" | must |
| R11 | No regression for the customer: guest cart, guest wishlist and language still restore after reload, and the release forces no mass re-login (no cookie rename, no `cookie_domain` change, no `APP_SECRET` rotation). | quality | "instead of" (same behaviour, different transport) | should |
| R12 | An ADR records the decision, the rejected localStorage alternative, the per-environment flags as shipped, and the consequences of a cross-site cookie (CORS allow-list with credentials, CSRF posture, `GET`-capable logout route). | quality | "instead of localStorage" | should |
| R13 | Frontend verification stays inside the existing toolchain (`npm run typecheck`, `npm run lint`); no new test framework or dependency is introduced. | constraint | implied by "instead of" | should |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "sessionToken" means the credential that keeps the storefront signed in today, i.e. the Symfony session cookie; no token of that name exists in the tree. | med |
| 2 | The working tree already authenticates by cookie, so this work is targeted hardening plus proof, not a new token transport. | med |
| 3 | The stale `user` object restored from `ziden-store-v1` is the concrete localStorage problem the prompt reacts to. | med |
| 4 | Production keeps `SameSite=None` + `Secure` because the storefront and API live on different registrable domains (`deploy/Caddyfile`). | med |
| 5 | No client outside this workspace (mobile app, legacy admin build, third-party SPA) reads a localStorage token. | high |
| 6 | No authenticated data is fetched server-side through `client.ts` (no browser cookie exists there); if one is found during implementation it is reported, not guessed at. | low |
| 7 | Cross-tab sign-out propagation is acceptable through the next `/me` call; no `storage` event listener is required once the user mirror leaves localStorage. | low |
| 8 | Existing suites stay green: `RememberMeTest`, `GoogleOAuthTest`, `CorsTest`, `RememberMeCookiePolicyTest`. | low |

## Open questions
- [resolved v2] The prompt says the token lives in localStorage, but this working tree stores none. Answer: this storefront today — harden the existing cookie session and remove the residual localStorage `user`. No client outside this workspace is involved, so assumption 5 is settled and R1-R13 apply as written; WP1, WP2 and WP3 are all in scope.
- [minor] Should `identity_logout` stop accepting `GET` (cross-site triggerable, more relevant with `SameSite=None`)? Default if unanswered: keep the route methods, record the risk in the ADR (R12).
- [minor] Should the session cookie gain an explicit `cookie_lifetime` (it is a browser-session cookie today; remember-me carries persistence)? Default if unanswered: keep the browser-session cookie.
- [minor] Should the cookie be renamed with a `__Host-` prefix? Default if unanswered: no rename (R11 forbids a mass re-login).
- [minor] On 401, should the guest cart/wishlist mirror be kept or wiped? Default if unanswered: keep it — clear only the user so an expired session does not destroy a shopper's cart.

## Out of scope
- Replacing the session with JWT/bearer tokens, refresh tokens, or a stateless API.
- New CSRF tokens on JSON endpoints; the existing CORS allow-list and `SameSite` posture are documented, not redesigned.
- CSP/security headers, WAF, rate limiting, bot protection.
- Remember-me lifetime, rotation or revocation changes (`spec-run-7` owns them).
- Session-fixation re-engineering beyond what Symfony's stateful authenticator already does (verify only).
- Cookie rename to `__Host-`, `cookie_domain` pinning, `APP_SECRET` rotation.
- Storefront redesign, new auth screens, Next.js server actions, adding Vitest.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Pin the API credential surface with header-level tests per environment and correct the config only where a test proves it wrong | R1, R2, R3, R4, R8, R10 | `config/packages/framework.yaml`, `config/packages/security.yaml`, `src/Identity/Infrastructure/Security/LoginAuthenticator.php`, `src/Identity/Infrastructure/Http/AuthController.php`, `tests/Integration/Identity/RememberMeCookiePolicyTest.php` (pattern) | `tests/Functional/SessionCookieTest.php`, `tests/Integration/Identity/SessionCookiePolicyTest.php`, plus any config correction those tests force | `tests/Functional/SessionCookieTest.php`, `tests/Integration/Identity/SessionCookiePolicyTest.php`, `config/packages/framework.yaml`, `config/packages/security.yaml` | none | login `Set-Cookie` raw header contains `HttpOnly`; prod kernel yields `Secure` + `SameSite=None`, test env `Lax` without `Secure`; login JSON keys are exactly `id`, `email`, `roles`; logout response expires the session + remember-me cookies and a later authenticated call is 401 |
| WP2 | Make the storefront server-authoritative for auth and stop persisting any user/credential | R5, R6, R7, R9, R11, R13 | `Zidane_front_web/src/lib/store.tsx`, `src/lib/api/client.ts`, `src/lib/api/storefront.ts`, `src/lib/api/types.ts`, `src/lib/auth-redirect.ts` | Store change: sanitised read (drop `user`), no user in the persisted payload, 401 clears the user, guest state preserved | `Zidane_front_web/src/lib/store.tsx`, `Zidane_front_web/src/lib/api/storefront.ts`, `Zidane_front_web/src/lib/api/types.ts` | none | `npm run typecheck` and `npm run lint` pass; a stale `user` in `ziden-store-v1` cannot sign a visitor in; guest cart/wishlist/language still restore after reload; every `client.ts` call keeps `credentials: "include"` |
| WP3 | Record the decision and the cross-site cookie consequences | R12 | WP1 outcome, `docs/adr/ADR-0011-remember-me-credential.md`, `tests/Functional/CorsTest.php` | `docs/adr/ADR-0013-session-cookie-credential.md` | `docs/adr/ADR-0013-session-cookie-credential.md` | WP1 | ADR states the decision, rejected localStorage alternative, per-env flags as shipped, CORS/CSRF posture, and the `GET`-logout risk |

## Decisions (synthesizer only)
| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Nature of the work | Hardening + proof | Close the staleness hole + prove | Prove, remove the mirror, flag high-risk assumptions | Verification + targeted hardening, no re-architecture | The tree has no localStorage token and already sets `HttpOnly`; the prompt's intent (JS must not read the credential) is met by making the property explicit, tested and free of residual storage. |
| Which codebase the prompt means | None raised | None raised | BLOCKING | Keep one BLOCKING question with the "current tree" default | The risk of doing the wrong work is real (assumption 5, high), but the current tree stays authoritative if unanswered, so the run is not blocked indefinitely. |
| Legacy localStorage `user` | Purge | Purge and ignore | Purge and ignore | Purge on load and never trust it (R6) | Both B and C converge; it is the only concrete "session in localStorage" left in the codebase. |
| What a 401 clears | Clear user | Clear user, cart, orders, wishlist | Clear user, keep guest cart/wishlist | Clear the user only (R7), keep guest mirrors (R11) | Clearing the guest cart destroys a shopper's work on a merely expired session; B's wider wipe adds UX damage with no security gain, since server data is re-fetched on the next sign-in. |
| CSRF / `GET` logout | Out of scope | Document the posture | Minor open question | Document only (R12), no new CSRF layer | A CSRF redesign is a separate security decision with its own ADR; adding tokens here would expand the diff beyond the prompt. |
| Cookie prefix / rename | `__Host-` as open question | Out of scope | Keep host-only | Keep the current name (R11) | A rename invalidates every live session and is not needed to meet the prompt. |
| Frontend proof | `tsc` + `eslint` | Test runner only if dependency-free | `tsc` + `eslint` | `npm run typecheck` + `npm run lint` (R13) | No test runner is wired in `package.json`; adding one is scope creep the prompt does not ask for. |
| Documentation | ADR | ADR | ADR | One ADR, `ADR-0013` (R12) | All three ask for it; it is also the harness's audit trail for a security decision. |

## Changelog (synthesizer and orchestrator only)
- v1: created from analyst-a, analyst-b, analyst-c.
- v2: BLOCKING question answered by the user — the prompt targets this storefront, so the cookie session is hardened and the stale localStorage `user` is removed; both the API (WP1) and the storefront (WP2) changes ship, with the ADR (WP3).
- v3: R5 tightened after the REVIEW gate (F8): the payload also drops `orders`/`addresses`, which carried PII and had no consumer; R9 evidence downgraded to inspection after the same review (F5).

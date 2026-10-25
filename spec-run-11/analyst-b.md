# Spec: XSS-resistant storefront session (HttpOnly cookie, no Web Storage credential)
Version: 1

## Repository context (same brief as the other analysts)
- API: `E-commerce/` Symfony 8.1 modular monolith. Login = `POST /api/v1/auth/login` handled by `src/Identity/Infrastructure/Security/LoginAuthenticator.php`, response `{id, email, roles}` (no token). `AuthController` serves `/register`, `/forgot-password`, `/reset-password`, `/me`, and stubs `/login` + `/logout` that the firewall intercepts.
- Session cookie config: `config/packages/framework.yaml` — `cookie_httponly: true`, `cookie_samesite: lax`, `cookie_secure: false`; `when@prod` sets `cookie_secure: true`, `cookie_samesite: none`. Remember-me (`config/packages/security.yaml`) is `httponly: true`, prod `SameSite=None; Secure`.
- Frontend: `Zidane_front_web/src/lib/api/client.ts` already sends `credentials: "include"` on every call. `src/lib/store.tsx` persists to localStorage key `ziden-store-v1` with `user: null` forced on write, restores stored payload on mount (so a stale `user` survives), and the `/me` probe leaves state untouched on 401. `src/lib/auth-redirect.ts` and `src/routes/panier.tsx` use `sessionStorage` for a return path and a promo code (not credentials).
- Google sign-in (`GoogleOAuthController`) logs the customer in server-side and redirects; no token is handed to the browser.
- No `sessionToken`, no Bearer header, no Supabase auth anywhere in the repo (grep). The credential is the Symfony session cookie.
- Related tests: `tests/Functional/RememberMeTest.php`, `tests/Integration/Identity/RememberMeCookiePolicyTest.php`, `tests/Functional/GoogleOAuthTest.php`, `tests/Functional/CorsTest.php`.

## Goal
A malicious script injected into the storefront cannot read, copy or replay a customer's session, because the only credential is an HttpOnly cookie that the browser attaches automatically and the storefront never needs to touch; behaviour a customer sees (stay signed in, log out, refresh) is unchanged.

## Corrected prompt
Store the session token in an HttpOnly cookie instead of localStorage, so client-side JavaScript can never read it.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | No session credential is readable from JavaScript: it is transmitted only in a `Set-Cookie` header marked `HttpOnly`. | constraint | "HttpOnly cookie instead of localStorage" | must |
| R2 | No endpoint in the API returns the session identifier (or any credential that authenticates a later request) in a JSON body; login, register, Google callback and `/me` included. | functional | "instead of localStorage" | must |
| R3 | The storefront never stores a credential in `localStorage`/`sessionStorage`; state persisted under `ziden-store-v1` holds only non-credential UI data (cart for guests, language, wishlist). | functional | "instead of localStorage" | must |
| R4 | A returning visitor whose browser still holds a legacy `user` object under `ziden-store-v1` is not shown a signed-in UI unless `GET /api/v1/auth/me` answers 200; the stale field is dropped from storage. | functional | implied by the migration the prompt asks for | must |
| R5 | Session state is single-sourced from the server: successful `/me` sets the user, any 401 response clears the user, cart, orders and wishlist local state, and this happens before the first auth-guarded page renders (`authReady`). | functional | implied (the session, not the browser, is the source of truth) | must |
| R6 | Every state-changing storefront call (`POST`/`PUT`/`PATCH`/`DELETE`) is issued with credentials so the cookie is attached; a call that omits credentials is a defect. | functional | implied by cookie-based transport | must |
| R7 | The session cookie policy is fixed per environment and provable: `HttpOnly` everywhere, `Secure` + `SameSite=None` in production (storefront and API are separate origins), `SameSite=Lax` and non-secure in dev/test. | constraint | "secure sessionToken" | must |
| R8 | Logging out removes the credential server-side: the logout response expires the session cookie and clears any remember-me cookie, so a copied cookie value cannot be replayed after logout. | functional | implied ("secure") | must |
| R9 | Because the cookie now travels cross-site in production, the API keeps an explicit credential-aware CORS allow-list (no wildcard origin with credentials) and CSRF posture is documented; a new cross-site JSON endpoint must justify why it is safe. | quality | implied by "HttpOnly cookie" | should |
| R10 | Regression tests fail if the guarantee breaks: functional test asserting `HttpOnly` on the login `Set-Cookie` and the exact login JSON keys; environment test asserting prod flags; a frontend check asserting no credential write to Web Storage. | quality | "HttpOnly cookie" | must |
| R11 | The guarantee is documented where a future contributor will look (ADR + a comment next to the session config), including why localStorage is rejected. | quality | "instead of localStorage" | should |
| R12 | Server-side rendering and the Google OAuth redirect keep working without a JS-visible token: the Next server never needs the cookie to render, and the OAuth flow stays a full-page redirect. | quality | implied by "instead of" (no regression) | should |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The intended goal is XSS resistance: the prompt is the standard remedy for a token kept in localStorage, and the customer-visible behaviour must not change. | low |
| 2 | The repo has already migrated to cookie sessions, so the deliverable is closing the remaining localStorage staleness hole plus making the guarantee explicit and tested rather than building token transport from scratch. | med |
| 3 | "sessionToken" refers to whatever keeps the customer signed in; today that is the Symfony session cookie, and the remember-me cookie is part of the same surface. | med |
| 4 | The legacy localStorage `user` object is the residual risk the prompt is reacting to; clearing it is in scope, restoring "sign in from localStorage" is not. | med |
| 5 | A signed-in phone/browser session must survive a page reload and a browser restart when "remember me" was ticked — unchanged by this work. | low |
| 6 | Adding a frontend test framework is allowed only if it needs no new dependency beyond what `package.json` already lists (`jsdom`, `@testing-library/*`). Otherwise proof is `tsc`/`eslint` + a scripted check. | med |

## Open questions
- [minor] Should the API additionally send `Referrer-Policy: no-referrer` / other headers that protect a cookie-authenticated cross-origin flow, or is that a separate hardening task? Default if unanswered: separate task, recorded as out of scope.
- [minor] Should the storefront drop the promo code and return path from `sessionStorage` too (paranoid reading of "instead of localStorage")? Default if unanswered: keep them — they are not credentials.
- [minor] Is a CSRF token required for cookie-authenticated JSON writes now that `SameSite=None` applies in production? Default if unanswered: keep the current CORS allow-list + `SameSite` posture, document it in the ADR.

## Out of scope
- Replacing the session with JWT / bearer tokens or making the API stateless.
- Changing the remember-me lifetime, rotation or revocation semantics (`spec-run-7` owns them).
- New CSP/security-header work, WAF rules, or rate limiting.
- Storefront redesign, new auth pages, or moving auth to Next.js server actions.
- Rotating the app secret or forcing every user to sign in again (no `__Host-` prefix rename in this run).

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Prove and pin the API side: no credential in JSON, `HttpOnly`/`Secure`/`SameSite` per env, logout revokes | R1, R2, R7, R8, R10 | `framework.yaml`, `security.yaml`, `LoginAuthenticator.php`, `AuthController.php` | Functional + environment cookie tests; config corrections they reveal | `tests/Functional/SessionCookieTest.php`, `tests/Integration/Identity/SessionCookiePolicyTest.php`, `config/packages/framework.yaml`, `config/packages/security.yaml` | none | login `Set-Cookie` is HttpOnly; prod kernel proves Secure+SameSite=None; login body keys are exactly id/email/roles; logout cookies are expired |
| WP2 | Storefront: server-authoritative auth, legacy key sanitised, no credential persistence | R3, R4, R5, R6, R12 | `src/lib/store.tsx`, `src/lib/api/client.ts`, `src/lib/api/storefront.ts`, `src/lib/api/types.ts` | Store refactor + sanitising read + 401 clearing | `Zidane_front_web/src/lib/store.tsx`, `Zidane_front_web/src/lib/api/storefront.ts`, `Zidane_front_web/src/lib/api/types.ts` | none | typecheck + lint pass; stale `user` cannot sign a visitor in; guest cart/lang still restore |
| WP3 | Document the decision and the CSRF/CORS posture | R9, R11 | WP1 outcome, `docs/adr/ADR-0011-remember-me-credential.md`, `tests/Functional/CorsTest.php` | ADR "session cookie is the only credential" | `docs/adr/ADR-0013-session-cookie-credential.md` | WP1 | ADR records decision, rejected localStorage alternative, environment flags, CORS/CSRF posture |

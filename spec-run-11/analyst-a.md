# Spec: Session credential moved out of localStorage into an HttpOnly cookie
Version: 1

## Repository context given to every analyst
- Working root `E-commerce/` = Symfony 8.1 modular-monolith API (CQRS harness). Storefront = `Zidane_front_web/` (Next 15, App-Router-style `src/routes`).
- API login `POST /api/v1/auth/login` is handled by `src/Identity/Infrastructure/Security/LoginAuthenticator.php`, which returns JSON `{id, email, roles}` — no token field. `/api/v1/auth/logout` and `/api/v1/auth/me` live in `src/Identity/Infrastructure/Http/AuthController.php`.
- Session persistence is Symfony's own session cookie: `config/packages/framework.yaml` sets `cookie_httponly: true`, `cookie_samesite: lax`, `cookie_secure: false`, and `when@prod` overrides `cookie_secure: true`, `cookie_samesite: none`. Remember-me cookie in `config/packages/security.yaml` is `httponly: true` too.
- Storefront HTTP client `Zidane_front_web/src/lib/api/client.ts` already sends `credentials: "include"` on every request.
- Storefront state `Zidane_front_web/src/lib/store.tsx` persists under localStorage key `ziden-store-v1`, forces `user: null` when writing, but on mount merges the stored payload into state (`{...initial, ...stored}`) so an older stored `user` object is honoured. The session probe (`getCurrentUser()` -> `/api/v1/auth/me`) does nothing on 401: `user` stays whatever was restored.
- `sessionStorage` is used only for the post-login redirect path (`src/lib/auth-redirect.ts`) and the promo code (`src/routes/panier.tsx`).
- A repo-wide grep finds no `sessionToken`, no token in `localStorage`, no `Authorization: Bearer` header, no Supabase auth. The credential in use today is the Symfony session cookie.
- Existing related coverage: `tests/Functional/RememberMeTest.php`, `tests/Integration/Identity/RememberMeCookiePolicyTest.php`, `tests/Functional/GoogleOAuthTest.php`, `tests/Functional/CorsTest.php`.

## Goal
The storefront's session credential is only ever a cookie the API issues with `HttpOnly`, `Secure` in production and a deliberate `SameSite` policy, and nothing that grants a session is readable from JavaScript or persisted in Web Storage.

## Corrected prompt
Secure the session token by transporting it in an HttpOnly cookie instead of storing it in localStorage.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The session credential is issued only as a `Set-Cookie` header by the API and is never included in any JSON response body (login included). | functional | "using an HttpOnly cookie instead of localStorage" | must |
| R2 | The session cookie is emitted with the `HttpOnly` attribute in every environment (dev, test, prod). | constraint | "HttpOnly cookie" | must |
| R3 | The session cookie carries `Secure` in production and is not `Secure` outside production, mirroring the existing session policy. | constraint | "secure sessionToken" | must |
| R4 | The session cookie's `SameSite` policy is explicitly configured per environment and matches the storefront/API origin layout (separate origins in production). | constraint | "secure" | must |
| R5 | The storefront never writes the session credential to `localStorage` or `sessionStorage`; the only auth-related Web Storage usage that remains is non-credential UI state. | functional | "instead of localStorage" | must |
| R6 | Any auth material persisted under the legacy storefront key `ziden-store-v1` is removed on load and is never used to derive a signed-in state. | functional | "instead of localStorage" | must |
| R7 | The storefront's signed-in state is derived exclusively from the server session probe (`GET /api/v1/auth/me`, 200 = signed in); a 401 clears the local user state instead of keeping the restored value. | functional | "sessionToken" (session is authoritative) | must |
| R8 | Every storefront request that needs the session is sent with credentials so the cookie is attached, including POST/PUT/DELETE. | functional | "HttpOnly cookie" | must |
| R9 | Logout clears the session cookie (`Set-Cookie` with an expired/empty value) so the credential cannot be replayed. | functional | "secure" | must |
| R10 | Automated tests assert the cookie attributes instead of the YAML text: a functional test fails if `HttpOnly` disappears from the login `Set-Cookie`, and an environment test fails if the prod policy stops being `Secure` + `SameSite=None`. | quality | "HttpOnly cookie" | must |
| R11 | The storefront login response is proven token-free: the React/TS client type for the login result has no token field, and a backend functional test asserts the `/api/v1/auth/login` body keys. | quality | "instead of localStorage" | should |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | `sessionToken` in the prompt means the credential that keeps the storefront signed in today, i.e. the Symfony session cookie (the repo contains no other token). | med |
| 2 | The task is hardening plus proof, not a rewrite: the cookie transport already exists, so most work is closing the localStorage staleness gap and adding regression tests. | med |
| 3 | `ziden-store-v1` may still contain a `user` object from an older build on returning visitors' browsers; that is the "localStorage" the prompt refers to. | low |
| 4 | Production keeps `SameSite=None` because the storefront and API are different origins (`deploy/Caddyfile`); changing that is out of scope. | med |
| 5 | The storefront has no test runner configured today, so frontend proof is done with `tsc --noEmit` / `eslint` plus a focused check, not a new test framework. | med |

## Open questions
- [minor] Should the session cookie be renamed to a `__Host-`/`__Secure-` prefixed name (stronger subdomain/Downgrade protection, but renames the cookie and invalidates live sessions on deploy)? Default if unanswered: keep the Symfony default name; document the tradeoff.
- [minor] Should the API expose an explicit `/api/v1/auth/session` alias instead of relying on `/me`? Default if unanswered: keep `/me`.

## Out of scope
- Replacing Symfony's session storage with JWT/OAuth bearer tokens, or introducing a stateless API.
- Adding new CSRF tokens to JSON endpoints (the CORS allow-list and `SameSite` policy stay as they are; existing CSRF config untouched).
- Changing remember-me lifetime or semantics (already specified in `spec-run-7`).
- Any admin dashboard redesign or storefront UI changes beyond auth state handling.
- Migrating to `__Host-` cookie prefix (kept as open question).

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Harden and pin the API session/remember-me cookie policy, and prove it per environment | R1, R2, R3, R4, R9, R10 | `config/packages/framework.yaml`, `config/packages/security.yaml`, `src/Identity/Infrastructure/Security/LoginAuthenticator.php` | Cookie-policy tests (functional + env) and any config correction they force | `config/packages/framework.yaml`, `config/packages/security.yaml`, `tests/Functional/SessionCookieTest.php`, `tests/Integration/Identity/SessionCookiePolicyTest.php` | none | `Set-Cookie` on login is `HttpOnly` in test env; prod kernel assertion proves `Secure` + `SameSite=None`; logout response expires the cookie; login JSON has no token key |
| WP2 | Make the storefront server-authoritative for auth state and purge legacy storage | R5, R6, R7, R8, R11 | `Zidane_front_web/src/lib/store.tsx`, `Zidane_front_web/src/lib/api/client.ts`, `Zidane_front_web/src/lib/api/storefront.ts` | Store rewrite: no auth material persisted, legacy key sanitised, 401 clears user | `Zidane_front_web/src/lib/store.tsx`, `Zidane_front_web/src/lib/api/storefront.ts`, `Zidane_front_web/src/lib/api/types.ts` | none | `npm run typecheck` and `npm run lint` pass; grep shows no credential write; a stale `user` in `ziden-store-v1` cannot produce a signed-in UI |
| WP3 | Document the cookie-only credential policy | R1, R4 | WP1 outcome, `docs/adr/ADR-0011-remember-me-credential.md` | Short ADR for the session cookie (HttpOnly/Secure/SameSite, no Web Storage) | `docs/adr/ADR-0013-session-cookie-credential.md` | WP1 | ADR states the decision, the rejected localStorage alternative and the per-environment flags actually shipped |

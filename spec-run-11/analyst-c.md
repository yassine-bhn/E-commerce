# Spec: HttpOnly session cookie replacing localStorage (skeptic pass)
Version: 1

## Repository context (brief shared with the other analysts)
- `E-commerce/` = Symfony 8.1 API. `POST /api/v1/auth/login` -> `LoginAuthenticator::onAuthenticationSuccess` returns `{id, email, roles}`. `/api/v1/auth/me` returns the session user or 401. `/api/v1/auth/logout` is a firewall stub; the route accepts `POST` **and** `GET` and is `PUBLIC_ACCESS` (`config/packages/security.yaml`).
- Session cookie: `config/packages/framework.yaml` -> `cookie_httponly: true`, `cookie_samesite: lax`, `cookie_secure: false`, `cookie_path: /`; `when@prod` -> `cookie_secure: true`, `cookie_samesite: none`. No `cookie_domain`, no `cookie_lifetime`, no custom `name`. Remember-me cookie: `httponly: true`, `samesite: lax` / prod `none`+`secure`.
- `Zidane_front_web/src/lib/api/client.ts`: every request is `credentials: "include"`; on the server it uses `cache: "no-store"`; it never forwards cookies and has no `Authorization` header.
- `src/lib/store.tsx`: localStorage key `ziden-store-v1`; write path forces `user: null` but keeps `cart`, `wishlist`, `lang`, `orders`, `addresses`; read path does `{...initial, ...stored}` so a stored `user` object is restored; the `/me` probe returns early on 401 without clearing `user`.
- `sessionStorage` used for the post-login redirect (`auth-redirect.ts`) and the promo code (`panier.tsx`).
- Google OAuth: server-side `$this->security->login(...)` + `RedirectResponse`; state stored in the session.
- No `sessionToken`, Bearer header, Supabase auth, or token persistence anywhere in the repo (repo-wide grep).
- Existing cookie coverage: `tests/Functional/RememberMeTest.php`, `tests/Integration/Identity/RememberMeCookiePolicyTest.php`, `tests/Functional/GoogleOAuthTest.php`, `tests/Functional/CorsTest.php`. There is **no** test on the session cookie itself.
- Storefront has no test runner in `package.json` (`jsdom` + `@testing-library/*` are dev deps but unused); only `lint`, `typecheck`, `build`.

## Goal
Make "the session lives in an HttpOnly cookie and nothing session-bearing is ever readable or storable from JavaScript" a property that is true, tested and hard to regress — while keeping the customers' current sign-in/reload/logout behaviour.

## Corrected prompt
Store the session token in an HttpOnly cookie rather than in localStorage.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The session credential is delivered exclusively through a `Set-Cookie` response header carrying `HttpOnly`; no JSON response contains a credential a client could store and replay. | constraint | "HttpOnly cookie instead of localStorage" | must |
| R2 | The session cookie is `Secure` in production only, `SameSite=None` in production and `SameSite=Lax` outside it, and this is asserted from a booted kernel per environment (not from the YAML text). | constraint | "secure" | must |
| R3 | The storefront writes no credential to `localStorage`/`sessionStorage`; any legacy auth material under `ziden-store-v1` is removed on first load. | functional | "instead of localStorage" | must |
| R4 | The signed-in UI is decided only by `GET /api/v1/auth/me`: 200 sets the user, 401 (or any failure after a previous success) clears user, cart, orders and wishlist, and `authReady` gates auth-dependent rendering. | functional | "sessionToken" (the session is the credential) | must |
| R5 | Persisted storefront state that survives a reload contains no session-bearing field; guest cart, wishlist and language still restore (no regression). | functional | "instead of localStorage" | must |
| R6 | Logout clears both the session cookie and the remember-me cookie, and the account page can no longer call authenticated endpoints afterwards. | functional | "secure" | must |
| R7 | Marking a cookie `HttpOnly` is proven by an assertion on the raw `Set-Cookie` header (PHP cannot observe browser semantics), and the assertion fails if the attribute is dropped. | quality | "HttpOnly" | must |
| R8 | Cross-tab / cross-device behaviour is stated: signing out in one tab must not leave another tab silently authenticated against the API; any local mirror of the user is derived from `/me`, never trusted. | quality | implied by removing the localStorage mirror | should |
| R9 | The dev setup keeps working: `http://localhost:3000` -> `http://localhost:8081` is same-site (ports are ignored by SameSite), so `Lax` + non-`Secure` must stay valid in dev; production stays `None`+`Secure` because the storefront and API are different registrable domains. | constraint | "secure" | must |
| R10 | The change ships without forcing a mass re-login: no cookie rename, no `cookie_domain` change, no `APP_SECRET` rotation. | constraint | implied by "instead of" | must |
| R11 | The decision, the rejected localStorage alternative and the CSRF/CORS consequences of a cross-site cookie are recorded in an ADR linked from the session config. | quality | "instead of localStorage" | should |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The prompt describes this repo's storefront; no other client (mobile app, third-party SPA, old admin build) relies on a localStorage token. | high |
| 2 | "sessionToken" = the Symfony session cookie, because no token of that name exists in the tree. | high |
| 3 | The stale `user` restored from `ziden-store-v1` is the concrete localStorage problem to remove. | med |
| 4 | Behavioural compatibility matters more than new features: existing tests (`RememberMeTest`, `GoogleOAuthTest`, `CorsTest`) must stay green. | low |
| 5 | Frontend proof stays within `tsc`/`eslint` unless a test runner is explicitly added; adding Vitest is not assumed. | med |
| 6 | `/api/v1/auth/me` remains the session probe and is not renamed. | low |

## Open questions
- [BLOCKING] The prompt says the token is in localStorage, but this working tree has no such storage: is the statement about (a) this repo's storefront today, (b) a deployed older build, or (c) another client not in this workspace? Default if unanswered: (a)+(b) — treat the current tree as authoritative and remove the residual localStorage `user`.
- [minor] Should `identity_logout` stop accepting `GET` (a GET logout is cross-site triggerable and now matters more with `SameSite=None`)? Default if unanswered: leave the route methods as they are; note the risk in the ADR.
- [minor] Should the session cookie get `cookie_lifetime` explicitly (currently a browser-session cookie, with remember-me carrying persistence)? Default if unanswered: keep the current browser-session cookie.
- [minor] Should `cookie_domain` be pinned (currently unset, so host-only) — and would a `__Host-` prefix be preferred? Default if unanswered: keep host-only default, no rename (see R10).
- [minor] Is an empty `cart`/`wishlist` wipe on 401 acceptable UX for a customer whose session simply expired mid-browsing (guest cart would be lost)? Default if unanswered: clear user state but keep the guest cart/wishlist mirror so nothing the customer typed is destroyed.
- [minor] Does any SSR path fetch an authenticated endpoint through `client.ts` (where no browser cookie exists), which would make a cookie-only session silently unusable server-side? Default if unanswered: assume no such path; verify during implementation and report if one is found.

## Out of scope
- JWT/bearer rework, stateless API, refresh tokens.
- Session fixation hardening beyond what Symfony's stateful authenticator already does (verify, do not re-engineer).
- CSP/security headers, WAF, rate limiting, bot protection.
- Remember-me lifetime/rotation changes (`spec-run-7`).
- Storefront UI redesign, new auth screens, Next.js server actions.
- Renaming the cookie or rotating secrets (would force re-login; see R10).

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Pin the API credential surface with header-level assertions per environment | R1, R2, R6, R7, R9, R10 | `framework.yaml`, `security.yaml`, `LoginAuthenticator.php`, `AuthController.php`, existing remember-me cookie tests | `tests/Functional/SessionCookieTest.php` + `tests/Integration/Identity/SessionCookiePolicyTest.php`, plus the config correction they expose | `tests/Functional/SessionCookieTest.php`, `tests/Integration/Identity/SessionCookiePolicyTest.php`, `config/packages/framework.yaml`, `config/packages/security.yaml` | none | raw login `Set-Cookie` contains `HttpOnly`; prod kernel yields `Secure` + `SameSite=None`, test env `Lax` without `Secure`; logout expires both cookies; login JSON has no token key |
| WP2 | Remove the localStorage auth mirror and make `/me` authoritative, without losing guest state | R3, R4, R5, R8 | `src/lib/store.tsx`, `src/lib/api/client.ts`, `src/lib/api/storefront.ts`, `src/lib/api/types.ts`, `src/routes/compte.tsx`, `src/routes/admin.tsx` | Store change: sanitised read, no user in persisted payload, 401 clears the user, guest cart/wishlist preserved | `Zidane_front_web/src/lib/store.tsx`, `Zidane_front_web/src/lib/api/storefront.ts`, `Zidane_front_web/src/lib/api/types.ts` | none | `npm run typecheck` + `npm run lint` pass; a stale `user` in `ziden-store-v1` no longer signs anyone in; guest cart/lang still restore after reload |
| WP3 | Record the decision and the cross-site cookie consequences | R11 | WP1 result, `docs/adr/ADR-0011-remember-me-credential.md` | `docs/adr/ADR-0013-session-cookie-credential.md` with rejected alternatives and open CSRF/GET-logout notes | `docs/adr/ADR-0013-session-cookie-credential.md` | WP1 | ADR states decision, rejected localStorage option, per-env flags as shipped, and the GET-logout/CSRF risks |

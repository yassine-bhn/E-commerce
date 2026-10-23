# Spec: Remember Me (as stated) — ambiguity and edge-case reading
Version: 1

## Goal
A "Remember me" checkbox on the storefront login form produces a persistent authentication state that genuinely survives a browser restart, without silently widening the current cookie/CORS security posture or breaking the JSON login contract.

## Corrected prompt
Implement a "Remember Me" feature: when the user checks "Remember me" on the login form, the user stays authenticated after closing and reopening the browser.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The login form exposes a labelled checkbox whose unchecked state is the default. | functional | "checks Remember me on the login form" | must |
| R2 | The checkbox state is transmitted to the backend with the credentials in the same request. | functional | "when the user checks Remember me" | must |
| R3 | A successful flagged login sets a persistent credential with a `Max-Age`/`Expires` in the future; an unflagged login sets none. | functional | "they stay authenticated after closing and reopening" | must |
| R4 | A request carrying only the persistent credential (no session cookie) is authenticated and serves `GET /api/v1/auth/me` with 200 and the customer identity. | functional | "stay authenticated after closing and reopening the browse[r]" | must |
| R5 | A request carrying neither the session cookie nor the persistent credential stays 401 on protected endpoints. | functional | conditional reading of "when the user checks" | must |
| R6 | A failed login attempt with the box ticked sets no persistent credential. | functional | "when the user checks Remember me" presumes successful auth | must |
| R7 | A tampered, truncated or expired persistent credential does not authenticate and does not produce a 5xx; JSON callers receive 401 JSON, never an HTML redirect. | constraint | "stay authenticated" must not degrade into any-session-accepted | must |
| R8 | Logout deletes the persistent credential in the same response and a following cold start is unauthenticated. | functional | implicit boundary of a user-controlled "remember" | must |
| R9 | The persistent credential is HttpOnly and not readable from JavaScript; nothing about it is mirrored into `localStorage`. | constraint | not stated; standard bar for a persistent login secret | must |
| R10 | The persistent credential is not re-issued or rotated on every authenticated request. | quality | "stay authenticated" stability | should |
| R11 | Enabling persistence does not weaken the current cookie attributes (HttpOnly, SameSite policy, Secure in production) and does not add a wildcard/reflective CORS origin. | constraint | not stated; the feature must not regress security | must |
| R12 | The remember-me lifetime is bounded by configuration and is far shorter than "forever" (a value in days/weeks, documented). | quality | "closing and reopening" says nothing about duration | should |
| R13 | Google OAuth logins and post-registration auto-login are unaffected; they keep session-only behavior. | constraint | "on the login form" scopes the feature | must |
| R14 | The existing unflagged login contract (status, body, cookies other than the new one) is unchanged. | constraint | no contract change requested | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "browse" is a typo for "browser"; the requirement is about browser restarts, not a "browse" page. | low |
| 2 | "Stay authenticated" means the storefront treats the visitor as the same customer on the next visit — not that a specific tab or in-memory state survives. | med |
| 3 | The storefront login form is `Zidane_front_web/src/routes/connexion.tsx`; the session backend is the Symfony `main` firewall. | low |
| 4 | Persistence is implemented as a separate remember-me cookie, keeping today's browser-session session cookie unchanged. A long-lived *session* cookie is a different design and is not assumed. | high |
| 5 | The production deployment serves the storefront and API from the same registrable site, so the current `SameSite=lax` / `SameSite=none+Secure` split keeps working. | high |
| 6 | A Doctrine migration may be added for server-side token storage; the test database runs migrations. | med |
| 7 | Tests can simulate a browser restart by discarding the session cookie while keeping the persistent cookie. | low |
| 8 | The storefront's existing cold-start restore (`getCurrentUser()` in `src/lib/store.tsx`) is the integration point; no new restore path is needed. | med |
| 9 | Customer accounts blocked via `customer_profile.status` are out of scope: login itself does not check status today, so remember-me is not required to be stricter. | med |
| 10 | The label is French on the storefront ("Se souvenir de moi"). | low |

## Open questions
- [BLOCKING] Which mechanism: Symfony's `remember_me` firewall with `persistent` token storage (requires a `rememberme_token` table and a new migration) or `signature` (cookie-only HMAC, no DB, no server-side revocation)? Default: `signature` — no schema change and it invalidates when the password changes; revocation is covered by logout only.
- [BLOCKING] In production, are storefront and API on the *same registrable site*? If not, the remember-me cookie needs `SameSite=None; Secure`, which exposes the session-cookie API to cross-site requests; CSRF protection on state-changing API routes (`POST /api/v1/storefront/cart`, wishlist, orders) would then become a prerequisite. Default: same site (no CORS/CSRF change) — verify against `STOREFRONT_APP_URL` / `STOREFRONT_CORS_ORIGIN` before implementing.
- [minor] Does a remembered customer who changes their password keep the remembered session? Default: no — changing the password invalidates the remembered credential.
- [minor] Is a "guest-to-signed-in flash" on cold start acceptable while `/auth/me` resolves? Default: acceptable, no requirement to remove it (the storefront already exposes `authReady`).
- [minor] Should the checkbox be offered on the register tab (which auto-logs-in)? Default: no.
- [minor] Should the remember-me cookie name stay Symfony's default `REMEMBERME`? Default: yes, unless it collides with another app on the same host.

## Out of scope
- Admin/back-office login, device/session management UI, remote revocation.
- Long-lived session cookie as an alternative implementation.
- Password reset, registration flow, Google OAuth changes.
- Reworking the storefront cookie/localStorage strategy or auth guards.
- Rate limiting, account lockout, and status enforcement (pre-existing gaps).

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Decide and document the mechanism + topology facts before coding | R3, R11, R12 | `config/packages/security.yaml`, `framework.yaml`, `.env`, `CorsSubscriber.php` | Short ADR note in the WP report + chosen config values | `.agents/audit/**` (report only, no production edit) | none | Mechanism, cookie attributes, lifetime and prod SameSite stance are stated with evidence from the files |
| WP2 | Backend: flagged login persists, cookie-only request authenticates, logout clears | R2, R3, R4, R6, R7, R8, R10, R14 | WP1 decision, `LoginAuthenticator`, `security.yaml`, `LogoutSubscriber` | Firewall config + authenticator badge + logout handling | `src/Identity/Infrastructure/Security/**`, `config/packages/**`, `migrations/**` | WP1 | Functional tests cover R3, R4, R6, R7, R8, R14 |
| WP3 | Backend: cookie attributes and CORS posture unchanged/verified | R9, R11 | `framework.yaml`, `CorsSubscriber.php`, `.env*` | Verified attributes and no CORS widening | `config/packages/framework.yaml`, `src/Shared/Infrastructure/Http/CorsSubscriber.php`, `.env*` | WP2 | Attribute assertions (HttpOnly, SameSite, Secure in prod) hold in tests/config review |
| WP4 | Storefront: checkbox + request field + cold-start restore | R1, R2, R5, R9, R13 | `connexion.tsx`, `lib/api/storefront.ts`, `src/lib/store.tsx` | UI control + API field + restore behavior | `Zidane_front_web/src/routes/connexion.tsx`, `Zidane_front_web/src/lib/api/**`, `Zidane_front_web/src/lib/store.tsx` | WP2 | Manual/unit evidence: flag sent only when ticked; cold start resolves remembered user; no secret in `localStorage` |
| WP5 | Edge-case tests: restart simulation, tamper, expired, failed login, logout | R3, R4, R5, R6, R7, R8, R12, R14 | WP2, WP4 | Test suite additions | `tests/Functional/**`, `tests/Unit/Identity/**` | WP2 | Each listed requirement has a failing-before/passing-after test |

## Decisions (synthesizer only)

## Changelog (synthesizer and orchestrator only)

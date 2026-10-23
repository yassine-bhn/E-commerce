# Spec: Persistent "Remember me" login for the Zidane storefront
Version: 1

## Goal
A returning customer who ticked "Remember me" opens the storefront days later and is already signed in — same name in the header, cart/wishlist/orders reachable — without typing a password and without the customer having to understand cookies or sessions.

## Corrected prompt
Implement a Remember Me feature: when the user checks "Remember me" on the login form, the user stays authenticated after closing and reopening the browser.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The storefront login form shows a "Remember me" checkbox, unchecked by default, next to the sign-in button. | functional | "checks Remember me on the login form" | must |
| R2 | Ticking the checkbox and submitting sends an explicit remember-me signal on the login request. | functional | "when the user checks Remember me" | must |
| R3 | The backend persists a long-lived remember-me credential (survives browser process exit) when the signal is present and authentication succeeds. | functional | "stay authenticated after closing and reopening" | must |
| R4 | With only the remember-me credential (no session cookie), a request to the authenticated session endpoint returns the authenticated customer and starts a fresh session. | functional | "stay authenticated after closing and reopening the browse[r]" | must |
| R5 | On storefront cold start, the app resolves auth state from the backend before rendering auth-dependent UI, so a remembered customer is shown as signed in on the first paint of protected pages. | functional | "they stay authenticated" | must |
| R6 | A login without the signal issues no long-lived credential and the visitor is a guest after the browser restarts. | functional | "when the user checks" (conditional) | must |
| R7 | Logging out deletes the remember-me credential, so the next cold start does not silently re-authenticate. | functional | implied by "stay authenticated" being user-controlled | must |
| R8 | The remember-me credential is unusable if tampered with, expired, or issued for a customer whose password has since changed. | quality | "stay authenticated" implies trustworthy authentication | must |
| R9 | The browser must be allowed to store and resend the credential under the storefront's cross-origin deployment (CORS with credentials on `http://localhost:3000` → `http://localhost:8081`, and separate hosts in production). | constraint | implied by the two-app deployment | must |
| R10 | The login response body and status for a remember-me login are unchanged from a normal login, so the existing storefront client keeps working. | constraint | no contract change requested | should |
| R11 | The remember-me lifetime is configurable rather than hardcoded, and defaults to a value longer than a single browsing session. | quality | implied by "closing and reopening" | should |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The feature targets the customer-facing storefront login (`Zidane_front_web/src/routes/connexion.tsx`) and the Symfony `main` firewall; no admin UI work. | low |
| 2 | The user's intent is convenience for returning customers, not "keep me signed in forever": an explicit logout must still end it. | low |
| 3 | The existing session cookie stays a session cookie; persistence comes from a separate remember-me cookie, so unticked logins keep today's behavior exactly. | med |
| 4 | The storefront already restores state at boot via `GET /api/v1/auth/me` + `GET /api/v1/storefront/customer` (`src/lib/store.tsx`); the work is to make that path succeed with the remember-me cookie. | med |
| 5 | The backend already answers with `credentials: "include"` and `CorsSubscriber` allows the storefront origin with credentials; the remember-me cookie must therefore be compatible with that CORS profile. | high |
| 6 | The UI label follows storefront French copy: "Se souvenir de moi". | low |
| 7 | Google OAuth and registration keep their current behavior (no remember-me signal). | low |
| 8 | Remember-me state is not stored in localStorage; the credential lives in an HttpOnly cookie managed by the backend. | low |
| 9 | Symfony's built-in remember-me machinery is acceptable; a bespoke token scheme is not required. | low |
| 10 | No email notification on remember-me login (no "new device" alert). | low |

## Open questions
- [minor] Should checking the box also be offered on the register tab, which auto-logs-in after creation? Default: no.
- [BLOCKING] If the storefront is served from a different site than the API in production, the remember-me cookie needs `SameSite=None; Secure`. Default: reuse the production session cookie policy (`cookie_samesite: none`, `cookie_secure: true`) for the remember-me cookie.
- [minor] Should the checkbox stay ticked after a successful login so a later logout/login is one click? Default: no, reset to unchecked.

## Out of scope
- "Remember me" on the admin/back-office login.
- Device management page ("signed-in devices", revoke individual tokens).
- Multi-device session limits.
- Silent token refresh or JWT migration.
- Changing password, registration, or password-reset semantics.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Backend credential lifecycle: issue on flagged login, authenticate from cookie, delete on logout | R3, R4, R6, R7, R8, R10, R11 | `config/packages/security.yaml`, `LoginAuthenticator`, `LogoutSubscriber`, migrations | Firewall `remember_me`, persistent token storage, logout handlers | `config/packages/security.yaml`, `config/packages/*.yaml`, `migrations/**`, `src/Identity/Infrastructure/Security/**` | none | Functional test: flagged login sets a non-session cookie; cookie-only request authenticates; logout clears it |
| WP2 | Cross-origin cookie compatibility and configuration surface | R9, R11 | `src/Shared/Infrastructure/Http/CorsSubscriber.php`, `.env`, `framework.yaml` | Verified CORS/cookie settings + documented env knobs | `src/Shared/Infrastructure/Http/CorsSubscriber.php`, `.env*`, `config/packages/framework.yaml` | WP1 | Browser-style preflight + credentialed request keep and return the remember-me cookie; lifetime configurable |
| WP3 | Login form control and API wiring | R1, R2, R10 | `connexion.tsx`, `lib/api/storefront.ts`, `lib/api/types.ts` | Checkbox + `remember_me` field | `Zidane_front_web/src/routes/**`, `Zidane_front_web/src/lib/api/**` | WP1 | Form posts the flag only when ticked; response handling unchanged |
| WP4 | Cold-start auth resolution on the storefront | R5 | `src/lib/store.tsx`, auth-dependent layout/guard code | Boot restore proven/robust | `Zidane_front_web/src/lib/store.tsx`, auth guard components | WP1, WP2 | Cold start with only the remember-me cookie renders the customer as signed in without a redirect flash |
| WP5 | Tests: backend functional + storefront unit | R3, R4, R6, R7, R8, R10 | WP1–WP4 | Automated tests | `tests/Functional/**`, `tests/Unit/Identity/**`, `Zidane_front_web` test files | WP1, WP3, WP4 | Suites green; each requirement has at least one asserting test |

## Decisions (synthesizer only)

## Changelog (synthesizer and orchestrator only)

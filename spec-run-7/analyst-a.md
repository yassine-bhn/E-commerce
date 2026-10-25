# Spec: Remember Me — persistent authentication across browser restarts
Version: 1

## Goal
A customer who ticks a "Remember me" control on the login form is still logged in on the storefront after closing the browser and opening it again, while a customer who leaves the control unticked is not.

## Corrected prompt
Implement a Remember Me feature: when the user checks "Remember me" on the login form, the user stays authenticated after closing and reopening the browser.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The login form contains a "Remember me" control that the user can check before submitting the form. | functional | "when the user checks Remember me on the login form" | must |
| R2 | The control is an interactive tickable element (a checkbox with an accessible label), not a free-text field. | functional | "checks Remember me" | must |
| R3 | The state of the control is sent with the login request, so the backend can tell a remember-me login from a normal one. | functional | "when the user checks Remember me on the login form" | must |
| R4 | When the control is checked and the credentials are valid, the backend issues a persistent remember-me credential in addition to the session. | functional | "they stay authenticated after closing and reopening the browser" | must |
| R5 | The persistent credential survives the end of the browser process, i.e. it is not a session-only cookie. | constraint | "after closing and reopening the browse[r]" | must |
| R6 | After the browser is closed and reopened, an unauthenticated visit to the storefront restores the authenticated user without re-entering credentials. | functional | "they stay authenticated after closing and reopening the browse[r]" | must |
| R7 | When the control is unchecked and the credentials are valid, no persistent remember-me credential is issued for that login. | functional | "when the user checks" (conditional) | must |
| R8 | When the control is unchecked, closing and reopening the browser leaves the visitor unauthenticated. | functional | "when the user checks" (conditional) | must |
| R9 | The remember-me login returns the same successful login response body as a normal login. | constraint | "on the login form" (no change to the login contract stated) | should |
| R10 | Attacking the remember-me credential without the server-side secret does not authenticate the visitor. | quality | "stay authenticated" (authentication must remain sound) | must |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "The login form" is the storefront login form at `Zidane_front_web/src/routes/connexion.tsx`, not an admin or API-only form. | low |
| 2 | "The browser" is a normal desktop browser whose restart clears session cookies; remember-me must therefore rely on a long-lived cookie. | low |
| 3 | The login endpoint stays `POST /api/v1/auth/login` with a JSON body; the remember flag is an added optional body field. | med |
| 4 | "Stay authenticated" means the storefront header/profile shows the signed-in user and protected routes keep working, not merely that a cookie exists. | low |
| 5 | Session-restore on storefront boot already exists (`src/lib/store.tsx` calls `getCurrentUser`); it will pick up the remembered session without new UI work. | med |
| 6 | No new UI copy is required beyond the control's label; existing French storefront copy style is kept. | low |
| 7 | Remember-me duration is a configuration value, not a stated requirement. | low |

## Open questions
- [minor] Which label text should the control use on the French storefront? Default: "Se souvenir de moi".
- [minor] How long should the persistent credential last? Default: Symfony's default (1 year), configurable.
- [minor] Should Google OAuth logins also support remember-me? Default: no, out of scope.

## Out of scope
- Password reset, registration and Google OAuth flows.
- Remember-me for the admin/back-office session.
- Admin-visible "revoke remembered devices" UI.
- Changing the login page layout beyond adding the control.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Backend accepts a remember-me flag on login and issues a persistent credential | R3, R4, R5, R7, R9, R10 | `config/packages/security.yaml`, `src/Identity/Infrastructure/Security/LoginAuthenticator.php`, Doctrine migrations | Firewall remember-me config + authenticator badge + token table migration | `config/packages/security.yaml`, `config/packages/security*.yaml`, `migrations/`, `src/Identity/Infrastructure/Security/` | none | A functional test logs in with the flag, receives a persistent cookie, and a subsequent request with only that cookie is authenticated |
| WP2 | Storefront login form exposes the control and forwards its state | R1, R2, R3 | `Zidane_front_web/src/routes/connexion.tsx`, `Zidane_front_web/src/lib/api/storefront.ts`, `src/lib/api/types.ts` | Checkbox + request field | `Zidane_front_web/src/routes/connexion.tsx`, `Zidane_front_web/src/lib/api/storefront.ts`, `Zidane_front_web/src/lib/api/types.ts` | WP1 | Submitting the form with the control ticked sends the flag; unticked omits/false it |
| WP3 | Storefront restores the remembered session on cold start | R6, R8 | `Zidane_front_web/src/lib/store.tsx` | Verified/extended boot-time restore | `Zidane_front_web/src/lib/store.tsx` | WP1 | A cold start with only the remember-me cookie shows the signed-in user; without it the visitor stays a guest |
| WP4 | Regression tests encode the behavior | R4, R6, R7, R8, R10 | WP1–WP3 | Unit/functional tests | `tests/Unit/Identity/`, `tests/Functional/` | WP1, WP2, WP3 | Tests fail before the feature and pass after it |

## Decisions (synthesizer only)

## Changelog (synthesizer and orchestrator only)

# Task F-http-only-session-cookie
Type: feature
Started: 2026-10-24T09:05:00+01:00
Requirement: Secure the session token by carrying it in an HttpOnly cookie instead of storing it in localStorage.

## Spec traceability
- Spec: spec-run-11/spec.md (v2 — v1 synthesised from analyst-a/b/c; v2: BLOCKING question answered by the user, storefront and API both in scope)
- Prompt archive: spec-run-11/prompt.md
- Stage 4 summary: coverage R1-R13 pass (evidence in `## Coverage`); Stage 3 touched `tests/Functional/SessionCookieTest.php`, `tests/Integration/Identity/SessionCookiePolicyTest.php`, `docs/adr/ADR-0013-session-cookie-credential.md`, `Zidane_front_web/src/lib/store.tsx`; no PHP production file needed changing (the cookie policy was already correct and is now pinned)
- Requirements: R1, R2, R3, R4, R5, R6, R7, R8, R9, R10, R11, R12, R13

## Gates
GATE: SPEC ok — 2026-10-24T09:20:00+01:00 — orchestrator
GATE: PLAN ok — 2026-10-24T09:22:00+01:00 — orchestrator (behaviours derived per R: login header + body, /me replay, logout revocation, register body, per-env policy, storefront sanitised read, 401 clearing)
GATE: RED (negative control) — 2026-10-24T09:26:00+01:00 — implementer (`cookie_httponly: false` in `config/packages/framework.yaml` made `SessionCookieTest::testLoginAnswersWithAnHttpOnlySessionCookieAndNoToken` and `SessionCookiePolicyTest::testTheSessionCookiePolicyIsPinnedPerEnvironment` fail; config reverted, both green again)
GATE: GREEN ok — 2026-10-24T09:29:00+01:00 — implementer (API tests + storefront store change)
GATE: SPEC-TESTS ok — 2026-10-24T09:30:00+01:00 — integration-test-agent (per-environment policy proven from a booted prod kernel)
GATE: REVIEW fail — 2026-10-24T09:40:00+01:00 — security-reviewer (2 high/med: ADR claimed a CORS/content-type CSRF mitigation that does not exist, `SameSite=None` justified by an unverified topology; 2 med: vacuous `PHPSESSID` assertion on register, logout did not prove server-side revocation; 6 low)
GATE: FIXES ok — 2026-10-24T09:52:00+01:00 — implementer (ADR-0013 CSRF/topology statements corrected; register test checks both session cookie names; logout replays the pre-logout id and asserts remember-me clearing; `dev` kernel added to the policy test; storefront drops `orders`/`addresses` on restore and in the payload, adds an auth claim ref so a late 401 cannot clear a fresh login, sanitises array payloads)
GATE: SPEC v3 — 2026-10-24T09:54:00+01:00 — orchestrator (R5 tightened to no account data in storage; R9 evidence downgraded to inspection)
GATE: DONE — 2026-10-24T09:58:00+01:00 — orchestrator

## Behaviors → tests
| Requirement | Test |
|---|---|
| R1 | `SessionCookieTest::testLoginAnswersWithAnHttpOnlySessionCookieAndNoToken`, `::testRegisterAnswersWithIdentityOnlyAndOpensNoSession`, `::testTheSessionCookieAloneAuthenticatesTheNextRequest` |
| R2, R10 | `SessionCookieTest::testLoginAnswersWithAnHttpOnlySessionCookieAndNoToken` (raw `Set-Cookie`), `SessionCookiePolicyTest::testTheSessionCookiePolicyIsPinnedPerEnvironment` (prod + test kernels) |
| R3, R4 | `SessionCookiePolicyTest::testTheSessionCookiePolicyIsPinnedPerEnvironment` (`Secure`/`SameSite` per env) |
| R8 | `SessionCookieTest::testLogoutExpiresTheSessionCookieAndRevokesTheSession` |
| R9 | `SessionCookieTest::testTheSessionCookieAloneAuthenticatesTheNextRequest` (no `Authorization` header) |
| R5, R6, R7, R11, R13 | `Zidane_front_web/src/lib/store.tsx` (sanitised read of `user`/`orders`/`addresses`, 401 clears user) verified by `npm run typecheck` + `npm run lint`; no storefront test runner exists |
| R9 | inspection only: `client.ts` holds the single `fetch` in the storefront and sets `credentials: "include"` (no automated pin; recorded as such in the ADR) |
| R12 | `docs/adr/ADR-0013-session-cookie-credential.md` |

## RED record
RED: tests/Functional/SessionCookieTest.php — "The session cookie must be HttpOnly. Failed asserting that false is true." — cmd: docker compose exec app php bin/phpunit tests/Functional/SessionCookieTest.php → FAIL (1 failure) after temporarily setting `cookie_httponly: false`
RED: tests/Integration/Identity/SessionCookiePolicyTest.php — "production — the session cookie must be HttpOnly. Failed asserting that false is true." — same mutation → FAIL (1 failure)
ATTEMPTS: 0 (no production PHP change was required; the mutation was reverted)

## Human approvals
APPROVED: spec scope (this storefront, API + storefront) — user — 2026-10-24T09:18:00+01:00

## Coverage (Stage 4)
| Requirement | Status | Evidence |
|---|---|---|
| R1 no credential in JSON | pass | login keys exactly `email,id,roles`; register keys `email,id,roles,status`; `/me` key scan |
| R2 HttpOnly everywhere | pass | raw `Set-Cookie` assertion + prod kernel cookie assertion |
| R3 Secure/SameSite per env | pass | prod `Secure` + `SameSite=None`; test `Lax` without `Secure` |
| R4 dev works | pass | `SessionCookieTest` runs the full login/me/logout flow over the test env policy |
| R5 no credential in Web Storage | pass | only `sessionStorage` for return path + promo code; payload writes `user`/`orders`/`addresses` empty; restore strips `user`/`orders`/`addresses` |
| R6 legacy `user` dropped | pass | `restore` deletes `stored.user` before merging |
| R7 `/me` authoritative, 401 clears | pass | `restore` clears `user` on 401 |
| R8 logout revokes | pass | session cookie expired + remember-me cleared + pre-logout session id replayed and refused (401) |
| R9 credentials on every call | pass (inspection) | single `fetch` in `client.ts` with `credentials: "include"`; not pinned by an automated test |
| R10 regression tests | pass | functional + integration suites green, negative control proven |
| R11 no regression / no mass re-login | pass | guest cart/wishlist/lang restore path untouched; cookie name/domain/secret unchanged |
| R12 ADR | pass | `docs/adr/ADR-0013-session-cookie-credential.md` |
| R13 toolchain-only frontend proof | pass | `npm run typecheck`, `npm run lint` (0 errors) |

## Definition of Done (AGENTS.md)
| Check | Status | Evidence |
|-------|--------|----------|
| Unit suite | pass | 279 tests, 674 assertions |
| Integration suite | pass | 73 tests, 561 assertions |
| Functional suite | pass | 269 tests, 1993 assertions |
| Architecture suite | pass | 14 tests, 96 assertions |
| Projection / Contract suites | n/a | not touched by this change (no read model, no controller contract change) |
| phpstan | pass | `[OK] No errors` |
| php-cs-fixer | pass | 0 of 469 files fixable |
| deptrac | pass | 0 violations, 0 errors |
| infection MSI | n/a | touched paths are `tests/`, `docs/`, `config/` and a Next.js file — no touched Domain/Application PHP code |
| ADR | pass | `docs/adr/ADR-0013-session-cookie-credential.md` |
| Reviews | pass | security-reviewer at REVIEW; every finding (F1-F12) answered by a fix or an explicit follow-up in the ADR |
| State + audit complete | pass | this file + `.agents/audit/F-http-only-session-cookie.log` |
| Requirements traced to tests | pass | `## Coverage` above, R1-R13 |

## Review findings disposition (security-reviewer)
| Finding | Sev | Disposition |
|---|---|---|
| F1 ADR claimed a CORS/content-type CSRF mitigation that does not exist | high | fixed: ADR now states there is no request-side CSRF control and names the Origin allow-list / token as the follow-up |
| F2 `SameSite=None` justified by an unverified topology | med | fixed: ADR states the repo only proves two hostnames, `.env.prod.example` is same registrable domain, and flipping the live policy needs the real hostnames |
| F3 register test asserted `PHPSESSID` only (vacuous) | med | fixed: both `PHPSESSID` and `MOCKSESSID` checked on response and jar |
| F4 logout did not prove server-side revocation, remember-me filtered out | med | fixed: pre-logout session id replayed → 401; remember-me clearing asserted |
| F5 tautological `Authorization` assertion; R9 unpinned | low | fixed: assertion removed, R9 evidence downgraded to inspection in spec, ADR and this file |
| F6 `dev` env never booted | low | fixed: third kernel booted with `environment: dev` |
| F7 docblock implied session-fixation coverage | low | fixed: claim removed from the docblock; fixation re-engineering stays out of scope |
| F8 `orders`/`addresses` PII restored and re-persisted | low | fixed: dropped on restore, written empty, cleared on logout (spec R5 v3) |
| F9 late 401 could clear a fresh login | low | fixed: `authClaimRef` generation guard |
| F10 fire-and-forget logout | low | not fixed: pre-existing UX contract (instant local sign-out); recorded as a follow-up in the ADR trade-offs |
| F11 unvalidated storage spread into state | low | fixed: `Array.isArray` guards on `cart`/`wishlist` |
| F12 prod kernel handled inside the test suite | low | fixed: docblock now requires the request path to stay side-effect free |

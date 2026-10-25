# Task F-csrf-origin-guard
Type: feature
Started: 2026-10-24T10:05:00+01:00
Requirement: Implement the ADR-0013 CSRF follow-up — an allow-list on Origin/Referer/Sec-Fetch-Site for state-changing `/api/v1` requests, mounted as a request listener in `src/Shared/Infrastructure/Http/`, answering 403 to cross-site state-changing requests while leaving the `CorsSubscriber` OPTIONS preflight intact; prove it with functional tests (foreign origin → 403, configured storefront origin → success) and update ADR-0013's trade-off section.

## Source
- ADR: docs/adr/ADR-0013-session-cookie-credential.md (Accepted trade-offs: "no CSRF token and no Origin/Referer check")
- Follow-up named there: "Origin allow-list or double-submit token"
- Origin allow-list reused from `STOREFRONT_CORS_ORIGIN` (`config/packages/framework.yaml` has no CORS block; `CorsSubscriber` reads the env var)

## Spec traceability
- Spec: spec-run-13/spec.md (version 2)
- Prompt archive: spec-run-13/prompt.md
- Stage 4 summary: coverage complete — WP1 (FIX-2 production + unit tests) and WP2 (FIX-3 ADR) delivered all four FIX-round clusters' owned files; one spec defect found by WP1 and fixed in spec v2 (R10 green count 24/98, not /86); ADR reconciled against delivered code (priority 14, decode-once, shared Origin/OriginAllowList, write-trust-anchor wording)
- Requirements: R1–R23 (R1–R11, R23 → WP1 production; R12–R15 → WP2 ADR; R16–R17 → review re-run; R18–R22 → closure)

## Gates
GATE: DISPATCH ok — 2026-10-24T10:05:00+01:00 — orchestrator
GATE: PLAN ok — 2026-10-24T10:08:00+01:00 — test-planner
GATE: PATTERN n/a — 2026-10-24T10:08:00+01:00 — orchestrator (no new bus, aggregate, port or context boundary; the guard reuses the existing kernel.request listener slot next to `CorsSubscriber` and the existing `STOREFRONT_CORS_ORIGIN` allow-list)
GATE: RED ok — 2026-10-24T10:12:00+01:00 — e2e-contract-test-agent (C1: foreign Origin on a valid login → expected 403, actual 200)
GATE: GREEN ok — 2026-10-24T10:20:00+01:00 — implementer (C1: `CrossSiteRequestGuardSubscriber`, kernel.request priority 16, refuses POST/PUT/PATCH/DELETE under `/api/v1` carrying any `Origin`)
GATE: RED ok — 2026-10-24T10:25:00+01:00 — e2e-contract-test-agent (C2: configured storefront Origin and own Origin each expected 200, actual 403)
GATE: GREEN ok — 2026-10-24T10:31:00+01:00 — implementer (C2: allow-list from `STOREFRONT_CORS_ORIGIN` + own `getSchemeAndHttpHost()` accepted; Functional suite 273 tests / 1974 assertions green excluding the foreign probe file)
GATE: RED ok — 2026-10-24T10:37:00+01:00 — e2e-contract-test-agent (C3: foreign Referer and `Sec-Fetch-Site: cross-site` each expected 403, actual 200)
GATE: GREEN ok — 2026-10-24T10:44:00+01:00 — implementer (C3: signal chain Origin → Referer origin → Sec-Fetch-Site; no signal stays allowed; 5 tests green)
GATE: SPEC-TESTS ok — 2026-10-24T10:52:00+01:00 — e2e-contract-test-agent (C4 pins: 7 methods incl. a 3-case data provider; 14 tests / 48 assertions green, order flow 403 foreign vs 201 configured, no leftover rows)
GATE: SPEC-TESTS (negative control) ok — 2026-10-24T11:02:00+01:00 — implementer (M1 own-origin branch removed → own-origin pin red; M2 GET added to the method set → safe-method pin red; M3 signal made mandatory → no-signal pin red; M4 allow-list removed → storefront + order pins red; guard restored byte-identical, sha256 5177422685b976170e68bc478e462b122f9ab272205f1a70ad40ea4b8e99032e)
GATE: REFACTOR ok — 2026-10-24T11:10:00+01:00 — refactorer (guarded: default-port lookup table; test helpers extracted; no behaviour change, 14 tests / 48 assertions)
GATE: SPEC-TESTS ok (C5) — 2026-10-24T11:16:00+01:00 — implementer (ADR-0013 cross-site posture, trade-off, negative, follow-up and proof sections rewritten to the delivered control; residual gaps stated)
GATE: REVIEW fail — 2026-10-24T11:20:00+01:00 — review fan-out (code-reviewer approve-with-fixes; security-reviewer DO-NOT-SHIP: percent-encoded path bypasses the guard; architecture-reviewer clean but flags the allow-list contract; performance-reviewer approve (4 low); test-reviewer: half-pinned fallbacks; solid-guardian PASS with OCP-001)
GATE: SPEC ok — 2026-10-24T23:26:00+01:00 — orchestrator (continuation via /spec spec-run-13: three analysts → synthesizer → spec.md v2, 23 requirements, 4 work packages, 0 blocking questions)
GATE: FIXES ok — 2026-10-24T23:26:00+01:00 — implementer via spec WP1/WP2 (WP1: guard decodes path once with rawurldecode() before the /api/v1 check; Origin + OriginAllowList in src/Shared/Domain/Http/ shared by guard and CorsSubscriber; priority 14 verified collision-free (LocaleAwareListener 15 above, firewall 8 below); allow-list parsed once at construction; unit tests 21 tests / 58 assertions. WP2: ADR-0013 states S2 raw-path caveat, S4 write trust anchor, S5 Host/X-Forwarded-Host trusted-proxies derivation, priority sentence reads 14. Evidence: CsrfOriginGuardTest 24/98 green unmodified incl. both encoded paths → 403; CorsTest green; Unit 300/727; phpstan 0, cs-fixer 0, deptrac 0. [Correction 2026-10-24T23:55 — the unit-test count first recorded here as 21/53 is 21/58; verified by run and recount, test-reviewer round-2 finding 1, AGT-002])
GATE: REVIEW ok — 2026-10-24T23:59:00+01:00 — review fan-out round 2, all six verdicts clean (security APPROVE: S1 closed, verified live against the router source and a curl battery incl. double-encoded, traversal and case spellings — encoded variants all 403 or unrouted; code APPROVE: C1/C2/C3/OCP1/P1 closed; architecture APPROVE: single interpreter, deptrac clean; performance APPROVE: allow-list parsed once per worker; test APPROVE: T1/T2/S3/S6/C1-unit closed, 24/98 and 21/58 green; solid-guardian PASS: OCP-001 closed, no findings. Zero blocking findings; one LOW record-accuracy item corrected above; three NIT/INFO observations recorded, none actionable)
GATE: DONE — 2026-10-24T23:59:00+01:00 — orchestrator (DoD evidence: PHPUnit all suites 741 tests / 4213 assertions, sole failure the documented foreign CatalogApprovalDesignTest (Architecture suite, pre-dates this diff); phpstan 0 errors over 468 files; php-cs-fixer 0 of 493 (the earlier foreign-migration flag no longer reproduces); deptrac 0 violations / 0 errors / 0 warnings; parallel reviews approved with every finding cited, fixed or ADR'd; ADR-0013 updated (no new pattern — PATTERN n/a); infection n/a per R21 — image has no coverage driver; state at DONE, audit log appended)

## RED record
RED: tests/Functional/CsrfOriginGuardTest.php — "Failed asserting that 200 is identical to 403." (tests/Functional/CsrfOriginGuardTest.php:73) — cmd: docker compose exec -T app php bin/phpunit tests/Functional/CsrfOriginGuardTest.php → FAIL (Tests: 1, Assertions: 1, Failures: 1)
ATTEMPTS: 1
FIX-1 RED: tests/Functional/CsrfOriginGuardTest.php — "Failed asserting that 500 is identical to 403." (tests/Functional/CsrfOriginGuardTest.php:131, both data sets) — cmd: docker compose exec -T app php bin/phpunit tests/Functional/CsrfOriginGuardTest.php → FAIL (Tests: 24, Assertions: 86, Failures: 2)
FIX-1 pins green at that point: `Origin: null` 403, unparseable Referer (`not-a-url`, `/relative/page`) 403, `CROSS-SITE` 403, storefront Referer 200 + session, PUT/PATCH/DELETE 403, own-origin session cookie, `processed_commands` teardown; HEAD pinned to 200 (Symfony routes HEAD onto the GET route).
RED: tests/Functional/CsrfOriginGuardTest.php — "Failed asserting that 403 is identical to 200." (tests/Functional/CsrfOriginGuardTest.php:110 and :136) — cmd: docker compose exec -T app php bin/phpunit tests/Functional/CsrfOriginGuardTest.php → FAIL (Tests: 3, Assertions: 11, Failures: 2)
RED: tests/Functional/CsrfOriginGuardTest.php — "Failed asserting that 200 is identical to 403." (tests/Functional/CsrfOriginGuardTest.php:157 foreign Referer, :186 `Sec-Fetch-Site: cross-site`) — cmd: docker compose exec -T app php bin/phpunit tests/Functional/CsrfOriginGuardTest.php → FAIL (Tests: 5, Assertions: 14, Failures: 2)
NEGATIVE CONTROL (pins, not RED): mutating the guard turns them red — :160 own-origin, :290 safe-method, :238 no-signal, :134 storefront origin, :423 order 201; the OPTIONS pin is NOT detectable by mutation because `CorsSubscriber` answers preflight at priority 256 and `RequestEvent::setResponse()` stops propagation, so the guard never sees an OPTIONS request (recorded as a limitation, not a defect).

## Observations outside this task's scope
- `LoginAuthenticator::supports()` compares the raw path, so a percent-encoded `/api/v1/auth/login` is matched by the router but declined by the authenticator, and the stub `AuthController::login()` throws → 500. Pre-existing, unreachable cross-site once S1 is fixed, but a same-origin encoded login still answers 500. Recorded, not fixed: it belongs to the authenticator, not to the origin guard.
- 20 `csrf-*` rows written by the pre-fix runs remain in the `app_test` `processed_commands` table; the new teardown only removes keys it generates itself.
- `CorsSubscriber`'s own `onRequest`/`onResponse` compare the raw `getPathInfo()` against `/api/`, so an encoded preflight path skips the CORS answer headers. Fail-closed for browsers (no ACAO → the browser blocks the real request) and the guard refuses encoded state-changing requests independently. Recorded per spec-run-13 open-question 2 default; security-reviewer round 2 concurs out of scope. (recorded 2026-10-24, review round 2)

## Review findings (round 1)
| # | Sev | Owner | Finding | Disposition |
|---|---|---|---|---|
| S1 | blocker | security | guard keys on raw `getPathInfo()`, Symfony routes/`access_control` on `rawurldecode`d path → `POST /api%2Fv1/storefront/orders` reaches the firewall (401) and would run authenticated, guard skipped. Verified by the orchestrator against the running stack: `/api/v1/storefront/orders` → 403, `/api%2Fv1/storefront/orders` → 401, `/api/%76%31/storefront/orders` → 401 | to fix |
| S2 | high | security | ADR states the control without the raw-path caveat → overstated | to fix (with S1) |
| S3 | med | security | ADR proof claims own-origin answers "200 with a session"; the test asserts status only | to fix (test) |
| S4 | med | security | the allow-listed origin becomes a write trust anchor (its redirect/HTML hygiene now matters) | to fix (ADR) |
| S5 | med | security | "own origin" derives from `Host`/`X-Forwarded-Host`; trusted proxies decide | to fix (ADR) |
| S6 | low | security | `Origin: null` refused but unpinned | to fix (test) |
| C1 | med | code | `originOf()` branches, unparseable Referer, uppercase `Sec-Fetch-Site` have no RED and no test | to fix (test) |
| C2 | low | code | priority 16 is shared with `MalformedRememberMeCookieSubscriber` and Symfony's `LocaleListener` → ordering accidental | to fix (priority 14) |
| C3 | low | code | second interpreter of `STOREFRONT_CORS_ORIGIN` with a different comparator than `CorsSubscriber` | to fix (shared value object) |
| C4 | nit | code | if-branches in test cleanup helpers; GET+HEAD in one test | partially (HEAD strictness) |
| OCP1 | design | solid | trust policy inlined with no seam; a third listener would copy a third variant | to fix (shared value object) |
| P1 | low | perf | allow-list re-split/re-parsed per request; Referer parsed twice; `getSchemeAndHttpHost()` per call | to fix |
| P2 | low | perf | `processed_commands` row leaked per order test | to fix (teardown) |
| T1 | major | test | no allowed-Referer case → a `return true` for Referer survives all 14 tests | to fix (test) |
| T2 | med | test | only POST exercised of PUT/PATCH/DELETE; HEAD asserted `not 403` | to fix (test) |
| T3 | med | test | OPTIONS pin cannot fail (recorded, accepted) | accepted as documentation |
| T4 | med | test | no builders; hand-rolled SQL duplicated across the suite | accepted (repo-wide, `tests/Builders` empty) |

## FIXES plan (round 1)
Orchestrator verification of S1 against the running stack (2026-10-24, `curl -X POST -H 'Origin: http://evil.example'` on port 8081):

| Path | Status | Meaning |
|---|---|---|
| `/api/v1/storefront/orders` | 403 | guard refuses |
| `/api%2Fv1/storefront/orders` | 401 | firewall reached, guard skipped |
| `/api/%76%31/storefront/orders` | 401 | firewall reached, guard skipped |

Cause: the guard compares `Request::getPathInfo()`, which keeps percent-encoding, while `UrlMatcher::doMatch()` compares `rawurldecode($pathinfo)`. Any encoded byte in `/api/v1` therefore routes normally and escapes the guard.

| Cluster | Agent | Findings | Files owned |
|---|---|---|---|
| FIX-1 | e2e-contract-test-agent | S1 (RED), S3, S6, C1 (tests), T1, T2, P2 | `tests/Functional/CsrfOriginGuardTest.php` |
| FIX-2 | implementer | S1 (GREEN), C2, C3, OCP1, P1, C1 (unit coverage) | `src/Shared/Infrastructure/Http/CrossSiteRequestGuardSubscriber.php`, `src/Shared/Infrastructure/Http/CorsSubscriber.php`, new `src/Shared/Domain/Http/Origin.php`, `src/Shared/Domain/Http/OriginAllowList.php`, new `tests/Unit/Shared/Http/OriginTest.php`, new `tests/Unit/Shared/Http/OriginAllowListTest.php` |
| FIX-3 | implementer | S2, S4, S5 | `docs/adr/ADR-0013-session-cookie-credential.md` |
| REVIEW re-run | review fan-out | all | read-only |

Decisions taken by the orchestrator so the clusters cannot drift:
- The path is decoded **once** with `rawurldecode()`, mirroring `UrlMatcher::doMatch()`; a second decode would diverge from routing in the other direction.
- The allow-list becomes one value object (`OriginAllowList`) consumed by both `CorsSubscriber` and the guard; `CorsSubscriber` keeps echoing the raw `Origin` header value so browser comparison is unchanged.
- `Origin`/`OriginAllowList` live in `src/Shared/Domain/Http/` (the deptrac `Shared.Domain: []` layer, no Symfony coupling) and get unit tests, which also gives C1 real branch coverage instead of pinning every branch through HTTP.
- Priority moves to 14: 16 collides with `MalformedRememberMeCookieSubscriber` and Symfony's `LocaleListener`.
- infection stays **n/a**: the image has no coverage driver (`php -m` reports neither xdebug nor pcov), so MSI cannot be produced for the new Domain classes here; the unit tests are written to be mutation-ready.

## Environment blockers outside this task
- `tests/Functional/StorefrontReviewEndpointBudgetTest.php` is an untracked probe file written by another run (spec-run-12) at 11:31, while this task was at the GREEN gate. It carries deliberate `PROBE-GET` / `PROBE-POST` assertions that fail and two phpstan errors at lines 133/144. It is not this task's artifact and is never edited or deleted here; suite evidence for this task is collected with `--exclude-filter=StorefrontReviewEndpointBudgetTest`. Flagged to the user at DONE.
- `migrations/Version20261024000000.php` is also untracked and foreign to this task; php-cs-fixer flags it (1 of 482 files). Not touched here; flagged to the user at DONE.
- `tests/Architecture/CatalogApprovalDesignTest.php` fails (1 test) tripping on `src/Catalog/Infrastructure/ReadModel/CatalogVisibility.php`, `CategoryFinder.php` and `src/Shared/Infrastructure/ReadModel/StorefrontReferenceRepository.php` — all dated 2026-10-23, before this diff, and referencing nothing from it. Foreign to this task (same class as the spec-run-12 probe); recorded at review round 2, flagged to the user at DONE, not fixed here.

## Human approvals
none required: no bus, aggregate or bounded-context boundary, no migration, no new dependency, nothing under `src/Payment/**`

## Behaviors (PLAN — test-planner, routed into cycles)
Semantics settled before RED (fixes the ambiguities the planner left open):
- S1 scope: main requests, path prefix `/api/v1`; state-changing = POST, PUT, PATCH, DELETE.
- S2 allow-list = `STOREFRONT_CORS_ORIGIN` (comma-separated), same variable `CorsSubscriber` reads.
- S3 same-origin (Origin equals the request's own scheme+host) is never cross-site.
- S4 no Origin → fall back to the Referer origin.
- S5 no Origin and no Referer → `Sec-Fetch-Site: cross-site` refused, anything else not refused by that signal.
- S6 no Origin, no Referer, no `Sec-Fetch-Site` (curl, provider webhooks) → allowed. Keeps the whole existing Functional suite and provider callbacks working.
- S7 refusal = 403 `{"error": ...}` (repo shape, e.g. `JsonAccessDeniedHandler`).

| Cycle | Behaviors | Level | Test path | Kind |
|---|---|---|---|---|
| C1 | state-changing `/api/v1` request with a foreign Origin → 403 (valid login) | Functional | tests/Functional/CsrfOriginGuardTest.php | RED |
| C2 | configured storefront Origin → login 200 + session; same-origin Origin → login 200 | Functional | same file | RED |
| C3 | no Origin + foreign Referer → 403; no Origin/Referer + `Sec-Fetch-Site: cross-site` → 403 | Functional | same file | RED |
| C4 | pins: no-signal allowed, `Sec-Fetch-Site` same-origin/same-site/none allowed, GET/HEAD never 403, OPTIONS preflight 204 from a foreign Origin, non-`/api/v1` path untouched, order create 403 foreign / 201 configured | Functional | same file + CorsTest.php | PIN + mutation control |
| C5 | ADR-0013 trade-off text states the control and its residual gaps | docs | docs/adr/ADR-0013-session-cookie-credential.md | deliverable |

Deviations recorded:
- Behaviors 4+6 share one production change (the fallback signal chain) and are written in one RED dispatch; the two test methods still assert one behavior each (TDD-003 on the test, TDD-003 cycle batching recorded here).
- C4 is characterisation: those behaviors hold as soon as C3 lands, so they are proven by mutation (negative control) instead of a RED line.
- No `tests/Unit/**` behaviour is planned: the guard is exercised through HTTP, which is the contract that matters.
- ADR text is a documentation deliverable with no owning agent glob; the implementer is dispatched with the ADR path explicitly named in its boundary.

## Review re-run (round 2) — closure, driven by spec-run-13
Spec: spec-run-13/spec.md v2. Workers: WP1 (FIX-2 production + unit tests), WP2 (FIX-3 ADR). Fan-out: six reviewers, all APPROVE/PASS, zero blocking findings.

### Extended coverage table — spec requirements R1–R23
| R | Disposition | Proof |
|---|-------------|-------|
| R1 decode once with rawurldecode | done (WP1) | CrossSiteRequestGuardSubscriber.php:85; security-reviewer traced parity with UrlMatcher::doMatch() (vendor source); encoded spellings pinned 403 |
| R2 Origin VO at src/Shared/Domain/Http/Origin.php | done (WP1) | file exists, final readonly, zero imports; OriginTest green |
| R3 OriginAllowList at src/Shared/Domain/Http/OriginAllowList.php | done (WP1) | file exists; OriginAllowListTest green |
| R4 both subscribers consume the shared VOs (C3/OCP1) | done (WP1) | grep: STOREFRONT_CORS_ORIGIN read only by the two Autowire params, both via fromCommaSeparated(); code/architecture/solid verdicts |
| R5 Domain purity, deptrac Shared.Domain clean | done (WP1) | zero non-builtin imports; deptrac 0 violations |
| R6 CorsSubscriber observable behavior unchanged | done (WP1) | CorsTest 5/12 green, unedited; raw echo pinned assertSame |
| R7 priority 14, after router, before firewall | done (WP1) | debug:event-dispatcher: guard #13 sole at 14, router 32, firewall 8, Cors 256 |
| R8 allow-list parsed once | done (WP1) | constructor parse, readonly, shared service; performance-reviewer verified per-worker |
| R9 unit tests with the R9 branch set | done (WP1) | tests/Unit/Shared/Http/{OriginTest,OriginAllowListTest}.php — 21 tests / 58 assertions green |
| R10 frozen functional file fully green, production-only | done (WP1) | CsrfOriginGuardTest 24/98 green, file unmodified, both S1 data sets 403 |
| R11 guard behavior otherwise unchanged | done (WP1) | signal-chain/GET/HEAD/403-shape pins green in the frozen file |
| R12 ADR states raw-path caveat (S2) | done (WP2) | ADR-0013 cross-site posture paragraph (decode-mirroring sentence) |
| R13 ADR states write trust anchor (S4) | done (WP2) | ADR-0013 trade-off section |
| R14 ADR states Host/X-Forwarded-Host derivation (S5) | done (WP2) | ADR-0013 posture paragraph |
| R15 ADR contradicts nothing shipped (priority 14) | done (WP2) | grep: no "priority 16" remains; shared VO + unit tests stated |
| R16 fan-out re-run, findings cite rule IDs | done (round 2) | six handoffs above; every finding cites a rule ID |
| R17 closure only on a clean re-run | done (round 2) | zero blockers / DO-NOT-SHIP |
| R18 GATE: REVIEW ok recorded | done | Gates section, 23:59 |
| R19 extended coverage table | done | this table |
| R20 GATE: DONE with DoD evidence | done | Gates section, 23:59 |
| R21 infection recorded n/a | done | no xdebug/pcov in image; unit tests written mutation-ready |
| R22 four out-of-scope items untouched and recorded | done | Observations + Environment blockers sections; none modified |
| R23 priority 14 collision-free | done (WP1) | full kernel.request map: nothing else at 14 (LocaleAwareListener 15, firewall 8) |

### Round-1 findings — final disposition
S1 closed (decode-once + live curl battery); S2/S4/S5 closed (ADR); S3 closed (session-cookie assertions in frozen file, test-reviewer verified); S6 closed (Origin: null → 403 pin); C1 closed (unit branch set); C2 closed (priority 14, map-verified); C3 closed (single interpreter); C4 partially, as dispositioned round 1 (HEAD strictness done; helper if-branches frozen with FIX-1); OCP1 closed (seam); P1 closed (constructor parse; micro-items incidentally resolved too); P2 closed (FIX-1 teardown); T1/T2 closed; T3 accepted as documentation; T4 accepted repo-wide.

### Foreign artifacts flagged to the user at DONE (never edited here)
tests/Functional/StorefrontReviewEndpointBudgetTest.php (spec-run-12 probe; excluded via --exclude-filter; its earlier phpstan errors no longer reproduce); tests/Architecture/CatalogApprovalDesignTest.php (1 failure, pre-dates this diff, tripping on Oct-23 ReadModel files); migrations/Version20261024000000.php (cs-fixer flag no longer reproduces: 0 of 493 now).

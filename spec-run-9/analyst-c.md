# Spec: WhatsApp contact backend (skeptical reading)
Version: 1

## Goal
One public endpoint returns the shop's WhatsApp business number, the message that will be prefilled, and a `wa.me` link built only from server-side configuration — with no way for a caller to change the destination, no persistence, and no way for a bad configured value to ship silently.

## Corrected prompt
Add backend support for the WhatsApp contact button: the official WhatsApp Business number and a default contact message come from environment variables, one API endpoint returns them plus a dynamically generated and correctly URL-encoded `https://wa.me/` chat URL, an optional validated contextual message may replace the default text, the configured number is validated so an invalid value cannot produce a usable-looking link, no secrets are exposed, the client cannot choose the destination, and nothing else is built: no WhatsApp Business API, no chat system, no storage, no frontend changes.

Repo context given to this analyst (not requirements): `E-commerce/` (Symfony 8.1, PHP) already exposes `GET /api/v1/storefront/reference`, whose payload includes `contact.whatsapp` read from the `platform_setting` table (editable at runtime through `AdminSettingsController`), and it has a `StorefrontReferenceContactTest`. Public reads are attribute-routed controllers in `src/Shared/Infrastructure/Http/` dispatching a query DTO from `src/Shared/Application/Query/` over `MessengerQuery`/`query.bus` to `#[AsMessageHandler(bus: 'query.bus')]` handlers in `src/Shared/Infrastructure/ReadModel/`. `.env` declares environment keys injected through `%env(...)%` in `config/services.yaml`. `config/packages/security.yaml` maps `^/api/v1/storefront` to `PUBLIC_ACCESS`; nothing else under `/api` is matched, so an unmatched path falls through to whatever the firewall default is. Named rate limiters live in `config/packages/rate_limiter.yaml` and are applied explicitly in controllers through `RateLimitChecker`. Assertions are made in `tests/Functional/*Test.php` (`WebTestCase`), plus `tests/Unit`, `tests/Contract`, `tests/Architecture`; `deptrac.yaml` enforces the layer rules.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The WhatsApp number and default message are configured through environment variables; neither value appears as a literal in application code. | functional | "centralized configuration using environment variables"; "Do not hardcode" | must |
| R2 | Both configuration values have a defined behaviour when absent (explicitly stated in the spec, not "whatever happens"), so a fresh checkout and a production deploy cannot disagree by accident. | constraint | "Validate the configured phone number at application startup or configuration validation time"; AC4 | must |
| R3 | The configured number is validated against WhatsApp's international format before it can be used: digits only after normalisation, correct E.164 length bounds, no letters, no spaces, no punctuation other than an optional leading `+`. | constraint | "international format required by WhatsApp, without spaces or special characters" | must |
| R4 | A configuration that fails validation produces a deterministic, diagnosable failure (boot-time/config-validation error) rather than a 200 with a broken link. | constraint | "Validate the configured phone number at application startup or configuration validation time"; AC4 | must |
| R5 | Exactly one public `GET` endpoint returns the contact payload; its path and method are fixed by the spec and registered in the project's routing conventions. | functional | "Implement an endpoint that provides the WhatsApp contact information"; example `GET /api/contact/whatsapp` | must |
| R6 | The response body has exactly three keys — `phoneNumber`, `message`, `chatUrl` — with `phoneNumber` carrying the digits used in the link and no `+`. | functional | response example | must |
| R7 | `chatUrl` matches `https://wa.me/<digits>` optionally followed by `?text=<percent-encoded message>`; the scheme and host are constant and never derived from input. | functional | "Use the official `https://wa.me/` link format"; "Only generate links targeting the official WhatsApp domain" | must |
| R8 | `message` and the `text=` value in `chatUrl` describe the same string; the response never returns two different messages. | functional | response example | must |
| R9 | Message encoding is correct for characters that break naive concatenation: `,`, space, `&`, `=`, `?`, `#`, `+`, `%`, `'`, `"`, `/`, and multi-byte (accented/Arabic) text. | functional | "URL-encode the prefilled message correctly"; AC3 | must |
| R10 | An optional contextual message may replace the default; when the parameter is absent, empty or whitespace-only, the default message is used and the endpoint still answers 200. | functional | "Support an optional contextual message …" | should |
| R11 | A contextual message that violates the validation rules (over max length, control characters, invalid UTF-8, embedded newlines used for injection) is rejected with a defined client error and a machine-readable body; it is never silently truncated into a URL. | constraint | "Validate any contextual parameters to prevent malformed URLs or injection issues"; AC4 | must |
| R12 | No request input can set the phone number, the host, the scheme, or supply a ready-made URL; there is no parameter that is copied into `chatUrl` verbatim. | constraint | "Do not accept arbitrary redirect URLs from the client" | must |
| R13 | The endpoint's authorization matches the project's conventions for public storefront reads; the spec states the chosen policy explicitly rather than relying on a default. | constraint | "Apply the project's existing API authentication … conventions" | must |
| R14 | Rate limiting is explicitly decided: either an existing limiter is applied or the spec records why a static read needs none. | constraint | "…and rate-limiting conventions where appropriate" | should |
| R15 | No secret, token, internal URL or environment dump appears in the response, error bodies, or logs. | constraint | "Never expose private credentials, API tokens, or other secrets" | must |
| R16 | The endpoint performs no write: no message send attempt, no WhatsApp Business API call, no persistence of the request or the message. | constraint | "the user must explicitly send it inside WhatsApp"; "Do not implement message delivery through the WhatsApp Business API"; "No database entities, migrations, or message persistence" | must |
| R17 | No new Doctrine entity, migration, or table is introduced; existing platform settings and reference endpoint behaviour stay identical. | constraint | "No database entities, migrations, or message persistence are needed" | must |
| R18 | The implementation keeps the layered structure and the existing error-response shape of the codebase (JSON error with a stable `error`/`message` pair as produced by the existing handlers and `RateLimitChecker`). | quality | AC6; "Reuse existing … error-handling conventions" | must |
| R19 | Automated tests cover: default payload, contextual override, invalid contextual input, absence of an invalid-number acceptance path, exact encoding, and the immutability of the host. | quality | AC1, AC3, AC4 | must |
| R20 | The task changes no file under the frontend project and adds no frontend-related contract beyond the documented JSON. | constraint | "No frontend implementation"; "Do not modify frontend components" | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The literal path `/api/contact/whatsapp` in the prompt is illustrative; the project's `/api/v1/...` convention wins. | med |
| 2 | Contextual input is accepted as a query parameter, since the endpoint is a `GET`. A `POST`-with-body design is not implied. | med |
| 3 | Only a message can be contextual; page/product identifiers are not translated server-side into copy. | med |
| 4 | The default message is a single string, not a per-locale map. | med |
| 5 | An empty default message is legal and means "no prefilled text". | low |
| 6 | A missing configured number is a deployment error, not something to default to a fake number. | low |
| 7 | `displayName`/avatar/label are not part of the contract. | low |
| 8 | The existing DB-backed `contact_whatsapp` remains the source for the reference payload; this new endpoint is env-driven, and the spec must state which one wins for the button. | high |

## Open questions
- [BLOCKING] The repository already stores the WhatsApp number in `platform_setting.contact_whatsapp` and serves it from `/api/v1/storefront/reference`. Should the new endpoint (a) read the env variable only, (b) read the DB value with the env as fallback, or (c) replace the DB value entirely as the source of truth? Default if unanswered: (a) env only, DB untouched — the prompt says environment variables and no database changes.
- [BLOCKING] Exact route: `/api/contact/whatsapp` as written, or the versioned `/api/v1/storefront/contact/whatsapp`? Default if unanswered: versioned path; if the storefront already calls the literal one, both must be served.
- [minor] Query parameter name and maximum length for the contextual message. Default if unanswered: `message`, 200 characters, single line.
- [minor] Which client error for invalid contextual input: `400` with a validation body, or silent fallback to the default message? Default if unanswered: `400` — silent fallback hides the caller's bug.
- [minor] Should `chatUrl` omit `?text=` when the effective message is empty? Default if unanswered: omit it.
- [minor] What happens when the configured number is the placeholder `216XXXXXXXX` from the prompt? Default if unanswered: it is invalid (letters) and must fail validation, not be treated as a value.
- [minor] Is a `Vary`/cache header required, given the response depends on a query parameter? Default if unanswered: no caching headers; the endpoint is not cached.
- [minor] Do we need `Content-Type: application/json` asserted explicitly? Default if unanswered: yes, by `JsonResponse` as elsewhere.

## Out of scope
- WhatsApp Business API / Cloud API, templates, delivery webhooks, message status.
- Storing or logging conversation content; analytics or click tracking.
- New entities, columns, migrations, or a writable settings store.
- Admin UI changes for the WhatsApp number.
- Frontend button/UI, QR codes, deep links other than `wa.me`.
- Localising the default message.
- Deprecating or changing `/api/v1/storefront/reference` or `platform_setting`.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Configuration with explicit absence semantics and validation: two env keys, injected values, a validator that fails the build/boot on a malformed number. | R1, R2, R3, R4 | prompt; `.env`; `.env.prod.example`; `config/services.yaml` | env keys + arguments + validation entry point | `.env`, `.env.test`, `.env.prod.example`, `config/services.yaml` | none | a malformed `WHATSAPP_PHONE_NUMBER` fails a container/config test with a readable message; valid values pass; no literal number in `src/**` |
| WP2 | Message + URL construction as a pure unit: normalisation, message validation rules, encoding, constant `wa.me` host, empty-message handling. | R6 (phone form), R7, R8, R9, R11, R12 | WP1 values | unit-tested builder with explicit validation errors | new files under `src/Shared/Domain/WhatsApp/**` and `src/Shared/Application/**` | WP1 | unit tests assert exact URLs for `,`/space/`&`/`#`/`%`/unicode and assert each rejection rule |
| WP3 | Query + handler + controller + route + explicit auth decision. | R5, R10, R13, R14, R15, R16, R17, R18 | WP1, WP2 | endpoint returning exactly the three keys | `src/Shared/Application/Query/GetWhatsAppContact.php`, `src/Shared/Infrastructure/ReadModel/GetWhatsAppContactHandler.php`, `src/Shared/Infrastructure/Http/WhatsAppContactController.php`, `config/packages/security.yaml`, `config/packages/rate_limiter.yaml` | WP2 | `GET` 200 with three keys; override reflected; invalid override 400 with the project's error shape; no DB call; no write; auth policy visible in `security.yaml` |
| WP4 | Functional + unit proof at the boundary and at the builder. | R19 | WP2, WP3 | test files for both levels | `tests/Functional/WhatsAppContactTest.php`, `tests/Unit/Shared/WhatsApp/**` | WP3 | both suites green; tests assert scheme+host constant across inputs |
| WP5 | Documentation of variables, defaults, endpoint and error cases. | R2, R18 | WP1, WP3 | doc entry listing env keys, validation rule, response and error contract | `README.md` (single section), `docs/` (new page) | WP1, WP3 | doc states absence semantics and the 400 rule explicitly |

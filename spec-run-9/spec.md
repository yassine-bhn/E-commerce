# Spec: WhatsApp Business contact endpoint
Version: 2

## Goal
A public backend endpoint returns the shop's configured WhatsApp Business number, the message that will be prefilled and a `https://wa.me/` chat link generated on the fly, so the storefront button lands the visitor in the right conversation with no code change needed to move the number or reword the message.

## Corrected prompt
Add the backend support for a WhatsApp contact feature. The official WhatsApp Business number and a default contact message are stored in centralized, environment-based configuration and are never hardcoded in business logic; the number must be in WhatsApp's international format, without spaces or special characters. One endpoint (`GET`) returns the contact information to the frontend as `{phoneNumber, message, chatUrl}`, where `chatUrl` is generated dynamically from the configured number in the official `https://wa.me/` format with the message correctly URL-encoded and prefilled. The endpoint optionally accepts a contextual message (for the current page or product), which is validated so it cannot produce a malformed URL or inject anything. The configured number is validated at configuration validation time; no credentials, tokens or secrets are exposed; the client can never supply a redirect target and links only ever point at the official WhatsApp domain; existing authentication and rate-limiting conventions apply where appropriate. The user still sends the message inside WhatsApp: no WhatsApp Business API, no chat system, no database entities, migrations or message persistence, and no frontend implementation are part of this task.

Repo context (not requirements): working root `E-commerce/` (Symfony 8.1, PHP 8.4-era features such as typed constants). Public reads are attribute-routed controllers in `src/Shared/Infrastructure/Http/` that dispatch a query DTO from `src/Shared/Application/Query/` via `MessengerQuery` (bus `query.bus`) to `#[AsMessageHandler(bus: 'query.bus')]` handlers in `src/Shared/Infrastructure/ReadModel/` — `StorefrontReferenceController` / `GetStorefrontReferenceHandler` is the closest precedent. Environment keys are declared in `.env` and injected through `%env(...)%` in `config/services.yaml`. `config/packages/security.yaml` already grants `PUBLIC_ACCESS` to `^/api/v1/storefront`. Named rate limiters live in `config/packages/rate_limiter.yaml` and are applied explicitly per controller through `RateLimitChecker`. Client errors use `JsonResponse(['error' => '…'], 400)` (see `StorefrontReviewController`, `AdminSettingsController`). `platform_setting.contact_whatsapp` already exists, is edited through `PUT /api/v1/admin/settings` and is returned as `contact.whatsapp` by `/api/v1/storefront/reference`.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The WhatsApp number and the default contact message are read from two environment variables (`WHATSAPP_PHONE_NUMBER`, `WHATSAPP_DEFAULT_MESSAGE`) declared in `.env`, `.env.test` and `.env.prod.example`, and injected with `%env(...)%` in `config/services.yaml`. | functional | "Store the official WhatsApp contact number in a centralized configuration using environment variables." / "Provide a configurable default welcome message" | must |
| R2 | No phone number, message text or `https://wa.me/` URL literal appears in `src/**`; changing number or message is a configuration-only change. | constraint | "Do not hardcode the WhatsApp number in business logic." / AC5 | must |
| R3 | The configured number is normalised to digits only: a single optional leading `+` is accepted and stripped, and the remaining value must be 8–15 digits. | functional | "international format required by WhatsApp, without spaces or special characters" | must |
| R4 | An unparseable configured number (letters, spaces, dashes, parentheses, `+` inside, wrong length) fails deterministically at configuration-validation time with a diagnosable error; the endpoint never answers 200 with a link built from it, and the failure does not affect unrelated endpoints or commands. | constraint | "Validate the configured phone number at application startup or configuration validation time." / AC4 | must |
| R5 | `GET /api/v1/storefront/contact/whatsapp` (route name `storefront_whatsapp_contact`) returns HTTP 200 with a JSON body. | functional | "Implement an endpoint that provides the WhatsApp contact information to the frontend." (the prompt's `/api/contact/whatsapp` is labelled an example; AC6 requires existing routing conventions) | must |
| R6 | The response body contains exactly the three keys `phoneNumber`, `message`, `chatUrl` and nothing else. | functional | response example in "### 2. Backend Endpoint" | must |
| R7 | `phoneNumber` is the normalised digits-only number, without a leading `+`. | functional | `"phoneNumber": "216XXXXXXXX"` with "no spaces or special characters" | must |
| R8 | `message` is the effective message — the contextual one when supplied, otherwise the configured default — and is byte-for-byte the value that appears percent-decoded in the `text=` parameter of `chatUrl`. | functional | response example (`message` and `text=` carry the same string) | must |
| R9 | `chatUrl` is `https://wa.me/<digits>` followed by `?text=<encoded message>`, generated per request from configuration; when the effective message is empty the `?text=` part is omitted. | functional | "Generate the WhatsApp chat URL dynamically" / "Use the official `https://wa.me/` link format." | must |
| R10 | The message is encoded with percent-encoding (`rawurlencode` semantics, spaces as `%20`, comma as `%2C`) so that `&`, `=`, `?`, `#`, `+`, `%`, quotes, slashes and multi-byte characters (accented Latin, Arabic) cannot break or split the URL. | functional | "URL-encode the prefilled message correctly." / AC3 | must |
| R11 | The endpoint accepts an optional `message` query parameter which replaces the default message; when it is absent, empty or whitespace-only the configured default is used and the response stays 200. | functional | "Support an optional contextual message based on the page or product the user is viewing, if applicable." | should |
| R12 | A contextual `message` that is invalid (> 200 Unicode code points, contains control characters or newlines, or is not valid UTF-8) is rejected with HTTP 400 and the project's `{"error": "…"}` body; it is never truncated, sanitised into a different sentence, or used to build a URL. | constraint | "Validate any contextual parameters to prevent malformed URLs or injection issues." / AC4 | must |
| R13 | No request input can influence the destination: no parameter sets the number, scheme, host or a full URL, and the only host that can appear in `chatUrl` is `wa.me`. | constraint | "Do not accept arbitrary redirect URLs from the client." / "Only generate links targeting the official WhatsApp domain." | must |
| R14 | No credential, token, secret or internal host appears in the response, in error bodies, or in logs written by this feature. | constraint | "Never expose private credentials, API tokens, or other secrets." | must |
| R15 | The endpoint requires no authentication, inheriting the existing `^/api/v1/storefront` → `PUBLIC_ACCESS` rule; `config/packages/security.yaml` needs no new rule. | constraint | "Apply the project's existing API authentication … conventions where appropriate." | must |
| R16 | Rate limiting is explicitly decided: no limiter is applied to this read, and the reason (stateless configuration read, no write, no expensive work) is recorded in this spec. | constraint | "…and rate-limiting conventions where appropriate." | should |
| R17 | The read is side-effect free: no WhatsApp Business API call, no message send or queueing, no persistence, and the query handler performs no database access and no write. | constraint | "the user must explicitly send it inside WhatsApp" / "Do not implement message delivery through the WhatsApp Business API" | must |
| R18 | No Doctrine entity, migration, column or table is added, and `platform_setting` / `/api/v1/storefront/reference` behaviour is unchanged. | constraint | "No database entities, migrations, or message persistence are needed." / "Do not store conversations or messages." | must |
| R19 | The feature follows the existing architecture and conventions: controller → `MessengerQuery` → `query.bus` handler, `declare(strict_types=1)`, `final class`, attribute routing, `JsonResponse`, and phpstan / php-cs-fixer / deptrac stay clean. | quality | AC6 / "Reuse existing configuration, routing, validation, and testing conventions." | must |
| R20 | No file under `Zidane_front_web/` is created or modified, and no frontend contract beyond the documented JSON is added. | constraint | "No frontend implementation" / "Do not modify frontend components." | must |
| R21 | Automated tests cover: the builder's normalisation and exact URL strings, the default payload, the contextual override, the rejected contextual input, and the absence of any non-`wa.me` host. | quality | AC1, AC3, AC4 | must |
| R22 | The two variables (format rule, absence semantics, example) and the endpoint contract (path, response, 400 case) are documented where operators look for configuration. | quality | AC5 / AC6 | should |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The prompt's `/api/contact/whatsapp` is illustrative; the versioned storefront prefix wins because AC6 requires existing routing conventions and no frontend consumes the literal path yet. | med |
| 2 | A leading `+` is accepted in configuration (typical for a number copied from a phone book) and stripped before the URL is built. | low |
| 3 | The contextual override travels as a single `message` query parameter, since the endpoint is a `GET`. | med |
| 4 | Contextual messages are capped at 200 code points — long enough for a page/product context, short enough to stay a safe URL. | med |
| 5 | The default message is a single string; the fr/en/ar storefront passes a localised contextual message when it wants one. | med |
| 6 | An empty default message is legal and means "no prefilled text" (`chatUrl` without `?text=`). | low |
| 7 | A missing or invalid configured number is a configuration error with no fallback number. | low |
| 8 | The new endpoint is additive: `platform_setting.contact_whatsapp` keeps feeding `contact.whatsapp` in the reference payload, untouched. | high |
| 9 | `displayName`, avatar or button label are not part of the contract. | low |

## Open questions
All blocking questions are resolved (see Decisions, rows "Number source" and "Number value"). Remaining minor items:
- [minor] Should invalid contextual input be a 400 or a silent fallback to the default message? Default if unanswered: 400 (already chosen as R12).
- [minor] Is a `Vary: …`/cache header needed since the response depends on a query parameter? Default if unanswered: no cache headers.
- [minor] Should a locale parameter pick a translated default message? Default if unanswered: no.

## Out of scope
- WhatsApp Business API / Cloud API, message templates, delivery status, webhooks.
- Any custom real-time chat, inbox or conversation view.
- Persisting messages, conversations, clicks or analytics; logging message content.
- New entities, columns, migrations or a second writable settings store.
- Admin UI/endpoint changes for the WhatsApp number.
- Frontend button, UI state, QR codes, deep links other than `wa.me`.
- Translating the default message.
- Deprecating or changing `/api/v1/storefront/reference` and `platform_setting`.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Configuration surface: the two env keys with commented semantics, a syntactically valid default, prod example guidance, and the container wiring. | R1, R2, R4, R22 | prompt, `spec-run-9/spec.md`, `.env`, `.env.test`, `.env.prod.example`, `config/services.yaml` | env keys + service arguments; no value in `src/**` | `.env`, `.env.test`, `.env.prod.example`, `config/services.yaml` | none | container builds with `.env.dev` and `.env.test`; `grep -rE '[0-9]{8,}' src/**` shows no WhatsApp number; comments state format rule |
| WP2 | Framework-free WhatsApp model: number normalisation/validation, message validation rules, and the `wa.me` URL builder with constant scheme+host. | R3, R4(surface), R7, R9, R10, R12(rules), R13 | WP1 values, this spec | `WhatsAppNumber` value object + `WhatsAppChatLink` builder used by the handler | `src/Shared/Domain/WhatsApp/**` | WP1 | unit tests assert exact URLs for `,`/space/`&`/`#`/`+`/`%`/accented/Arabic text, the 8–15 digit rule, the 200-code-point rule and the constant host |
| WP3 | Read path and HTTP contract: query DTO, handler, controller with validation/error mapping, route. | R4(end-to-end), R5, R6, R8, R11, R12(400), R14, R15, R16, R17, R18, R19 | WP1, WP2 | `GetWhatsAppContact` + handler + controller | `src/Shared/Application/Query/GetWhatsAppContact.php`, `src/Shared/Infrastructure/ReadModel/GetWhatsAppContactHandler.php`, `src/Shared/Infrastructure/Http/WhatsAppContactController.php` | WP2 | `GET` 200 with exactly three keys; override reflected in both `message` and `chatUrl`; over-length override 400 with `{"error": …}`; handler has no DB access |
| WP4 | Proof: unit suite for the builder/number and functional suite for HTTP behaviour. | R21, R4, R5–R14 | WP2, WP3 | `tests/Unit/Shared/WhatsApp/**`, `tests/Functional/WhatsAppContactTest.php` | `tests/Unit/Shared/WhatsApp/**`, `tests/Functional/WhatsAppContactTest.php` | WP3 | `phpunit --testsuite=Unit --filter WhatsApp` and `--testsuite=Functional --filter WhatsAppContact` green; assertions on `parse_url($chatUrl)['host'] === 'wa.me'` for every case |
| WP5 | Documentation for operators and frontend consumers. | R22 | WP1, WP3 | env-var reference + endpoint description (path, response, `message` parameter, 400 case) | `README.md` (one section), `docs/` (new page) | WP1, WP3 | a reader can set both variables and call the endpoint without reading code |

## Decisions (synthesizer only)
| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Route path | `/api/v1/storefront/contact/whatsapp` (assumption) | versioned prefix (assumption) | BLOCKING: literal vs versioned | `/api/v1/storefront/contact/whatsapp` | The prompt labels `/api/contact/whatsapp` an example and AC6 demands existing routing conventions; the existing `^/api/v1/storefront` rule then covers auth with no new rule |
| Source of value | env only, DB untouched (assumption) | env only, risk high | BLOCKING: env / DB / replace | env only, DB untouched — raised as a BLOCKING question to the user | Prompt is explicit ("environment variables", "no database entities"); everything else is speculative |
| Invalid contextual input | validate, no policy | 4xx rejection | 400, not silent fallback | 400 with the project's `{"error": …}` body | Silent fallback hides a caller bug and makes the endpoint's behaviour unobservable; the codebase already returns 400 for bad query parameters |
| Empty effective message | not addressed | omit `?text=` | omit `?text=` | omit `?text=` | A `?text=` with nothing after it is noise and two analysts converged on omission |
| Phone format | digits only, optional `+` stripped | normalise, E.164 length | digits only, optional `+`, bounded length | digits only, 8–15 digits, optional single leading `+` | WhatsApp's own format is digits-only international; the bounds catch typos like a missing country code |
| Validation timing | startup/boot failure | boot or 5xx | deterministic, diagnosable failure | Validated when the configuration value object is built, so no link is ever produced from an invalid number; failure must be a hard error, not a fallback | Keeps the prompt's guarantee without inventing a bespoke config-bundle layer |
| Rate limiting | no new limiter (assumption) | no new limiter (assumption) | must be an explicit decision | No limiter; decision recorded as R16 | A stateless configuration read has no write side and no expensive work; the existing limiters protect writes |
| Auth | public read | public read | explicit policy | Public, inherited from `^/api/v1/storefront` | The endpoint is consumed by anonymous visitors before any contact exists |
| "message" echo | effective message (assumption) | same as `text=` | same as `text=` | Effective message in both places | The frontend may display it; two different strings would be a bug |
| Number source (confirmed by user) | env only, DB untouched (assumption) | env only, risk high | BLOCKING: env / DB / replace | **Env only, `platform_setting.contact_whatsapp` untouched** | User decision; prompt mandates environment variables and "no database entities". The reference payload keeps its current source, so R18 holds |
| Number value (confirmed by user) | not addressed | not addressed | BLOCKING: placeholder vs real number | **`21600000000` placeholder** in `.env`/`.env.test`; `.env.prod.example` documents the replacement rule | User decision; a syntactically valid placeholder keeps container, tests and local dev working until the production value is supplied |
| Merging granularity | 22 requirements | 20 requirements | 20 requirements | 22 requirements, mostly literal | Keep the literal, testable statements; the intent/skeptic items survive as R14–R18, R21–R22 |

## Changelog (synthesizer and orchestrator only)
- v1: created from analyst-a/b/c
- v2: user answered both BLOCKING questions — number source = env only (DB untouched); number value = `21600000000` placeholder. Open questions resolved; requirements unchanged.

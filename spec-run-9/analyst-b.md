# Spec: WhatsApp Business contact backend (intent reading)
Version: 1

## Goal
A storefront visitor clicks the WhatsApp button and lands in the shop's real WhatsApp Business conversation, on the right number, with a helpful message already typed in — and the shop owner can change number, message or add a per-page message by editing configuration and redeploying, without touching code.

## Corrected prompt
Build the backend half of the WhatsApp contact feature: keep the official WhatsApp Business number and a default contact message in environment-based configuration, expose them through one API endpoint together with a dynamically generated and correctly URL-encoded `https://wa.me/...` chat URL, allow an optional validated contextual message (page/product) to replace the default one, validate the configured number so a bad value can never ship a broken link, expose no secrets, let the client choose only the message and never the destination, and add nothing else — no WhatsApp Business API, no chat system, no persistence, no frontend changes.

Repo context given to this analyst (not requirements): `E-commerce/` is a Symfony 8.1 modular monolith with a QRS split. Public storefront reads are attribute-routed controllers in `src/Shared/Infrastructure/Http/` that dispatch a query from `src/Shared/Application/Query/` through `MessengerQuery` on `query.bus`, handled by `#[AsMessageHandler(bus: 'query.bus')]` classes in `src/Shared/Infrastructure/ReadModel/` (`StorefrontReferenceController` is the closest precedent — it is a pure configuration/reference read with no write side). `.env` holds the environment keys and `config/services.yaml` injects them with `%env(...)%`. `config/packages/security.yaml` maps `^/api/v1/storefront` to `PUBLIC_ACCESS`. Rate limiters are declared by name in `config/packages/rate_limiter.yaml` and enforced via `RateLimitChecker`. `platform_setting.contact_whatsapp` already exists in the DB and is returned by the reference endpoint, with `AdminSettingsController` able to change it at runtime.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The WhatsApp business number and the default contact message are supplied by environment variables and read into the application through its configuration layer, so no code change is needed to change either. | functional | "centralized configuration using environment variables"; AC5 | must |
| R2 | No phone number, message text or `wa.me` URL is hardcoded in business logic, controllers or services. | constraint | "Do not hardcode the WhatsApp number in business logic"; "Generate the WhatsApp chat URL dynamically" | must |
| R3 | The configured number is normalised to WhatsApp's dialling form: digits only, international prefix, no `+`, spaces, dashes or parentheses left in the generated link. | functional | "without spaces or special characters" | must |
| R4 | The configured number is validated as international (country code + subscriber digits, plausible E.164 length) at container/configuration validation time, so a typo fails boot instead of reaching a visitor. | constraint | "Validate the configured phone number at application startup or configuration validation time" | must |
| R5 | A single public `GET` endpoint returns a JSON object with `phoneNumber`, `message` and `chatUrl`. | functional | "Implement an endpoint that provides the WhatsApp contact information"; response example | must |
| R6 | `chatUrl` is always `https://wa.me/<digits>?text=<encoded message>`, built from configuration on every request. | functional | "Use the official `https://wa.me/` link format" | must |
| R7 | The prefilled text is encoded so that commas, spaces, quotes, `&`, `?`, `#`, `+`, `%` and accented/Arabic characters survive the round-trip and cannot split the query string. | functional | "URL-encode the prefilled message correctly"; AC3 | must |
| R8 | `message` in the response is the same text that appears encoded in `chatUrl`, so the frontend can display exactly what will be typed in WhatsApp. | functional | response example (`message` and `text=` carry the same string) | must |
| R9 | The caller may optionally override the default message with a contextual one (page, product, category) and the response then carries that text in both `message` and `chatUrl`. | functional | "Support an optional contextual message based on the page or product the user is viewing, if applicable" | should |
| R10 | The contextual message is validated (max length, no control characters, no newline injection) and rejected with a clear 4xx when it violates the rules; the default message is used when the parameter is absent or empty. | constraint | "Validate any contextual parameters to prevent malformed URLs or injection issues"; AC4 | must |
| R11 | The client can never influence the destination: no parameter accepts a number, host, scheme or full URL, and the only host that can appear in `chatUrl` is `wa.me`. | constraint | "Do not accept arbitrary redirect URLs from the client"; "Only generate links targeting the official WhatsApp domain" | must |
| R12 | The response and the generated link expose no token, secret or internal host; the endpoint is a plain public read. | constraint | "Never expose private credentials, API tokens, or other secrets" | must |
| R13 | The endpoint is reachable without authentication, matching the other public storefront reads, and does not introduce a stricter or looser policy than the surrounding conventions. | constraint | "Apply the project's existing API authentication and rate-limiting conventions where appropriate" | must |
| R14 | The read is side-effect free: no message is sent, queued or attempted through the WhatsApp Business API, and no conversation, click or request is persisted. | constraint | "the user must explicitly send it inside WhatsApp"; "Do not implement message delivery through the WhatsApp Business API" | must |
| R15 | No database entity, column or migration is added; the existing platform settings are neither modified nor duplicated in a second writable store. | constraint | "No database entities, migrations, or message persistence are needed"; "Do not store conversations or messages" | must |
| R16 | The feature respects the layering rules of the codebase (controller → query bus → handler; no domain knowledge of HTTP, no infrastructure leakage into Domain). | quality | AC6 "follows the existing backend architecture"; "Reuse existing … conventions" | must |
| R17 | The behaviour is proven by automated tests covering the happy path, the contextual override, the rejection of a malformed contextual message and the exact URL encoding. | quality | AC1, AC3, AC4 | must |
| R18 | A developer can find, in the repository's own documentation, which variables to set (including the international-format rule) and what the endpoint returns, without reading code. | quality | AC5; "Reuse existing configuration … conventions" | should |
| R19 | The feature is added without touching any file under the Next.js storefront project; the contract is expressed purely by the API response. | constraint | "No frontend implementation is included"; "Do not modify frontend components" | must |
| R20 | A misconfigured WhatsApp number/message must never break unrelated endpoints: validation is scoped to this feature's configuration. | quality | AC4 "handled safely"; AC6 | should |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Environment variables" is a deliberate choice over the existing DB-backed `platform_setting.contact_whatsapp`; the two are allowed to differ and the env value wins for this new endpoint. | high |
| 2 | The route lives under the codebase's versioned public prefix rather than the literal `/api/contact/whatsapp` example. | low |
| 3 | The contextual override travels as a single query-string parameter (e.g. `message=`), because the endpoint is a `GET` returning a link. | med |
| 4 | One configured default message is enough; the shop's fr/en/ar storefront will pass a localised contextual message when it wants one. | med |
| 5 | The default message may be empty, in which case `chatUrl` is returned without `?text=` and `message` is an empty string. | low |
| 6 | A missing or invalid configured number is a boot/5xx class failure, because there is no sensible fallback number. | med |
| 7 | No new rate limiter is needed: a static GET with no side effects is cheaper than the limiter's own storage round-trip; abuse protection stays at the reverse proxy. | med |
| 8 | The endpoint is additive: the reference payload keeps returning `contact.whatsapp` from platform settings, untouched. | med |

## Open questions
- [BLOCKING] Is the env-var configuration meant to replace `platform_setting.contact_whatsapp` (single source of truth) or sit beside it for this endpoint only? Default if unanswered: sit beside it; the new endpoint reads env only and the reference payload is left untouched.
- [minor] Should the endpoint also return `phone`/`displayName` for the button label? Default if unanswered: no, only the three documented keys.
- [minor] Which query parameter name and max length for the contextual message? Default if unanswered: `message`, 200 characters.
- [minor] Should a locale parameter select a translated default message? Default if unanswered: no.

## Out of scope
- WhatsApp Business API / Cloud API, message templates, delivery status, webhooks.
- Persisting messages, conversations, click tracking or analytics.
- Any new entity, migration, table or writable settings store.
- Admin screens for editing the WhatsApp number.
- Frontend button, UI state, QR codes, deep links other than `wa.me`.
- Translating the default message.
- Rewiring or deprecating the existing reference endpoint / platform settings.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Configuration surface: two environment variables, injected into the container, with commented dev defaults and a documented prod template. | R1, R2, R4, R18, R20 | prompt; `.env`; `.env.prod.example`; `config/services.yaml` | env keys + service arguments + validation hook; no literals in code | `.env`, `.env.test`, `.env.prod.example`, `config/services.yaml` | none | an unset/invalid number fails a container/config check, not a random request; `grep` shows no phone digits in `src/**` |
| WP2 | WhatsApp link builder as an isolated, framework-free unit: normalisation, message validation, encoding, URL assembly with a fixed `wa.me` host. | R3, R6, R7, R10, R11, R4 | WP1 values | a small collaborator with unit tests, used by the handler | new files under `src/Shared/Domain/WhatsApp/**` and `src/Shared/Application/**` | WP1 | unit tests assert the exact URL string for a comma/space/unicode message and assert rejection of over-length or control-character input |
| WP3 | Read model + endpoint: query DTO, handler, controller, route, security rule; response exactly `{phoneNumber, message, chatUrl}`. | R5, R8, R9, R12, R13, R14, R15, R16 | WP1, WP2 | query + handler + controller wired on `query.bus` | `src/Shared/Application/Query/GetWhatsAppContact.php`, `src/Shared/Infrastructure/ReadModel/GetWhatsAppContactHandler.php`, `src/Shared/Infrastructure/Http/WhatsAppContactController.php`, `config/packages/security.yaml` | WP2 | `GET` 200 with the three keys; `message` override reflected in `chatUrl`; over-length override yields 4xx; no DB access in the handler |
| WP4 | End-to-end proof at the HTTP boundary. | R17 | WP3 | functional test file covering default, override, invalid override, encoding and absence of secrets | `tests/Functional/WhatsAppContactTest.php` | WP3 | `phpunit --testsuite=Functional --filter WhatsAppContact` green and asserts the decoded URL contains the raw text |
| WP5 | Operator/developer documentation. | R18 | WP1, WP3 | env var reference entry plus endpoint description | `README.md` (single section), `docs/` (new page) | WP1, WP3 | a reader can set both variables and call the endpoint using only the docs |

# Spec: WhatsApp contact backend endpoint (literal reading)
Version: 1

## Goal
The backend exposes the configured WhatsApp business contact — phone number, default prefilled message and a ready-to-open `https://wa.me/...` chat URL — through one HTTP endpoint, with every value driven by environment configuration and no database involved.

## Corrected prompt
Implement the backend support for a WhatsApp contact feature: the official WhatsApp Business number and a default contact message are read from environment-based configuration, and an API endpoint returns them together with a dynamically generated, URL-encoded `https://wa.me/` chat URL, including an optional validated contextual message. The configuration is validated, the client cannot supply redirect targets, no WhatsApp Business API, no persistence and no frontend work is involved.

Repo context given to this analyst (not requirements): working root `E-commerce/` is a Symfony modular monolith with CQRS query handlers; public HTTP reads live in `src/Shared/Infrastructure/Http/` with attribute routes under `/api/v1/...`, dispatching a query DTO from `src/Shared/Application/Query/` through `MessengerQuery` to `query.bus`, handled in `src/Shared/Infrastructure/ReadModel/` with `#[AsMessageHandler(bus: 'query.bus')]` (see `StorefrontReferenceController` / `GetStorefrontReferenceHandler`). Environment values are declared in `.env` and injected through `%env(...)%` in `config/services.yaml` (existing example: `MEILISEARCH_URL`). `config/packages/security.yaml` grants `PUBLIC_ACCESS` to `^/api/v1/storefront`. `config/packages/rate_limiter.yaml` declares named limiters consumed through `RateLimitChecker`. A database column `platform_setting.contact_whatsapp` already exists and is returned by `GET /api/v1/storefront/reference`.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The official WhatsApp contact number is read from a centralized configuration source that is fed by an environment variable. | functional | "Store the official WhatsApp contact number in a centralized configuration using environment variables." | must |
| R2 | No WhatsApp phone number literal appears in business logic; changing the number requires an environment/config change only. | constraint | "Do not hardcode the WhatsApp number in business logic." / AC5 | must |
| R3 | A default contact message is configurable through the same centralized configuration and is not hardcoded in business logic. | functional | "Provide a configurable default welcome message or initial contact message." | must |
| R4 | The configured phone number is required to be in international WhatsApp format, containing no spaces and no special characters (digits only, optionally a leading `+` that is stripped). | constraint | "Ensure the phone number follows the international format required by WhatsApp, without spaces or special characters." | must |
| R5 | An HTTP `GET` endpoint returns the WhatsApp contact information to the frontend as a JSON object. | functional | "Implement an endpoint that provides the WhatsApp contact information to the frontend." | must |
| R6 | The response contains exactly the keys `phoneNumber`, `message` and `chatUrl`. | functional | JSON example under "### 2. Backend Endpoint" | must |
| R7 | `phoneNumber` is the configured number in the digits-only international form used to build the URL. | functional | `"phoneNumber": "216XXXXXXXX"` plus "without spaces or special characters" | must |
| R8 | `message` is the message actually prefilled in the returned `chatUrl` (default message, or the contextual message when one is supplied). | functional | `"message": "Hello, I would like more information about your products."` | must |
| R9 | `chatUrl` is generated at request time from the configured phone number, never from a stored literal URL. | functional | "Generate the WhatsApp chat URL dynamically from the configured phone number." | must |
| R10 | `chatUrl` uses the official `https://wa.me/<digits>` form and appends `?text=<encoded message>`. | functional | "Use the official `https://wa.me/` link format." | must |
| R11 | The prefilled message is correctly URL-encoded (commas, spaces, `&`, `?`, `#`, `%` and non-ASCII characters included) so the URL is never malformed. | functional | "URL-encode the prefilled message correctly." | must |
| R12 | The endpoint accepts an optional contextual message (for example the page or product being viewed) and uses it instead of the default message when provided. | functional | "Support an optional contextual message based on the page or product the user is viewing, if applicable." | should |
| R13 | Contextual input is validated before use, so it cannot produce a malformed URL or inject extra query parameters or hosts. | constraint | "Validate any contextual parameters to prevent malformed URLs or injection issues." | must |
| R14 | The configured phone number is validated at application startup / configuration validation time, and an invalid configured number fails loudly rather than producing a broken link. | constraint | "Validate the configured phone number at application startup or configuration validation time." / AC4 | must |
| R15 | No credential, API token or other secret is included in the response or in the generated URL. | constraint | "Never expose private credentials, API tokens, or other secrets." | must |
| R16 | The client cannot supply a redirect URL or host: the only host in `chatUrl` is `wa.me`, and the path is built solely from configuration. | constraint | "Do not accept arbitrary redirect URLs from the client." / "Only generate links targeting the official WhatsApp domain." | must |
| R17 | The endpoint follows the project's existing authentication and rate-limiting conventions; it is a public read consistent with the storefront's public `GET` endpoints. | constraint | "Apply the project's existing API authentication and rate-limiting conventions where appropriate." | must |
| R18 | The backend never sends or delivers a WhatsApp message: the prefilled text is only opened in the user's WhatsApp client and sent by the user. | constraint | "the user must explicitly send it inside WhatsApp" | must |
| R19 | No WhatsApp Business API integration, no chat system, and no conversation/message storage is implemented. | constraint | "Do not implement message delivery through the WhatsApp Business API…" / "No database entities, migrations, or message persistence are needed." | must |
| R20 | No frontend component is modified by this task. | constraint | "Do not modify frontend components." | must |
| R21 | The implementation uses the existing routing, validation, error-handling and testing conventions of the codebase. | quality | "Reuse existing configuration, routing, validation, and testing conventions." / AC6 | must |
| R22 | The endpoint is covered by automated tests for the default-message case, the contextual-message case, the invalid-contextual-input case and the encoding behaviour. | quality | AC1, AC3, AC4, AC6 | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | The route path in the prompt (`/api/contact/whatsapp`) is an example; the real path follows the codebase's `/api/v1/...` convention. | low |
| 2 | "Environment variable" means a new `.env` entry injected as a service argument / container parameter, matching the `MEILISEARCH_URL` pattern. | low |
| 3 | The default message is a single configured string (the prompt's example is English); no per-locale variants are required. | low |
| 4 | Contextual message is passed as a query-string parameter on the endpoint and is length/character limited by validation. | med |
| 5 | `message` in the response echoes the effective prefilled message (contextual when supplied), not always the default. | low |
| 6 | An invalid configured phone number is a server-side configuration error (5xx / startup failure), not a client error. | med |
| 7 | The endpoint stays `PUBLIC_ACCESS`; no new rate limiter is added, because a static configuration read carries no write-side cost (existing limiter `contact_submission` targets the contact form submission, not this read). | med |
| 8 | Nothing needs to be migrated or backfilled; the existing `platform_setting.contact_whatsapp` column is untouched by this task. | med |

## Open questions
- [minor] Is the route to be `/api/contact/whatsapp` exactly as written, or `/api/v1/storefront/contact/whatsapp` per convention? Default if unanswered: `/api/v1/storefront/contact/whatsapp`.
- [minor] Which contextual parameter name and limits? Default if unanswered: `message` (max 200 chars, single line, printable UTF-8).
- [minor] Should the default message support translations (fr/en/ar)? Default if unanswered: no, one configured string.
- [minor] How does this endpoint relate to the existing `platform_setting.contact_whatsapp` exposed by `/api/v1/storefront/reference`? Default if unanswered: both coexist untouched; the new endpoint reads env config only.

## Out of scope
- WhatsApp Business API / Cloud API integration, webhooks, templates, message status.
- Storing conversations, messages, clicks or any analytics.
- Any new database entity, column or migration.
- Frontend button, UI, deep linking or analytics wiring.
- Phone-number verification against WhatsApp, QR codes, or short links other than `wa.me`.
- Admin UI to edit the WhatsApp number.
- Localization of the default message.
- Changing the existing reference payload, platform settings, or admin settings endpoints.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Environment-driven configuration: `WHATSAPP_PHONE_NUMBER`, `WHATSAPP_DEFAULT_MESSAGE`, injected as parameters/arguments, with `.env` defaults and prod example updated. | R1, R2, R3, R22 | prompt; `config/services.yaml`; `.env`; `.env.prod.example` | parameters wired; no literal number in `src/**` | `.env`, `.env.test`, `.env.prod.example`, `config/services.yaml` | none | `grep` finds no phone literal in `src/**`; container builds; `.env` carries both keys with docblock comments |
| WP2 | WhatsApp value objects / URL builder with phone and message validation, producing the `wa.me` URL with correct encoding. | R4, R9, R10, R11, R13, R16 | WP1 parameters | a pure, unit-testable builder plus a configuration validator that rejects invalid numbers | `src/Shared/Domain/WhatsApp/**`, `src/Shared/Application/**` (new files only) | WP1 | unit tests cover digits-only normalisation, `+`/space rejection, encoding of `,`/space/`&`/`#`/unicode, and the contextual-message override |
| WP3 | Query DTO, handler, controller and route exposing the payload. | R5, R6, R7, R8, R12, R14, R15, R17, R18, R21 | WP1, WP2 | `GetWhatsAppContact` query + handler + controller returning the JSON object | `src/Shared/Application/Query/GetWhatsAppContact.php`, `src/Shared/Infrastructure/ReadModel/GetWhatsAppContactHandler.php`, `src/Shared/Infrastructure/Http/WhatsAppContactController.php`, `config/packages/security.yaml` (if a rule is needed) | WP2 | `GET` returns 200 with exactly the three keys; suplying a contextual message changes `message` and `text=`; no host other than `wa.me` can appear |
| WP4 | Functional test suite for the endpoint. | R22, R5–R13 | WP3 | `tests/Functional/WhatsAppContactTest.php` covering default, contextual, invalid contextual and encoding | `tests/Functional/WhatsAppContactTest.php` | WP3 | `vendor/bin/phpunit --testsuite=Functional --filter WhatsApp` green |
| WP5 | Documentation of the new environment variables and the public contract. | R1, R21 | WP1, WP3 | README/env documentation entry describing both variables and the endpoint | `README.md` (WhatsApp section only), `docs/` (new short page only) | WP1, WP3 | a reader can change number/message from the docs alone |

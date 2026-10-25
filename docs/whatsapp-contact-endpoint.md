# WhatsApp contact endpoint

`GET /api/v1/storefront/contact/whatsapp` (route name `storefront_whatsapp_contact`) is the public
read behind the storefront's "contact us on WhatsApp" button. It answers the configured WhatsApp
Business contact and a ready-to-open `https://wa.me/` chat link, so the visitor lands in the right
conversation with the shop's message already prefilled.

The endpoint is additive: `platform_setting.contact_whatsapp` and the `contact.whatsapp` field of
`GET /api/v1/storefront/reference` keep their own behaviour and source.

## Configuration

| Variable | Meaning | Rule |
|---|---|---|
| `WHATSAPP_PHONE_NUMBER` | The shop's WhatsApp Business number | Digits only, international format, 8–15 digits; a single leading `+` is accepted and stripped. |
| `WHATSAPP_DEFAULT_MESSAGE` | Message prefilled when the storefront sends no contextual message | Free text; may be empty, in which case the link carries no `text=` parameter. |

Both keys are declared in `.env` and `.env.test` and documented in `.env.prod.example`. The values
reach the handler through `#[Autowire('%env(...)%')]` on its constructor; no phone number or message
text is hardcoded in `src/**`, so moving the number or rewording the message is a configuration-only
change.

In production, `compose.prod.yaml` forwards both keys to the application container and
`deploy/release.sh` refuses to release while `WHATSAPP_PHONE_NUMBER` is empty, is not 8–15 digits, or
still holds the `21600000000` placeholder. The gate parses the value exactly as the application does
(surrounding quotes and one leading `+` are dropped; internal spaces, dashes and parentheses are
refused rather than normalised away), so a green release cannot ship a number the runtime rejects.
`WHATSAPP_DEFAULT_MESSAGE` must be declared but may be empty, so a shop that prefers no prefilled
text can still deploy.

The number is validated when the read handler is built. An unparseable value is a configuration
error: the contact read fails as a server error instead of answering a link built from it, while
unrelated endpoints keep working.

## Response

`200 OK`, `application/json`, exactly three keys:

```json
{
  "phoneNumber": "21600000000",
  "message": "Hello, I would like more information about your products.",
  "chatUrl": "https://wa.me/21600000000?text=Hello%2C%20I%20would%20like%20more%20information%20about%20your%20products."
}
```

- `phoneNumber` — the normalised digits, without `+` or separators.
- `message` — the effective message: the contextual one when supplied, otherwise the default.
- `chatUrl` — `https://wa.me/<digits>` plus `?text=<percent-encoded message>`; the `text=` part is
  omitted when the message is empty. Scheme and host are constants of the server, never inputs.

## Contextual message

`?message=` replaces the default message, for example:

```
GET /api/v1/storefront/contact/whatsapp?message=Bonjour%2C%20je%20souhaite%20un%20devis
```

| Input | Answer |
|---|---|
| absent, empty (`?message=`) or ASCII spaces only | `200` — the configured default message is used |
| 1–200 Unicode code points, no control characters, valid UTF-8 | `200` — echoed byte-for-byte in `message` and in the `text=` parameter, surrounding spaces included |
| more than 200 code points, control characters (newlines, tabs, null bytes), invalid UTF-8 | `400` — `{"error": "…"}` and nothing else |
| not a single string (`?message[]=Bonjour`) | `400` — `{"error": "…"}`; the value is never silently read as absent |

A rejected input is never truncated or sanitised into a different sentence, it is never used to
build a URL, and the error body never echoes it. Only ASCII spaces count as blank: `Hello\n` is
invalid input, not `Hello`.

## Guarantees

- Public read: no authentication (inherited from the `^/api/v1/storefront` rule), no session cookie.
- No rate limiter: the read is stateless, writes nothing and does no expensive work.
- Side-effect free: no WhatsApp Business API call, no message send or queueing, no database access
  and no write — the visitor sends the message inside WhatsApp.
- The caller cannot influence the destination: no parameter sets the number, scheme, host or a full
  URL, and the only host that can appear in `chatUrl` is `wa.me`.

## Out of scope

WhatsApp Business/Cloud API delivery, templates, webhooks, conversation storage, click analytics, an
admin UI for the number, and the storefront button itself.

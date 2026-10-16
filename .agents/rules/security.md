---
id: security
paths: ["src/**", "config/**", "tests/**"]
---
# Security & compliance rules

### SEC-001 — Never store or log card data
Scope: `src/**`, `config/**`
Rule: Code MUST NOT persist, log, or put on any bus a PAN, CVV or expiry; payments use provider tokens/payment-method IDs only.
Rationale: Storing PAN/CVV puts the merchant in PCI DSS scope; tokens keep us out.
BAD:  `$this->logger->info('charge', ['card' => $payload['card_number']]);` or a `card_number` column
GOOD: `Payment::authorize(Money $amount, PaymentMethodToken $token)`; DTOs contain `pm_tok_*` only.
Enforced by: `guard-secrets.sh` hook (PAN regex + `cvv|cvc|card_number` grep on diff) + security-reviewer + contract tests asserting payloads.

### SEC-002 — Every query enforces ownership or role
Scope: `src/*/Infrastructure/ReadModel/**`
Rule: See QRY-004: requester identity is a mandatory query parameter; SQL filters `customer_id = :requesterId` unless admin. Addresses, orders, payment methods included.
Rationale: IDOR is the most common e-commerce breach; handler-level filtering cannot be forgotten by a controller.
BAD:  `WHERE id = :id` on `order_history_view`
GOOD: `WHERE id = :id AND customer_id = :requesterId` (admin path covered by separate query).
Enforced by: functional tests per read model (cross-customer read → 404) + security-reviewer.

### SEC-003 — Webhooks verify provider signatures on the raw body
Scope: `src/Payment/Infrastructure/Http/**`
Rule: Webhook controllers MUST verify the provider HMAC signature against the raw request body before parsing or dispatching anything.
Rationale: JSON-reparse breaks signatures; skipping verification lets attackers forge `PaymentAuthorized`.
BAD:  `$payload = $request->toArray();` then signature check on re-encoded JSON
GOOD: `hash_equals($expected, hash_hmac('sha256', $request->getContent(), $secret))` before any dispatch.
Enforced by: contract test with valid/invalid signatures + security-reviewer.

### SEC-004 — Anonymization reaches every read model
Scope: `src/Identity/**`, `src/*/Infrastructure/Projection/**`
Rule: Customer anonymization MUST emit `CustomerAnonymized`, and every read table holding PII MUST have a subscriber that scrubs it (orders keep financial data, replace PII with tombstone values).
Rationale: GDPR erasure that only hits the write side still leaks names in `order_history_view`.
BAD:  anonymize `customer` table; `admin_customer_view` still shows the email
GOOD: `ScrubReadModelsOnCustomerAnonymized` updates all `*_view` rows for that customer id.
Enforced by: projection test (event → all views scrubbed) + security-reviewer.

### SEC-005 — Secrets only via environment; .env never committed
Scope: repo root, `config/**`
Rule: Secrets MUST come from env vars / the secrets vault; committing `.env`, `.env.*.local`, private keys or provider secrets is forbidden.
Rationale: A committed Stripe key is a breach on day one.
BAD:  `STRIPE_SECRET=sk_live_...` in a committed `.env`
GOOD: `.env` in `.gitignore`; `.env.example` with empty placeholders.
Enforced by: `guard-secrets.sh` hook (blocks commit) + gitleaks in CI.
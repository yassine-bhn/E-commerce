# WP6 brief — stale cart quote detection and reconfirmation at checkout

Authority: `spec-run-15/spec.md` v2, requirements R25–R29 and the WP6 row.
WP5a/b made money millime-native; WP2–WP4 made approval/stock authoritative. This package adds the
price-change guard between cart and order.

## Scope

Owned paths:

Backend:
- `src/Cart/Application/Dto/CartQuote.php`
- `src/Cart/Infrastructure/ReadModel/QuoteCartHandler.php`
- `src/Cart/Infrastructure/ReadModel/StorefrontCartFinder.php`
- `src/Cart/Infrastructure/Http/CheckoutQuoteController.php`
- `src/Ordering/Application/Command/PlaceOrder.php`
- `src/Ordering/Application/CommandHandler/PlaceOrderHandler.php`
- `src/Ordering/Infrastructure/Http/CheckoutOrderController.php`
- `src/Ordering/Domain/Model/PriceChangedException.php` (new)
- `src/Shared/Application/Service/StorefrontQuoteService.php` only if a normaliser is extracted;
  otherwise keep the comparison in the handler.

Frontend:
- `../Zidane_front_web/src/lib/api/types.ts`
- `../Zidane_front_web/src/lib/api/storefront.ts`
- `../Zidane_front_web/src/routes/commande.tsx`
- `../Zidane_front_web/src/lib/api/cart.ts` only for a confirmed-quote helper

Tests:
- `tests/Unit/Ordering/PlaceOrderPriceChangeTest.php` (new)
- `tests/Projection/Cart/StorefrontCartFinderTest.php` (new)
- `tests/Functional/CheckoutPriceChangeTest.php` (new)
- existing `PlaceOrder`/checkout HTTP call sites in `tests/Integration/Ordering/**` and
  `tests/Functional/**` must keep compiling after the new optional command field.

Never edit: `spec-run-15/**`, `.agents/**`, approval handlers/read models, stock logic, money
parsing, or the applied migrations. Do not touch `commande.tsx` checkout layout beyond the price
warning and confirm action.

## Backend — cart read (R25)

`StorefrontCartFinder::handleGetStorefrontCart` adds to every item:
- `currentUnitPrice` (int millimes): the catalogue price for the selected variant, or the product's
  base `price_minor` when `variant` is null/empty. Resolve the variant from the parsed `variants`
  JSON of the view row.
- `priceChanged` (bool): `currentUnitPrice !== unitPrice`.

The stored `unitPrice` snapshot stays unchanged. If the product row is gone (`LEFT JOIN` null),
set `currentUnitPrice = unitPrice` and `priceChanged = false` (nothing to compare against).

## Backend — quote endpoint (R26)

`CartQuote` gains:
- `lines`: `list<array{slug: string, variant: ?string, unitPrice: int, quantity: int}>` (the priced
  lines, `unitPrice` in millimes)
- `promoCode`: `?string`
- `deliveryMethod`: `string`

`QuoteCartHandler` fills them from the service result (`pricedLines` already has `unitPriceMinor`;
map it to `unitPrice`). `CheckoutQuoteController::quote` returns them as `lines`, `promoCode`,
`deliveryMethod` alongside the existing totals.

## Backend — checkout reconfirmation (R27, R28)

1. `PlaceOrder` gains a trailing optional field:
   `public ?array $confirmedQuote = null`.
   Existing call sites keep compiling (named args / trailing default).

2. `PriceChangedException extends \DomainException` with:
   - `public readonly array $currentQuote` (shape identical to the quote endpoint response:
     `lines`, `subtotal`, `discount`, `delivery`, `total`, `count`, `promoCode`, `deliveryMethod`)
   - `public readonly array $changedLines` (list of
     `{slug, variant, previousUnitPrice, currentUnitPrice, previousQuantity, currentQuantity}` for
     any line whose slug/variant/quantity/unitPrice differs, plus lines present on only one side
     with the missing side's values as `null`)
   - message `price-changed`.

3. `PlaceOrderHandler::__invoke`:
   - keep the `processed_commands` insert first (transaction rolls it back on throw).
   - compute `$quote = $this->quotes->quote(...)`.
   - if `$command->confirmedQuote` is not null, normalise both sides and compare:
     - lines: group by `slug` + normalised variant (trim, empty→null), sum `quantity`, sort by
       `slug` then `variant`; compare `slug`, `variant`, `quantity`, `unitPrice` (the confirmed
       `unitPrice` vs current `unitPriceMinor`).
     - `promoCode` (string|null), `deliveryMethod`, `subtotal`, `discount`, `delivery`, `total`
       (integers in millimes).
   - on any difference or a missing `confirmedQuote`, throw `PriceChangedException` with the
     current quote payload and the changed-line list. No reservation, order, payment, audit or
     outbox row is created; the transaction rolls back the idempotency insert.
   - otherwise proceed as today (reserve, save, payment, outbox).

   Note: a missing `confirmedQuote` is also a 409 — checkout requires reconfirmation (R27).

4. `CheckoutOrderController::create`:
   - parse `confirmedQuote` from the body (must be an array or null; a non-array is a 400).
   - pass it to `PlaceOrder`.
   - in the catch chain, handle `PriceChangedException` (direct or unwrapped from
     `HandlerFailedException`) → 409 JSON:
     ```json
     { "error": "price-changed", "quote": <current quote>, "changedLines": <list> }
     ```
   - do not consume the idempotency key (the rollback already did).

## Backend — tests

- `tests/Unit/Ordering/PlaceOrderPriceChangeTest.php`: handler throws `PriceChangedException` when
  `confirmedQuote` is null; throws when a line price differs; throws when promo/delivery/total
  differs; succeeds and snapshots current prices when they match; a retry with the same
  idempotency key creates no second order (use the existing in-memory fakes).
- `tests/Projection/Cart/StorefrontCartFinderTest.php`: stored snapshot unchanged, `currentUnitPrice`
  and `priceChanged` correct for base and variant lines, and for a missing product row.
- `tests/Functional/CheckoutPriceChangeTest.php`:
  - stale quote → 409 `price-changed`, no order/reservation/payment/audit/outbox row, idempotency
    key unused.
  - resubmit with the current quote → 201, order lines snapshot current prices.
  - same idempotency key retry → one order.
  - a later catalogue price change does not alter the stored order.
- Existing `PlaceOrder`/checkout HTTP call sites keep compiling (the new field is optional).

## Frontend — types and API (R29)

- `types.ts`: `CartQuote` gains `lines: {slug, variant, unitPrice, quantity}[]`, `promoCode: string | null`,
  `deliveryMethod: string` (optional for backward compat). `OrderInput` gains
  `confirmedQuote?: CartQuote`.
- `storefront.ts`: `createOrder` sends `confirmedQuote` in the body when present.

## Frontend — checkout flow

`commande.tsx`:
- Keep a ref `idempotencyRef` that is created lazily and reused across the 409, the manual
  confirmation and network retries until an order succeeds; only reset it after success.
- On submit, build `confirmedQuote` from the latest `totals` (quote endpoint shape) and send it.
- On a 409 with `error === "price-changed"`:
  - store the returned `quote` as the new confirmed quote and the `changedLines`;
  - show a warning listing each changed line with previous and current unit price and total;
  - keep the cart and the summary; do not clear the cart; do not auto-retry; do not regenerate the
    idempotency key.
  - render a separate "Confirmer avec les nouveaux prix" action that resubmits with the updated
    confirmed quote.
- Network retries reuse the same idempotency key.
- On success: clear cart, clear confirmed quote, reset idempotency ref.

`cart.ts`: add a small helper `toConfirmedQuote(cartQuote, cart, method, promoCode)` if useful; keep
the existing quote hook untouched.

## TDD and verification

RED before production code; capture the real failure. Minimum RED records:
1. `PlaceOrderPriceChangeTest` — handler accepts a null confirmed quote today and creates an order
   (must instead throw).
2. `StorefrontCartFinderTest` — finder returns no `priceChanged` key today.
3. `CheckoutPriceChangeTest` — stale quote currently succeeds (must 409).

Run and report:
- `docker compose exec app vendor/bin/phpunit tests/Unit/Ordering/PlaceOrderPriceChangeTest.php`
- `docker compose exec app vendor/bin/phpunit tests/Projection/Cart/StorefrontCartFinderTest.php`
- `docker compose exec app vendor/bin/phpunit tests/Functional/CheckoutPriceChangeTest.php`
- `docker compose exec app vendor/bin/phpunit tests/Unit tests/Integration tests/Projection tests/Functional tests/Architecture`
- `npm run typecheck && npm run lint` in the frontend.

The only expected remaining failure is `AdminProductListTest:142` (Meilisearch state, WP7).
Do not commit. Final report at most 18 lines: status; files; RED commands + failure lines; suite
counts; each R25–R29 clause; remaining failures; deviations.

# WP5b brief — decimal DT in the operator and storefront UI

Authority: `spec-run-15/spec.md` v2, requirements R23, R36, R37 and the frontend paths of WP5.
WP5a already made the backend millime-native. This package makes the frontend match it. There is no
backend work in WP5b.

Repo: `/home/yassine/Desktop/freelance/Zidaine-groupe/Zidane_front_web` (sibling of `E-commerce`).
Use `npm run typecheck`, `npm run lint` and `npm run build` there. Do not run docker for this package.

## The unit contract

The API returns and accepts integer **millimes** for every monetary field (`price`, `oldPrice`,
variant `price`, `deliveryFee`, `freeDeliveryThreshold`, cart/order totals). The UI shows decimal
**DT**, using a comma as the decimal separator and no unnecessary trailing zeros:
`15300` → `15,3 DT`, `680000` → `680 DT`, `153894320` → `153894,32 DT` (R37). Admin forms accept
`15.3` or `15,3`, up to three decimals, and send canonical decimal strings (dot) so the backend
parses them exactly. Never divide millimes by 1000 to obtain digits and never multiply a parsed
float by 1000 (R23/R36: no float round-trip).

## Owned paths

- `src/data/catalog.ts`
- `src/components/admin/SettingsPanel.tsx`
- `src/components/admin/ProductSheet.tsx`
- `src/components/site/Listing.tsx`
- `src/lib/api/admin.ts`
- comments in `src/lib/api/types.ts` only if they name the wrong unit
- any other frontend file that breaks typecheck only because an admin price input type changed;
  report each one. Do not touch `commande.tsx`, `cart.ts`, `storefront.ts` or checkout logic (WP6).

## Shared helpers in `src/data/catalog.ts`

Add exactly these, and make `formatDT` use the formatter:

```ts
/** Parses a non-negative decimal DT string ("15.3" or "15,3") into integer millimes, or null. */
export function dtToMillimes(value: string): number | null;

/** Integer millimes -> plain decimal DT input string, dot separator, no trailing zeros. */
export function millimesToDt(value: number): string;

/** Display format: 15300 -> "15,3 DT"; 680000 -> "680 DT"; 153894320 -> "153894,32 DT". */
export function formatDT(value: number): string;
```

Rules:

- `dtToMillimes` trims, matches `^(\d+)(?:[.,](\d{1,3}))?$` and returns
  `Number(integerPart + fraction.padEnd(3, "0"))` (string concatenation, exact integer). It returns
  `null` for empty input, negatives, a leading `+`, more than three decimals or anything else.
  `"15.3"` → `15300`, `"153894.32"` → `153894320`, `"0.5"` → `500`, `"15.3000"` → `null`.
- `millimesToDt` uses integer/string arithmetic only (`String(Math.abs(Math.trunc(value)))` padded
  to at least four characters, split three digits from the end, trim trailing zeros). No `/ 1000`
  producing a float. `15300` → `"15.3"`, `680000` → `"680"`, `500` → `"0.5"`, `0` → `"0"`.
- `formatDT` reuses the same split and appends `" DT"` with a comma decimal separator; no thousands
  grouping (R37's examples have none). Negative values keep a leading `-`, for refunds.
- Keep `formatDT`'s existing exported name and single argument; every caller must keep compiling.

## SettingsPanel (R23)

- Delete the local `dtToMillimes`/`millimesToDt`; import the shared helpers.
- Initial values and post-save refresh use `millimesToDt(...)`.
- Submit converts with the shared exact parser. Invalid input keeps the current toast/validation
  behaviour; do not silently round.
- Hints keep using `formatDT(...)` and must show `15,3 DT` for `15300`.

## ProductSheet (R36)

- Form values stay strings in DT. On load: `millimesToDt(product.price)`,
  `millimesToDt(product.oldPrice)` when present, and `millimesToDt(variant.price)` for each variant.
- On submit: parse `price`, `oldPrice` and every variant price with `dtToMillimes`. Reject `null`
  with a message saying "décimal (3 décimales max)". Compare `oldPrice > price` in millimes.
- When variants exist, the computed base price is the lowest variant price; display it with
  `millimesToDt` and send the same canonical string.
- Payload: change `AdminProductInput.price` to `string`, `oldPrice` to `string | null`, and
  `AdminVariantInput.price` to `string` in `src/lib/api/admin.ts`. Send `millimesToDt(parsed)`.
  The API responses stay integer millimes, so display code continues to use `formatDT`.
- Update the misleading "prix entier" messages and the `admin.ts` "Prices are whole dinars"
  comment to the millime contract.
- Keep `toInt` only for genuinely integer fields such as `stockQuantity`; prices must not use it.

## Listing and price filters (R37)

The API filter is millimes (`StorefrontProductRepository` compares `price_minor`), so:

- `ListingParams.priceMin/priceMax` become millimes. `parsePrice` returns millimes via
  `dtToMillimes`, so the existing whole-dinar URLs keep their meaning: `priceMin=150` still means
  150 DT and is sent as `150000`.
- `PRICE_PRESETS` become millime values: `150000`, `500000`, `1000000`; labels stay `150 DT` etc.
- URL serialization writes decimal DT (`millimesToDt`), not the raw millime number, so a reload
  parses the same value. `minInput`/`maxInput` display decimal DT and apply through the parser.
- Chip text keeps `formatDT(...)`, now showing `150 DT` / `150,5 DT`.
- Do not change sort values; the backend sorts on `price_minor` already.

## TDD / RED evidence

There is no frontend test runner. Use Node 22's built-in TypeScript stripping for a real behavioural
RED/GREEN on the helpers:

1. Write `/tmp/opencode/wp5b-money.test.ts` importing `dtToMillimes`, `millimesToDt`, `formatDT`
   from the absolute `file:///.../src/data/catalog.ts` path. Assert the examples above.
2. Run `node --experimental-strip-types /tmp/opencode/wp5b-money.test.ts` and record the actual
   failure before implementing (`formatDT(15300)` currently returns `"15 300 DT"` and
   `dtToMillimes`/`millimesToDt` are missing).
3. Implement, re-run to green. If Node's stripper cannot load the module, compile the helper with
   `npx tsc` to a temp directory and run the same assertions; report the exact command.

Then verify the UI integration with `npm run typecheck`, `npm run lint` and `npm run build`.

## Final report (max 14 lines)

Status; files changed; RED command and actual failure; GREEN command and counts; typecheck/lint/build
results; each R23/R36/R37 clause; any frontend file touched only for the type change; deviations.
Do not commit.

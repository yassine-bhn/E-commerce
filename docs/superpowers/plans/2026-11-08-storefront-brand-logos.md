# Storefront Brand Logos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose approved brand logo references from the backend and render them in storefront brand cards with a text fallback.

**Architecture:** Extend the existing storefront `/reference` read model so brands are objects containing `name` and nullable `image`. Preserve the existing relative `/uploads/brands/...` storage reference and `assetUrl()` URL resolution. Update every frontend `/reference` consumer so filtering and links continue to use `brand.name`, while the homepage renders `brand.image` when present.

**Tech Stack:** Symfony 8.1, Doctrine DBAL, PHPUnit, React, TypeScript, Next.js, Tailwind CSS.

## Global Constraints

- The database stores the relative upload path; image files remain under `public/uploads/brands`.
- Only approved brands are exposed by the public reference endpoint.
- Missing logos are represented by `image: null` and render the brand name.
- Do not add a migration, change upload storage, or add a new test runner.
- Run PHP commands through `docker compose exec app`.

---

### Task 1: Return logo references from the storefront reference API

**Files:**
- Modify: `src/Shared/Infrastructure/ReadModel/StorefrontReferenceRepository.php:186-197`
- Modify: `src/Shared/Infrastructure/ReadModel/GetStorefrontReferenceHandler.php` only if return annotations require adjustment
- Test: existing shared read-model or functional reference tests; create a focused test under `tests/Unit/Shared/Infrastructure/ReadModel/` if no suitable test exists

**Interfaces:**
- Consumes the existing `storefront_brand(name, image_path, approval_status)` table.
- Produces `brands: list<array{name: string, image: string|null}>` in `/api/v1/storefront/reference`.

- [ ] **Step 1: Write the failing repository test**

Add a test that configures the DBAL connection to return two approved rows, one with an upload path and one with `NULL`, then asserts `brands()` returns:

```php
[
    ['name' => 'CBM', 'image' => '/uploads/brands/cbm.webp'],
    ['name' => 'SOPAL', 'image' => null],
]
```

Also assert the query filters `approval_status = 'APPROVED'` and orders by name.

- [ ] **Step 2: Run the focused test and confirm it fails**

Run:

```bash
docker compose exec app vendor/bin/phpunit tests/Unit/Shared/Infrastructure/ReadModel/StorefrontReferenceRepositoryTest.php
```

Expected: failure because the current method maps each row to a string name.

- [ ] **Step 3: Implement the smallest read-model change**

Change the SQL projection from `SELECT name` to `SELECT name, image_path`, update the return annotation, and map each row to:

```php
[
    'name' => (string) $row['name'],
    'image' => null === $row['image_path'] ? null : (string) $row['image_path'],
]
```

Leave approval filtering, ordering, and the handler envelope unchanged.

- [ ] **Step 4: Run the focused test and backend checks**

Run the focused PHPUnit test, then:

```bash
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff
```

Expected: the focused test passes and static/style checks report no new issues.

- [ ] **Step 5: Commit the backend slice**

```bash
git add src/Shared/Infrastructure/ReadModel/StorefrontReferenceRepository.php tests
git commit -m "feat: expose brand logos in storefront reference" -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

### Task 2: Update frontend reference types and brand consumers

**Files:**
- Modify: `/home/youssefmsi/Downloads/Zidane_front_web/src/lib/api/types.ts:101-108`
- Modify: `/home/youssefmsi/Downloads/Zidane_front_web/src/routes/index.tsx`
- Modify: `/home/youssefmsi/Downloads/Zidane_front_web/src/routes/a-propos.tsx`
- Modify: `/home/youssefmsi/Downloads/Zidane_front_web/src/routes/recherche.tsx` only where explicit `brands: string[]` assumptions exist
- Modify: `/home/youssefmsi/Downloads/Zidane_front_web/src/routes/boutique.$category.tsx` only where explicit `brands: string[]` assumptions exist

**Interfaces:**
- Consumes `ReferenceData.brands: StorefrontBrand[]`.
- Produces stable `brand.name` values for search links, filters, counts, and text lists.

- [ ] **Step 1: Update the shared API type**

Add:

```ts
export interface StorefrontBrand {
  name: string;
  image: string | null;
}
```

and change `ReferenceData.brands` to `StorefrontBrand[]`.

- [ ] **Step 2: Update non-visual consumers**

In About and listing/filter routes, replace string operations with `brand.name` while preserving current visible names, counts, and search/filter query values. Do not alter product brand filtering semantics.

- [ ] **Step 3: Run frontend type validation**

Run from the frontend root:

```bash
cd /home/youssefmsi/Downloads/Zidane_front_web
yarn typecheck
```

Expected: PASS with no reference-shape TypeScript errors.

- [ ] **Step 4: Commit the frontend data-contract slice**

```bash
git add src/lib/api/types.ts src/routes/index.tsx src/routes/a-propos.tsx src/routes/recherche.tsx 'src/routes/boutique.$category.tsx'
git commit -m "feat: consume storefront brand logo data" -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

### Task 3: Render logos and text fallback in homepage brand cards

**Files:**
- Modify: `/home/youssefmsi/Downloads/Zidane_front_web/src/routes/index.tsx`

**Interfaces:**
- Consumes `StorefrontBrand { name, image }` and existing `assetUrl(path)`.
- Produces partner-brand cards that show an image when `image !== null`, otherwise the brand name.

- [ ] **Step 1: Add the logo-aware card markup**

Change `HomeBody` to accept `brandList: StorefrontBrand[]`. For each brand, keep the existing `/recherche?q=<brand name>` link and render:

```tsx
{brand.image ? (
  <img src={assetUrl(brand.image)} alt={brand.name} className="max-h-12 max-w-32 object-contain" />
) : (
  <span>{brand.name}</span>
)}
```

Keep the card’s accessible label available when a logo is shown, for example with `aria-label={brand.name}` on the link.

- [ ] **Step 2: Run targeted frontend checks**

Run:

```bash
cd /home/youssefmsi/Downloads/Zidane_front_web
yarn eslint src/routes/index.tsx src/routes/a-propos.tsx src/routes/recherche.tsx 'src/routes/boutique.$category.tsx' src/lib/api/types.ts
yarn typecheck
yarn build
```

Expected: targeted lint, typecheck, and production build pass. Existing unrelated full-lint warnings/errors are not part of this change.

- [ ] **Step 3: Verify browser behavior**

Open `/` with an authenticated/available backend containing one brand with an image and one without. Confirm the image card loads from `/uploads/brands/...`, the imageless card shows its name, and both cards link to the correct brand search.

- [ ] **Step 4: Commit the storefront UI slice**

```bash
git add src/routes/index.tsx
git commit -m "feat: render brand logos on storefront homepage" -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

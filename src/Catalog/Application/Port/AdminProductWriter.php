<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Dto\ProductDraft;

/**
 * solid Task 4.11 — the admin product writes. The adapter owns the row persistence, the brand
 * canonicalization/registration (so a product may carry a brand the admin never registered), the
 * delete's cleanup (balance row, view row, submission row — one owner for the whole record), and
 * the content audit for the update, which is why the writes take the actor id.
 */
interface AdminProductWriter
{
    /**
     * Inserts the product row, registering the brand if missing. A taken slug answers
     * ProductAlreadyExistsException.
     */
    public function create(ProductDraft $draft, string $actorId): void;

    /**
     * Updates the product row (the caller has already authorized the submission), records the
     * CATALOG_PRODUCT_UPDATED audit entry and applies the reviewer/owner transition.
     */
    public function update(string $slug, ProductDraft $draft, string $actorId, bool $actorIsSuperAdmin): void;

    /**
     * Deletes the whole record: the balance row, the view row and the submission row (R11). A
     * missing product answers ProductNotFoundException.
     */
    public function delete(string $slug, string $actorId): void;
}

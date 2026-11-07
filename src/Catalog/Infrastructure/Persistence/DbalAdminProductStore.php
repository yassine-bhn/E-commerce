<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Application\Dto\ProductDraft;
use App\Catalog\Application\Port\AdminProductWriter;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Port\CatalogSubmissionCreator;
use App\Catalog\Application\Port\CatalogSubmissionDeleter;
use App\Catalog\Application\Port\CategoryReader;
use App\Catalog\Application\Port\ProductDeletionReader;
use App\Catalog\Application\Port\ProductPublicationStore;
use App\Catalog\Application\Port\ProductReviewStore;
use App\Catalog\Application\Service\SubcategoryTree;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\InvalidProductTaxonomyException;
use App\Catalog\Domain\Model\ProductAlreadyExistsException;
use App\Shared\Application\Port\InventoryStockPort;
use App\Shared\Domain\Storefront\ProductNotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * DIP-003 / solid Task 4.11 — the admin product adapter behind narrow ports: the publication
 * flag (the one writer, R28), the public review insert, the order-history count, and the
 * create/update/delete workflow. The delete removes the whole record — balance row, view row
 * and submission row (R11) — because the handler cannot carry a sixth dependency for the
 * submission store; the submission removal is therefore this adapter's job too, documented in
 * ADR-0018. The create/update register a brand the admin never registered, so a product may
 * carry a new brand without a second round-trip.
 */
final class DbalAdminProductStore implements ProductPublicationStore, ProductReviewStore, ProductDeletionReader, AdminProductWriter
{
    public function __construct(
        private Connection $connection,
        private CatalogSubmissionCreator $submissions,
        private CatalogAuditPort $audit,
        private InventoryStockPort $stock,
        private CategoryReader $categories,
    ) {
    }

    public function create(ProductDraft $draft, string $actorId): void
    {
        $this->assertTaxonomy($draft);

        $brand = $this->canonicalBrandName($draft->brand, $actorId);

        try {
            $this->connection->executeStatement(
                'INSERT INTO storefront_product_view (
                    id, slug, name, brand, ref, supplier_reference, category_slug, subcategory, price_minor,
                    old_price_minor, currency, stock, material, dims, image_path, rating, reviews, is_new, is_best,
                    headrest, description, variants, published, vat_rate, sale_price_ttc_minor
                ) VALUES (
                    :id, :slug, :name, :brand, :ref, :supplier_reference, :category, :sub, :price,
                    :old_price, \'TND\', :stock, :material, :dims, :image, 0, 0, :is_new, :is_best,
                    :headrest, :description, :variants, false, :vat_rate, :sale_price_ttc_minor
                )',
                ['id' => Uuid::v7()->toRfc4122()] + $this->parameters($draft, $brand),
                $this->types(),
            );
        } catch (UniqueConstraintViolationException) {
            throw ProductAlreadyExistsException::forSlug($draft->slug);
        }

        // The view row exists first, so the cache refresh finds it; `stockQuantity` is the
        // available quantity and the Inventory port derives on_hand from it (R17).
        if (null !== $draft->stockQuantity) {
            $this->stock->setAvailable($draft->slug, $draft->stockQuantity);
        }

        // The store owns the audit and the create event of the approval record (R2).
        $this->submissions->create(CatalogSubmissionKind::Product, $draft->slug, $actorId);
    }

    public function update(string $slug, ProductDraft $draft, string $actorId, bool $actorIsSuperAdmin): void
    {
        $this->assertTaxonomy($draft);

        $brand = $this->canonicalBrandName($draft->brand, $actorId);

        $this->connection->executeStatement(
            'UPDATE storefront_product_view SET
                name = :name, brand = :brand, ref = :ref, supplier_reference = :supplier_reference,
                category_slug = :category, subcategory = :sub, price_minor = :price,
                old_price_minor = :old_price, stock = :stock, material = :material,
                dims = :dims, image_path = :image, is_new = :is_new, is_best = :is_best, headrest = :headrest,
                description = :description, variants = :variants,
                vat_rate = :vat_rate, sale_price_ttc_minor = :sale_price_ttc_minor
              WHERE slug = :slug',
            $this->parameters($draft, $brand),
            $this->types(),
        );

        if (null !== $draft->stockQuantity) {
            // The entered quantity is available, so an existing reservation is preserved.
            $this->stock->setAvailable($slug, $draft->stockQuantity);
        }

        $this->audit->record(
            'CATALOG_PRODUCT_UPDATED',
            CatalogSubmissionKind::Product->auditTarget(),
            $slug,
            $actorId,
        );
    }

    public function delete(string $slug, string $actorId): void
    {
        $deleted = $this->connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $slug]);
        if (0 === $deleted) {
            throw ProductNotFoundException::forSlug($slug);
        }

        // The balance row and the view row are one record: neither may outlive the other.
        $this->stock->remove($slug);

        // Slug reuse must not inherit a previous approval (R11) — the handler removes the
        // submission through CatalogSubmissionDeleter right after this write.
        $this->audit->record(
            'CATALOG_PRODUCT_DELETED',
            CatalogSubmissionKind::Product->auditTarget(),
            $slug,
            $actorId,
        );
    }

    /**
     * Reuses an existing brand whose name differs only by case (so a product never creates a
     * case-variant duplicate of a brand the admin already registered); otherwise returns the
     * typed name unchanged. A brand the product carries but that was never registered is created
     * with its submission, approved at once like a Marques create.
     */
    private function canonicalBrandName(string $brand, string $actorId): string
    {
        $existing = $this->connection->fetchOne(
            'SELECT name FROM storefront_brand WHERE lower(name) = lower(:name)',
            ['name' => $brand],
        );

        if (is_string($existing)) {
            return $existing;
        }

        $this->connection->executeStatement('INSERT INTO storefront_brand (name) VALUES (:name)', ['name' => $brand]);

        $this->submissions->createApproved(CatalogSubmissionKind::Brand, $brand, $actorId);

        return $brand;
    }

    /**
     * The taxonomy guard on the update path: a product cannot hang off an unknown category or
     * a subcategory the category's tree does not carry (ECO-006 companion to the tree guard).
     */
    private function assertTaxonomy(ProductDraft $draft): void
    {
        $category = $this->categories->find($draft->category);
        if (null === $category) {
            throw InvalidProductTaxonomyException::unknownCategory($draft->category);
        }

        if ('' !== $draft->sub && [] !== $category->subcategories()) {
            $labels = SubcategoryTree::labels($category->subcategories());
            if (!in_array($draft->sub, $labels, true)) {
                throw InvalidProductTaxonomyException::unknownSubcategory($draft->sub, $draft->category);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(ProductDraft $draft, string $brand): array
    {
        return [
            'slug' => $draft->slug,
            'name' => $draft->name,
            'brand' => $brand,
            'ref' => $draft->ref,
            'supplier_reference' => $draft->supplierReference,
            'category' => $draft->category,
            'sub' => $draft->sub,
            'price' => $draft->priceMinor,
            'old_price' => $draft->oldPriceMinor,
            'stock' => $draft->stock,
            'material' => $draft->material,
            'dims' => $draft->dims,
            'image' => $draft->image,
            'is_new' => $draft->isNew,
            'is_best' => $draft->best,
            'headrest' => $draft->headrest,
            'description' => $draft->description,
            'variants' => json_encode($draft->variants, JSON_THROW_ON_ERROR),
            'vat_rate' => $draft->vatRate,
            'sale_price_ttc_minor' => $draft->salePriceTtcMinor,
        ];
    }

    /** @return array<string, ParameterType> */
    private function types(): array
    {
        return [
            'price' => ParameterType::INTEGER,
            'old_price' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
            'vat_rate' => ParameterType::STRING,
        ];
    }

    public function setPublished(string $slug, bool $published): void
    {
        $this->connection->executeStatement(
            'UPDATE storefront_product_view SET published = :published WHERE slug = :slug',
            ['published' => $published, 'slug' => $slug],
            ['published' => ParameterType::BOOLEAN],
        );
    }

    public function store(string $id, string $slug, string $authorName, int $rating, string $comment): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_product_review (id, product_slug, author_name, rating, comment)
             VALUES (:id, :slug, :author, :rating, :comment)',
            [
                'id' => $id,
                'slug' => $slug,
                'author' => $authorName,
                'rating' => max(1, min(5, $rating)),
                'comment' => $comment,
            ],
        );
    }

    public function orderCount(string $slug): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT order_id) FROM ordering_order_lines WHERE product_slug = :slug',
            ['slug' => $slug],
        );
    }
}

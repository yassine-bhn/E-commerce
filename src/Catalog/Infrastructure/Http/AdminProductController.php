<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Command\SetProductPublication;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Port\CatalogSubmissionCreator;
use App\Catalog\Application\Port\CatalogSubmissionOwnerEditor;
use App\Catalog\Application\Port\CatalogSubmissionReviewer;
use App\Catalog\Application\Query\GetCatalogSubmission;
use App\Catalog\Application\Service\SubcategoryTree;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductRepository;
use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use App\Identity\Infrastructure\Security\CustomerUser;
use App\Shared\Application\Port\InventoryStockPort;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * Admin product management (create / update / read / publication). Deletion lives in
 * {@see AdminCategoryController::deleteProduct()} (it protects order history).
 *
 * Prices are decimal DT on input (up to three decimals, e.g. `15.3` or `153894.32`) and integer
 * millimes in storage and in this API's responses; TND's smallest unit is the millime.
 *
 * spec-run-8 R1/R10/R15/R17/R35 — every writer may create a product, but a creation is always a
 * `PENDING_APPROVAL` submission owned by its creator; only the Super Admin reads or rewrites
 * another Admin's record, and only the Super Admin decides approval or publication. A Super Admin
 * rewrites as the reviewer, so his own save is the approval instead of a new submission.
 */
#[Route('/api/v1/admin/catalog/products')]
#[IsGranted('ROLE_ADMIN')]
final class AdminProductController
{
    private const array STOCKS = ['in', 'order', 'pickup', 'out'];

    public function __construct(
        private Connection $connection,
        private StorefrontProductRepository $products,
        private StorefrontProductSearchIndexer $searchIndex,
        private SubmissionAccessPolicy $access,
        private MessengerQuery $queries,
        private MessageBusInterface $commandBus,
        private CatalogAuditPort $audit,
        private InventoryStockPort $stock,
        private CatalogSubmissionCreator $submissions,
        private CatalogSubmissionReviewer $reviews,
        private CatalogSubmissionOwnerEditor $ownerEdits,
    ) {
    }

    #[Route('', name: 'admin_catalog_create_product', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $product = $this->validate($body, null);
        if (is_string($product)) {
            return new JsonResponse(['error' => $product], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // A product may carry a brand the admin never registered: register it as an approved brand
        // so the Marques screen lists it, the storefront filters offer it and renames can follow it.
        $product['brand'] = $this->canonicalBrandName($product['brand']);

        try {
            $this->connection->transactional(function () use ($product, $admin): void {
                $this->registerBrandIfMissing($product['brand'], $admin);

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
                    ['id' => Uuid::v7()->toRfc4122()] + $this->parameters($product),
                    $this->types(),
                );

                // The view row exists first, so the cache refresh finds it; `stockQuantity` is the
                // available quantity and the Inventory port derives on_hand from it (R17).
                if (null !== $product['stockQuantity']) {
                    $this->stock->setAvailable($product['slug'], $product['stockQuantity']);
                }

                // The store owns the audit and the create event of the approval record (R2).
                $this->submissions->create(
                    CatalogSubmissionKind::Product,
                    $product['slug'],
                    $admin->getId(),
                );
            });
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['error' => 'A product with this slug already exists.'], Response::HTTP_CONFLICT);
        }

        // Best-effort: a pending submission is not public, so this removes any stale document.
        $this->searchIndex->sync($product['slug']);

        return new JsonResponse($this->adminPayload($product['slug']), Response::HTTP_CREATED);
    }

    /**
     * R15/R17/R25 — the Super Admin reads one product whatever its state, with its approval
     * metadata; a regular Admin reads their own submission and nobody else's.
     */
    #[Route('/{slug}', name: 'admin_catalog_show_product', methods: ['GET'])]
    public function show(string $slug, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $payload = $this->adminPayload($slug, $admin);

        return null === $payload
            ? new JsonResponse(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND)
            : new JsonResponse($payload);
    }

    #[Route('/{slug}', name: 'admin_catalog_update_product', methods: ['PUT'])]
    public function update(string $slug, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $product = $this->validate($body, $slug);
        if (is_string($product)) {
            return new JsonResponse(['error' => $product], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // See create(): an unregistered brand typed while editing a product is registered too.
        $product['brand'] = $this->canonicalBrandName($product['brand']);

        try {
            $this->connection->transactional(function () use ($slug, $product, $admin): void {
                $this->access->authorize(
                    CatalogSubmissionKind::Product,
                    $slug,
                    $admin->getId(),
                    $this->isSuperAdmin($admin),
                );

                $this->registerBrandIfMissing($product['brand'], $admin);

                $this->connection->executeStatement(
                    'UPDATE storefront_product_view SET
                        name = :name, brand = :brand, ref = :ref, supplier_reference = :supplier_reference,
                        category_slug = :category, subcategory = :sub, price_minor = :price,
                        old_price_minor = :old_price, stock = :stock, material = :material,
                        dims = :dims, image_path = :image, is_new = :is_new, is_best = :is_best, headrest = :headrest,
                        description = :description, variants = :variants,
                        vat_rate = :vat_rate, sale_price_ttc_minor = :sale_price_ttc_minor
                      WHERE slug = :slug',
                    $this->parameters($product),
                    $this->types(),
                );

                if (null !== $product['stockQuantity']) {
                    // The entered quantity is available, so an existing reservation is preserved.
                    $this->stock->setAvailable($slug, $product['stockQuantity']);
                }

                $this->audit->record(
                    'CATALOG_PRODUCT_UPDATED',
                    CatalogSubmissionKind::Product->auditTarget(),
                    $slug,
                    $admin->getId(),
                );

                // The reviewer validates by writing: his own save is never queued for himself (R7).
                if ($this->isSuperAdmin($admin)) {
                    $this->reviews->reviewerSave(CatalogSubmissionKind::Product, $slug, $admin->getId());
                } else {
                    $this->ownerEdits->ownerModified(CatalogSubmissionKind::Product, $slug);
                }
            });
        } catch (\DomainException $error) {
            return $this->domainFailure($error);
        }

        // Best-effort: the index follows what the save left publicly readable (ECO-008).
        $this->searchIndex->sync($slug);

        return new JsonResponse($this->adminPayload($slug, $admin));
    }

    /**
     * R28/R29 — publication is its own explicit decision, never a side effect of approval.
     */
    #[Route('/{slug}/publication', name: 'admin_catalog_product_publication', methods: ['PATCH'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function setPublication(string $slug, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body || !is_bool($body['published'] ?? null)) {
            return new JsonResponse(['error' => 'published must be a boolean.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->commandBus->dispatch(new SetProductPublication($slug, $body['published'], $admin->getId()));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();

            return $previous instanceof \DomainException
                ? $this->domainFailure($previous)
                : throw $e;
        } catch (\DomainException $error) {
            return $this->domainFailure($error);
        }

        $this->searchIndex->sync($slug);

        return new JsonResponse($this->adminPayload($slug, $admin));
    }

    /**
     * The record as the admin surface sees it: the content plus its approval metadata, or null
     * when the caller may not read it (missing and foreign are the same answer, R5).
     *
     * @return array<string, mixed>|null
     */
    private function adminPayload(string $slug, ?CustomerUser $admin = null): ?array
    {
        $content = $this->products->adminBySlug($slug);
        if (null === $content) {
            return null;
        }

        $payload = $content->toAdminApi();
        if (null === $admin) {
            return $payload;
        }

        /** @var \App\Catalog\Infrastructure\ReadModel\CatalogSubmissionView|null $submission */
        $submission = $this->queries->dispatch(new GetCatalogSubmission(
            CatalogSubmissionKind::Product,
            $slug,
            $admin->getId(),
            $this->isSuperAdmin($admin),
        ));

        if (null === $submission) {
            return null;
        }

        $payload['approval'] = $submission->toApi();

        return $payload;
    }

    private function isSuperAdmin(CustomerUser $admin): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $admin->getRoles(), true);
    }

    /**
     * Reuses an existing brand whose name differs only by case (so a product never creates a
     * case-variant duplicate of a brand the admin already registered); otherwise returns the
     * typed name unchanged.
     */
    private function canonicalBrandName(string $brand): string
    {
        $existing = $this->connection->fetchOne(
            'SELECT name FROM storefront_brand WHERE lower(name) = lower(:name)',
            ['name' => $brand],
        );

        return is_string($existing) ? $existing : $brand;
    }

    /**
     * Registers a brand the product carries but that was never created through the Marques screen:
     * the row, its submission and its approval are written in the same transaction as the product,
     * so the brand is immediately usable, visible in the filters and renameable.
     */
    private function registerBrandIfMissing(string $brand, CustomerUser $admin): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM storefront_brand WHERE lower(name) = lower(:name)',
            ['name' => $brand],
        );
        if (false !== $exists) {
            return;
        }

        $this->connection->executeStatement('INSERT INTO storefront_brand (name) VALUES (:name)', ['name' => $brand]);

        $this->submissions->create(CatalogSubmissionKind::Brand, $brand, $admin->getId());
        $this->reviews->reviewerSave(CatalogSubmissionKind::Brand, $brand, $admin->getId());
    }

    private function domainFailure(\DomainException $error): JsonResponse
    {
        if ($error instanceof CatalogSubmissionNotFoundException) {
            return new JsonResponse(['error' => 'Product not found.', 'message' => $error->getMessage()], Response::HTTP_NOT_FOUND);
        }

        if ($error instanceof CatalogSubmissionNotOwnedException) {
            return new JsonResponse(['error' => 'Forbidden', 'message' => $error->getMessage()], Response::HTTP_FORBIDDEN);
        }

        if ($error instanceof ApprovalTransitionRefusedException) {
            return new JsonResponse(['error' => 'Approval Transition Refused', 'message' => $error->getMessage()], Response::HTTP_CONFLICT);
        }

        throw $error;
    }

    /** @return array<string, mixed>|null */
    private function decode(Request $request): ?array
    {
        $body = json_decode($request->getContent(), true);

        /** @var array<string, mixed>|null $result */
        $result = is_array($body) ? $body : null;

        return $result;
    }

    /**
     * Validates the payload and returns the clean product, or a message describing the first problem.
     *
     * @param array<string, mixed> $body
     *
     * @return array{slug: string, name: string, brand: string, ref: string, supplierReference: string|null, category: string, sub: string, price: int, oldPrice: int|null, stock: string, stockQuantity: int|null, material: string, dims: string, image: string, isNew: bool, best: bool, headrest: bool, description: string, variants: list<array{label: string, price: int}>, vatRate: string|null, salePriceTtc: int|null}|string
     */
    private function validate(array $body, ?string $fixedSlug): array|string
    {
        $name = $this->text($body['name'] ?? null);
        if ('' === $name || mb_strlen($name) > 255) {
            return 'The name is required (255 characters max).';
        }

        $slug = $fixedSlug ?? $this->text($body['slug'] ?? null);
        if ('' === $slug) {
            $slug = mb_substr(strtolower((new AsciiSlugger('fr'))->slug($name)->toString()), 0, 128);
        }
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 128) {
            return 'The slug may only contain lowercase letters, digits and dashes (128 characters max).';
        }

        $brand = $this->text($body['brand'] ?? null);
        if ('' === $brand || mb_strlen($brand) > 64) {
            return 'The brand is required (64 characters max).';
        }

        $ref = $this->text($body['ref'] ?? null);
        if ('' === $ref || mb_strlen($ref) > 64) {
            return 'The reference is required (64 characters max).';
        }

        // Optional supplier reference: the same typed contract as `ref`, but blank means "unset".
        $rawSupplierReference = $body['supplierReference'] ?? null;
        if (null !== $rawSupplierReference && !is_string($rawSupplierReference)) {
            return 'The supplier reference must be a string or null (64 characters max).';
        }
        $supplierReference = is_string($rawSupplierReference) ? trim($rawSupplierReference) : null;
        if ('' === $supplierReference) {
            $supplierReference = null;
        }
        if (null !== $supplierReference && mb_strlen($supplierReference) > 64) {
            return 'The supplier reference must be a string or null (64 characters max).';
        }

        $category = $this->text($body['category'] ?? null);
        $sub = $this->text($body['sub'] ?? null);
        if (mb_strlen($sub) > 128) {
            return 'The subcategory is too long (128 characters max).';
        }
        $taxonomyError = $this->checkTaxonomy($category, $sub);
        if (null !== $taxonomyError) {
            return $taxonomyError;
        }

        $variants = $this->variants($body['variants'] ?? []);
        if (is_string($variants)) {
            return $variants;
        }

        if ([] !== $variants) {
            // The storefront shows "from <lowest variant price>": keep the base price in sync.
            $price = min(array_column($variants, 'price'));
        } else {
            $price = $this->decimalMinor($body['price'] ?? null);
            if (null === $price) {
                return 'The price must be a non-negative decimal DT amount with up to 3 decimals.';
            }
        }

        $oldPrice = null;
        $rawOldPrice = $body['oldPrice'] ?? null;
        if (null !== $rawOldPrice && '' !== $rawOldPrice) {
            $oldPrice = $this->decimalMinor($rawOldPrice);
            if (null === $oldPrice || $oldPrice <= $price) {
                return 'The old price must be a decimal DT amount with up to 3 decimals, greater than the price.';
            }
        }

        $stock = $this->text($body['stock'] ?? null);
        if (!in_array($stock, self::STOCKS, true)) {
            return sprintf('The stock must be one of: %s.', implode(', ', self::STOCKS));
        }

        $stockQuantity = null;
        if (isset($body['stockQuantity'])) {
            if (!is_int($body['stockQuantity']) || $body['stockQuantity'] < 0) {
                return 'The stock quantity must be a non-negative integer.';
            }
            $stockQuantity = $body['stockQuantity'];
        }

        $material = $this->text($body['material'] ?? null);
        $dims = $this->text($body['dims'] ?? null);
        if (mb_strlen($material) > 128 || mb_strlen($dims) > 128) {
            return 'The material and dimensions are limited to 128 characters.';
        }

        $image = $this->text($body['image'] ?? null);
        if ('' === $image || strlen($image) > 255 || 1 !== preg_match('#^(/[A-Za-z0-9._/-]+|https?://\S+)$#', $image)) {
            return 'The image must be a site path (/photo.jpg) or an http(s) URL (255 characters max).';
        }

        $description = $this->text($body['description'] ?? null);
        if (mb_strlen($description) > 5000) {
            return 'The description is limited to 5000 characters.';
        }

        // vatRate — decimal percentage (up to 2 decimals), or null when blank/absent.
        $rawVatRate = $body['vatRate'] ?? null;
        $vatRate = null;
        if (null !== $rawVatRate && !(is_string($rawVatRate) && '' === trim($rawVatRate))) {
            if (is_bool($rawVatRate) || (!is_string($rawVatRate) && !is_int($rawVatRate) && !is_float($rawVatRate))) {
                return 'The VAT rate must be a decimal percentage with up to 2 decimals, or null.';
            }
            if (is_float($rawVatRate) && !is_finite($rawVatRate)) {
                return 'The VAT rate must be a decimal percentage with up to 2 decimals, or null.';
            }
            $vatRateString = is_string($rawVatRate) ? $rawVatRate : (string) $rawVatRate;
            if (1 !== preg_match('/^-?\d+(\.\d{1,2})?$/', $vatRateString)) {
                return 'The VAT rate must be a decimal percentage with up to 2 decimals, or null.';
            }
            $vatRate = $vatRateString;
        }

        // salePriceTtc — decimal DT amount parsed with Money::fromDecimalString, or null when blank/absent.
        $rawSalePriceTtc = $body['salePriceTtc'] ?? null;
        $salePriceTtc = null;
        if (null !== $rawSalePriceTtc && !(is_string($rawSalePriceTtc) && '' === trim($rawSalePriceTtc))) {
            if (is_bool($rawSalePriceTtc) || (!is_string($rawSalePriceTtc) && !is_int($rawSalePriceTtc) && !is_float($rawSalePriceTtc))) {
                return 'The sale price (TTC) must be a non-negative decimal DT amount with up to 3 decimals, or null.';
            }
            if (is_float($rawSalePriceTtc) && !is_finite($rawSalePriceTtc)) {
                return 'The sale price (TTC) must be a non-negative decimal DT amount with up to 3 decimals, or null.';
            }
            $salePriceTtcCandidate = is_string($rawSalePriceTtc) ? trim($rawSalePriceTtc) : (string) $rawSalePriceTtc;
            if ('' === $salePriceTtcCandidate) {
                $salePriceTtc = null;
            } else {
                try {
                    $salePriceTtc = Money::fromDecimalString($salePriceTtcCandidate, Currency::TND)->minorUnits();
                } catch (\InvalidArgumentException) {
                    return 'The sale price (TTC) must be a non-negative decimal DT amount with up to 3 decimals, or null.';
                }
            }
        }

        return [
            'slug' => $slug,
            'name' => $name,
            'brand' => $brand,
            'ref' => $ref,
            'supplierReference' => $supplierReference,
            'category' => $category,
            'sub' => $sub,
            'price' => $price,
            'oldPrice' => $oldPrice,
            'stock' => $stock,
            'stockQuantity' => $stockQuantity,
            'material' => $material,
            'dims' => $dims,
            'image' => $image,
            'isNew' => true === ($body['isNew'] ?? false),
            'best' => true === ($body['best'] ?? false),
            'headrest' => true === ($body['headrest'] ?? false),
            'description' => $description,
            'variants' => $variants,
            'vatRate' => $vatRate,
            'salePriceTtc' => $salePriceTtc,
        ];
    }

    private function checkTaxonomy(string $category, string $sub): ?string
    {
        $raw = $this->connection->fetchOne(
            'SELECT subcategories FROM storefront_category WHERE slug = :slug',
            ['slug' => $category],
        );
        if (false === $raw) {
            return sprintf('Unknown category "%s".', $category);
        }

        $labels = SubcategoryTree::labels(json_decode((string) $raw, true));
        if ('' !== $sub && [] !== $labels && !in_array($sub, $labels, true)) {
            return sprintf('Unknown subcategory "%s" for category "%s".', $sub, $category);
        }

        return null;
    }

    /** @return list<array{label: string, price: int}>|string */
    private function variants(mixed $raw): array|string
    {
        if (!is_array($raw)) {
            return 'The variants must be a list.';
        }

        $variants = [];
        $seen = [];
        foreach (array_values($raw) as $index => $variant) {
            $label = is_array($variant) ? $this->text($variant['label'] ?? null) : '';
            $price = is_array($variant) ? $this->decimalMinor($variant['price'] ?? null) : null;

            if ('' === $label || mb_strlen($label) > 128 || null === $price) {
                return sprintf('Variant #%d needs a label and a non-negative decimal DT price with up to 3 decimals.', $index + 1);
            }
            if (isset($seen[$label])) {
                return sprintf('The variant label "%s" is used twice.', $label);
            }

            $seen[$label] = true;
            $variants[] = ['label' => $label, 'price' => $price];
        }

        return $variants;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * A decimal DT amount (string, integer or JSON number) parsed exactly to millimes. JSON floats
     * are only converted to PHP's shortest round-trip decimal representation, never used in
     * arithmetic. Returns null for malformed input, negative values or more than three decimals.
     */
    private function decimalMinor(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        try {
            return Money::fromDecimalString($value, Currency::TND)->minorUnits();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array{slug: string, name: string, brand: string, ref: string, supplierReference: string|null, category: string, sub: string, price: int, oldPrice: int|null, stock: string, stockQuantity: int|null, material: string, dims: string, image: string, isNew: bool, best: bool, headrest: bool, description: string, variants: list<array{label: string, price: int}>, vatRate: string|null, salePriceTtc: int|null} $product
     *
     * @return array<string, mixed>
     */
    private function parameters(array $product): array
    {
        return [
            'slug' => $product['slug'],
            'name' => $product['name'],
            'brand' => $product['brand'],
            'ref' => $product['ref'],
            'supplier_reference' => $product['supplierReference'],
            'category' => $product['category'],
            'sub' => $product['sub'],
            'price' => $product['price'],
            'old_price' => $product['oldPrice'],
            'stock' => $product['stock'],
            'material' => $product['material'],
            'dims' => $product['dims'],
            'image' => $product['image'],
            'is_new' => $product['isNew'],
            'is_best' => $product['best'],
            'headrest' => $product['headrest'],
            'description' => $product['description'],
            'variants' => json_encode($product['variants'], JSON_THROW_ON_ERROR),
            'vat_rate' => $product['vatRate'],
            'sale_price_ttc_minor' => $product['salePriceTtc'],
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
}

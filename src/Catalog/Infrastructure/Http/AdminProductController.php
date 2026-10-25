<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Command\SetProductPublication;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Query\GetCatalogSubmission;
use App\Catalog\Application\Service\SubcategoryTree;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductRepository;
use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use App\Identity\Infrastructure\Security\CustomerUser;
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
 * Prices are whole dinars, like every other product of the storefront.
 *
 * spec-run-8 R1/R10/R15/R17/R35 — every writer may create a product, but a creation is always a
 * `PENDING_APPROVAL` submission owned by its creator; only the Super Admin reads or rewrites
 * another Admin's record, and only the Super Admin decides approval or publication.
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

        try {
            $this->connection->transactional(function () use ($product, $admin): void {
                $this->connection->executeStatement(
                    'INSERT INTO storefront_product_view (
                        id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                        stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants,
                        approval_status, submitted_by, submitted_at, published
                    ) VALUES (
                        :id, :slug, :name, :brand, :ref, :category, :sub, :price, :old_price, \'TND\',
                        :stock, :stock_quantity, :material, :dims, :image, 0, 0, :is_new, :is_best, :headrest, :description, :variants,
                        :approval, :submitted_by, :submitted_at, false
                    )',
                    ['id' => Uuid::v7()->toRfc4122(), 'approval' => ApprovalStatus::initial()->value,
                        'submitted_by' => $admin->getId(),
                        'submitted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
                    ] + $this->parameters($product),
                    $this->types(),
                );

                $this->audit->record(
                    'CATALOG_SUBMISSION_CREATED',
                    CatalogSubmissionKind::Product->auditTarget(),
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

        try {
            $this->connection->transactional(function () use ($slug, $product, $admin): void {
                $current = $this->access->authorize(
                    CatalogSubmissionKind::Product,
                    $slug,
                    $admin->getId(),
                    $this->isSuperAdmin($admin),
                );

                $next = $current->afterModification();
                $reopens = $next !== $current;

                $this->connection->executeStatement(
                    'UPDATE storefront_product_view SET
                        name = :name, brand = :brand, ref = :ref, category_slug = :category, subcategory = :sub,
                        price_minor = :price, old_price_minor = :old_price, stock = :stock,
                        stock_quantity = COALESCE(:stock_quantity, stock_quantity), material = :material,
                        dims = :dims, image_path = :image, is_new = :is_new, is_best = :is_best, headrest = :headrest,
                        description = :description, variants = :variants'
                    .($reopens ? ',
                        approval_status = :approval, reviewed_by = NULL, reviewed_at = NULL, rejection_reason = NULL' : '')
                    .' WHERE slug = :slug',
                    $this->parameters($product) + ($reopens ? ['approval' => $next->value] : []),
                    $this->types(),
                );

                $this->audit->record(
                    'CATALOG_PRODUCT_UPDATED',
                    CatalogSubmissionKind::Product->auditTarget(),
                    $slug,
                    $admin->getId(),
                );
            });
        } catch (\DomainException $error) {
            return $this->domainFailure($error);
        }

        // Best-effort: a record that just went back to review leaves the index (ECO-008).
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
     * @return array{slug: string, name: string, brand: string, ref: string, category: string, sub: string, price: int, oldPrice: int|null, stock: string, stockQuantity: int|null, material: string, dims: string, image: string, isNew: bool, best: bool, headrest: bool, description: string, variants: list<array{label: string, price: int}>}|string
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
            $price = $this->integer($body['price'] ?? null);
            if (null === $price || $price < 0) {
                return 'The price must be a non-negative integer (in dinars).';
            }
        }

        $oldPrice = null;
        $rawOldPrice = $body['oldPrice'] ?? null;
        if (null !== $rawOldPrice && '' !== $rawOldPrice) {
            $oldPrice = $this->integer($rawOldPrice);
            if (null === $oldPrice || $oldPrice <= $price) {
                return 'The old price must be an integer greater than the price.';
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

        return [
            'slug' => $slug,
            'name' => $name,
            'brand' => $brand,
            'ref' => $ref,
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
            $price = is_array($variant) ? $this->integer($variant['price'] ?? null) : null;

            if ('' === $label || mb_strlen($label) > 128 || null === $price || $price < 0) {
                return sprintf('Variant #%d needs a label and a non-negative integer price.', $index + 1);
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

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array{slug: string, name: string, brand: string, ref: string, category: string, sub: string, price: int, oldPrice: int|null, stock: string, stockQuantity: int|null, material: string, dims: string, image: string, isNew: bool, best: bool, headrest: bool, description: string, variants: list<array{label: string, price: int}>} $product
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
            'category' => $product['category'],
            'sub' => $product['sub'],
            'price' => $product['price'],
            'old_price' => $product['oldPrice'],
            'stock' => $product['stock'],
            'stock_quantity' => $product['stockQuantity'],
            'material' => $product['material'],
            'dims' => $product['dims'],
            'image' => $product['image'],
            'is_new' => $product['isNew'],
            'is_best' => $product['best'],
            'headrest' => $product['headrest'],
            'description' => $product['description'],
            'variants' => json_encode($product['variants'], JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string, ParameterType> */
    private function types(): array
    {
        return [
            'price' => ParameterType::INTEGER,
            'old_price' => ParameterType::INTEGER,
            'stock_quantity' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
        ];
    }
}

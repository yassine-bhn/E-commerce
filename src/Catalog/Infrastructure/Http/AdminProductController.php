<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Service\SubcategoryTree;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * Super-admin product management (create / update). Deletion lives in
 * {@see AdminCategoryController::deleteProduct()} (it protects order history).
 *
 * Prices are whole dinars, like every other product of the storefront.
 */
#[Route('/api/v1/admin/catalog/products')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class AdminProductController
{
    private const array STOCKS = ['in', 'order', 'pickup', 'out'];

    public function __construct(
        private Connection $connection,
        private StorefrontProductRepository $products,
    ) {
    }

    #[Route('', name: 'admin_catalog_create_product', methods: ['POST'])]
    public function create(Request $request): JsonResponse
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
            $this->connection->executeStatement(
                'INSERT INTO storefront_product_view (
                    id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                    stock, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants
                ) VALUES (
                    :id, :slug, :name, :brand, :ref, :category, :sub, :price, :old_price, \'TND\',
                    :stock, :material, :dims, :image, 0, 0, :is_new, :is_best, :headrest, :description, :variants
                )',
                ['id' => Uuid::v7()->toRfc4122()] + $this->parameters($product),
                $this->types(),
            );
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['error' => 'A product with this slug already exists.'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->products->bySlug($product['slug'])?->toApi(), Response::HTTP_CREATED);
    }

    #[Route('/{slug}', name: 'admin_catalog_update_product', methods: ['PUT'])]
    public function update(string $slug, Request $request): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $product = $this->validate($body, $slug);
        if (is_string($product)) {
            return new JsonResponse(['error' => $product], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $affected = $this->connection->executeStatement(
            'UPDATE storefront_product_view SET
                name = :name, brand = :brand, ref = :ref, category_slug = :category, subcategory = :sub,
                price_minor = :price, old_price_minor = :old_price, stock = :stock, material = :material,
                dims = :dims, image_path = :image, is_new = :is_new, is_best = :is_best, headrest = :headrest,
                description = :description, variants = :variants
             WHERE slug = :slug',
            $this->parameters($product),
            $this->types(),
        );

        if (0 === $affected) {
            return new JsonResponse(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->products->bySlug($slug)?->toApi());
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
     * @return array{slug: string, name: string, brand: string, ref: string, category: string, sub: string, price: int, oldPrice: int|null, stock: string, material: string, dims: string, image: string, isNew: bool, best: bool, headrest: bool, description: string, variants: list<array{label: string, price: int}>}|string
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
     * @param array{slug: string, name: string, brand: string, ref: string, category: string, sub: string, price: int, oldPrice: int|null, stock: string, material: string, dims: string, image: string, isNew: bool, best: bool, headrest: bool, description: string, variants: list<array{label: string, price: int}>} $product
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
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
        ];
    }
}

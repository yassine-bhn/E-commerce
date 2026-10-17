<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Service\CategoryHierarchyValidator;
use App\Catalog\Application\Service\ProductDeletionGuard;
use App\Catalog\Domain\Model\CategoryHierarchyCycleException;
use App\Catalog\Domain\Model\CategoryNotEmptyException;
use App\Catalog\Domain\Model\ProductHasOrdersCannotBeDeletedException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/admin/catalog')]
final class AdminCategoryController
{
    public function __construct(
        private CategoryHierarchyValidator $categoryValidator,
        private ProductDeletionGuard $productGuard,
        private Connection $connection,
    ) {
    }

    #[Route('/categories/validate-parent', name: 'admin_catalog_validate_parent', methods: ['POST'])]
    public function validateParent(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $categorySlug = trim((string) ($body['categorySlug'] ?? ''));
        $parentSlug = isset($body['parentSlug']) && '' !== trim((string) $body['parentSlug'])
            ? trim((string) $body['parentSlug'])
            : null;

        if ('' === $categorySlug) {
            return new JsonResponse(['error' => 'categorySlug is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->categoryValidator->validateParentAssignment($categorySlug, $parentSlug);
        } catch (CategoryHierarchyCycleException $e) {
            return new JsonResponse([
                'valid' => false,
                'error' => 'Cycle Detected',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['valid' => true, 'categorySlug' => $categorySlug, 'parentSlug' => $parentSlug]);
    }

    #[Route('/categories/{slug}', name: 'admin_catalog_delete_category', methods: ['DELETE'])]
    public function deleteCategory(string $slug): JsonResponse
    {
        try {
            $this->categoryValidator->ensureCanDeleteCategory($slug);
        } catch (CategoryNotEmptyException $e) {
            return new JsonResponse([
                'error' => 'Category Not Empty',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->connection->executeStatement(
            'DELETE FROM storefront_category WHERE slug = :slug',
            ['slug' => $slug],
        );

        return new JsonResponse(['success' => true, 'slug' => $slug, 'message' => 'Category deleted successfully']);
    }

    #[Route('/products/{slug}', name: 'admin_catalog_delete_product', methods: ['DELETE'])]
    public function deleteProduct(string $slug): JsonResponse
    {
        try {
            $this->productGuard->ensureCanDeleteProduct($slug);
        } catch (ProductHasOrdersCannotBeDeletedException $e) {
            return new JsonResponse([
                'error' => 'Product Has Historical Orders',
                'message' => $e->getMessage(),
                'advice' => 'Unpublish or hide the product instead of deleting it to preserve order history and accounting integrity.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->connection->executeStatement(
            'DELETE FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );

        return new JsonResponse(['success' => true, 'slug' => $slug, 'message' => 'Product deleted successfully']);
    }
}

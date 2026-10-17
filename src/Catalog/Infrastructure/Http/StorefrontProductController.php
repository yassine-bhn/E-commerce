<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Query\GetStorefrontProductBySlug;
use App\Catalog\Application\Query\ListStorefrontProducts;
use App\Catalog\Application\Query\SearchStorefrontProducts;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductView;
use App\Shared\Infrastructure\Http\MessengerQuery;
use App\Shared\Infrastructure\Http\RateLimitChecker;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/storefront/products')]
final class StorefrontProductController
{
    public function __construct(
        private MessengerQuery $queries,
        #[Target('catalog_search')]
        private RateLimiterFactoryInterface $searchLimiter,
        private RateLimitChecker $rateLimitChecker,
    ) {
    }

    #[Route('', name: 'storefront_products_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $category = $request->query->getString('category') ?: null;
        $sub = $request->query->getString('sub') ?: null;
        $promotions = $request->query->getBoolean('promotions');
        $brand = $request->query->getString('brand') ?: null;
        $isNew = $request->query->has('new') ? $request->query->getBoolean('new') : null;
        $isBest = $request->query->has('best') ? $request->query->getBoolean('best') : null;
        $limit = $request->query->has('limit') ? $request->query->getInt('limit') : null;
        $sort = $request->query->getString('sort') ?: null;

        /** @var list<StorefrontProductView> $products */
        $products = $this->queries->dispatch(new ListStorefrontProducts(
            $category,
            $sub,
            $promotions,
            $brand,
            $isNew,
            $isBest,
            $limit,
            $sort,
        ));

        return new JsonResponse([
            'items' => array_map(static fn (StorefrontProductView $p): array => $p->toApi(), $products),
        ]);
    }

    #[Route('/search', name: 'storefront_products_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        if ($limitResponse = $this->rateLimitChecker->check($this->searchLimiter, $request)) {
            return $limitResponse;
        }

        $q = $request->query->getString('q');
        /** @var list<StorefrontProductView> $products */
        $products = $this->queries->dispatch(new SearchStorefrontProducts($q));

        return new JsonResponse([
            'query' => $q,
            'items' => array_map(static fn (StorefrontProductView $p): array => $p->toApi(), $products),
        ]);
    }

    #[Route('/{slug}', name: 'storefront_products_show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        /** @var StorefrontProductView|null $product */
        $product = $this->queries->dispatch(new GetStorefrontProductBySlug($slug));
        if (null === $product) {
            return new JsonResponse(['error' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($product->toApi());
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Query\GetStorefrontProductBySlug;
use App\Catalog\Application\Query\ListStorefrontProducts;
use App\Catalog\Application\Query\SearchStorefrontProducts;
use App\Catalog\Application\Query\StorefrontProductFilters;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductPage;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductView;
use App\Shared\Infrastructure\Http\ListQueryParams;
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
        /** @var StorefrontProductPage $page */
        $page = $this->queries->dispatch(new ListStorefrontProducts(
            $this->filters($request),
            ListQueryParams::page($request, ListStorefrontProducts::DEFAULT_SIZE, ListStorefrontProducts::MAX_SIZE),
        ));

        return new JsonResponse($page->toArray(static fn (StorefrontProductView $p): array => $p->toApi()));
    }

    #[Route('/search', name: 'storefront_products_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        if ($limitResponse = $this->rateLimitChecker->check($this->searchLimiter, $request)) {
            return $limitResponse;
        }

        $q = ListQueryParams::text($request);
        if (null === $q) {
            return new JsonResponse(['error' => 'The q parameter is required.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var StorefrontProductPage $page */
        $page = $this->queries->dispatch(new SearchStorefrontProducts(
            $this->filters($request),
            ListQueryParams::page($request, SearchStorefrontProducts::DEFAULT_SIZE, SearchStorefrontProducts::MAX_SIZE),
        ));

        return new JsonResponse(
            ['query' => $q] + $page->toArray(static fn (StorefrontProductView $p): array => $p->toApi()),
        );
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

    private function filters(Request $request): StorefrontProductFilters
    {
        $stock = ListQueryParams::text($request, 'stock');

        return new StorefrontProductFilters(
            q: ListQueryParams::text($request),
            categorySlug: ListQueryParams::text($request, 'category'),
            subcategory: ListQueryParams::text($request, 'sub'),
            promotionsOnly: ListQueryParams::boolOrNull($request, 'promotions') ?? false,
            brand: ListQueryParams::text($request, 'brand'),
            isNew: ListQueryParams::boolOrNull($request, 'new'),
            isBest: ListQueryParams::boolOrNull($request, 'best'),
            priceMin: ListQueryParams::intOrNull($request, 'priceMin'),
            priceMax: ListQueryParams::intOrNull($request, 'priceMax'),
            material: ListQueryParams::text($request, 'material'),
            stock: in_array($stock, StorefrontProductFilters::STOCKS, true) ? $stock : null,
            sort: ListQueryParams::text($request, 'sort'),
        );
    }
}

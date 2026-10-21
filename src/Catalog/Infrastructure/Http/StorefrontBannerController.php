<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Query\GetBannerImages;
use App\Catalog\Infrastructure\ReadModel\BannerView;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * R21 — the storefront reads its banner without a token: `^/api/v1/storefront` is PUBLIC_ACCESS, so a
 * guest gets the same ordered slide list an admin sees. The answer is always `{images: […]}` with one
 * entry per slide in the stored order, and `{images: []}` when the admin has removed every slide —
 * a documented empty answer, never an error and never a fallback image (ADR-0008).
 */
#[Route('/api/v1/storefront/banner')]
final class StorefrontBannerController
{
    public function __construct(private MessengerQuery $queries)
    {
    }

    #[Route('', name: 'storefront_banner', methods: ['GET'])]
    public function show(#[CurrentUser] ?UserInterface $user = null): JsonResponse
    {
        /** @var BannerView $banner */
        $banner = $this->queries->dispatch(new GetBannerImages(array_values($user?->getRoles() ?? [])));

        return new JsonResponse($banner->toPublicApi());
    }
}

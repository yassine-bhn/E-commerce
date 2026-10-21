<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Command\BannerRemoveImage;
use App\Catalog\Application\Command\BannerReorderImages;
use App\Catalog\Application\Command\BannerReplaceImages;
use App\Catalog\Application\Query\GetBannerImages;
use App\Catalog\Application\Service\BannerRules;
use App\Catalog\Domain\Model\BannerImageNotFoundException;
use App\Catalog\Domain\Model\BannerRejectedException;
use App\Catalog\Infrastructure\ReadModel\BannerView;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * R18/R20 — the dashboard manages the single site banner as one ordered image set.
 *
 * Every mutation is one command on `command.bus` and every read is one query on `query.bus` (Q2/R25):
 * nothing here touches the database, and nothing here decides who may see what. `grow`, `shrink` and
 * `reorder` are the same three commands, so the stored set always equals what the admin sent.
 */
#[Route('/api/v1/admin/catalog/banner')]
#[IsGranted('ROLE_ADMIN')]
final class AdminBannerController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessengerQuery $queries,
        private BannerRules $rules,
    ) {
    }

    #[Route('', name: 'admin_catalog_get_banner', methods: ['GET'])]
    public function show(#[CurrentUser] UserInterface $admin): JsonResponse
    {
        return new JsonResponse($this->banner($admin)->toAdminApi());
    }

    #[Route('/images', name: 'admin_catalog_replace_banner_images', methods: ['PUT'])]
    public function replaceImages(Request $request, #[CurrentUser] UserInterface $admin): JsonResponse
    {
        try {
            $paths = $this->rules->imagePathsFrom(json_decode($request->getContent(), true));
        } catch (BannerRejectedException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($failure = $this->dispatch(new BannerReplaceImages($paths))) {
            return $failure;
        }

        return new JsonResponse($this->banner($admin)->toAdminApi());
    }

    #[Route('/images/order', name: 'admin_catalog_reorder_banner_images', methods: ['PATCH'])]
    public function reorderImages(Request $request, #[CurrentUser] UserInterface $admin): JsonResponse
    {
        try {
            $ids = $this->rules->imageIdsFrom(json_decode($request->getContent(), true));
        } catch (BannerRejectedException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($failure = $this->dispatch(new BannerReorderImages($ids))) {
            return $failure;
        }

        return new JsonResponse($this->banner($admin)->toAdminApi());
    }

    #[Route('/images/{imageId}', name: 'admin_catalog_remove_banner_image', methods: ['DELETE'])]
    public function removeImage(string $imageId, #[CurrentUser] UserInterface $admin): JsonResponse
    {
        if (!Uuid::isValid($imageId)) {
            return new JsonResponse(['error' => 'Invalid banner image UUID'], Response::HTTP_BAD_REQUEST);
        }

        if ($failure = $this->dispatch(new BannerRemoveImage($imageId))) {
            return $failure;
        }

        return new JsonResponse($this->banner($admin)->toAdminApi());
    }

    /**
     * ERR-002/ERR-003 — the domain decides, this edge only maps it: a broken banner rule is a 422, a
     * slide that is not part of the banner is a 404, anything else keeps propagating.
     */
    private function dispatch(object $command): ?JsonResponse
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $exception) {
            foreach ($exception->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof BannerImageNotFoundException) {
                    return new JsonResponse(['error' => $wrapped->getMessage()], Response::HTTP_NOT_FOUND);
                }

                if ($wrapped instanceof BannerRejectedException) {
                    return new JsonResponse(['error' => $wrapped->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            throw $exception;
        }

        return null;
    }

    private function banner(UserInterface $admin): BannerView
    {
        /** @var BannerView $view */
        $view = $this->queries->dispatch(new GetBannerImages(array_values($admin->getRoles())));

        return $view;
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\BannerRemoveImage;
use App\Catalog\Application\Port\BannerImageStore;
use App\Catalog\Application\Port\BannerSetReader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R18/R22 — the one command.bus handler that takes one slide out of the banner set (CMD-004).
 *
 * `BannerSet::remove()` is what decides whether the slide exists, so the guard cannot be skipped by
 * the next caller (AGG-001); removing it deletes the reference row only. Nothing in this handler — or
 * anywhere else in the banner code — opens `public/uploads/`, which is why a file outlives its slide
 * and why shrinking the set to its last removal is a legal state the public read answers with `[]`.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class BannerRemoveImageHandler
{
    public function __construct(
        private BannerSetReader $banners,
        private BannerImageStore $writes,
    ) {
    }

    public function __invoke(BannerRemoveImage $command): void
    {
        $this->banners->current()->remove($command->imageId);

        $this->writes->remove($command->imageId);
    }
}

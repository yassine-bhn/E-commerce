<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\BannerReorderImages;
use App\Catalog\Application\Port\BannerImageStore;
use App\Catalog\Application\Port\BannerSetReader;
use App\Catalog\Application\Service\BannerPayload;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R20 — the one command.bus handler that rewrites the slide sequence (CMD-004).
 *
 * The payload shape is validated first (ERR-001), then the aggregate decides that a reorder must
 * name every slide exactly once and produces the dense sequence; this handler writes it inside
 * the single transaction the middleware opened, so the banner is never observed half-dragged.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class BannerReorderImagesHandler
{
    public function __construct(
        private BannerPayload $payload,
        private BannerSetReader $banners,
        private BannerImageStore $writes,
    ) {
    }

    public function __invoke(BannerReorderImages $command): void
    {
        $set = $this->banners->current();
        $set->reorder($this->payload->imageIdsFrom($command->body));

        $this->writes->reorder($set);
    }
}

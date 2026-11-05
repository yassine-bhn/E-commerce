<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\BannerReorderImages;
use App\Catalog\Application\Port\BannerImageStore;
use App\Catalog\Application\Port\BannerSetReader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R20 — the one command.bus handler that rewrites the slide sequence (CMD-004).
 *
 * The aggregate decides that a reorder must name every slide exactly once and produces the dense
 * sequence; this handler writes it inside the single transaction the middleware opened, so the banner
 * is never observed half-dragged. At most ten statements run here, and the bounded set is the point:
 * a stored slide keeps its identity, only `sort_order` moves.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class BannerReorderImagesHandler
{
    public function __construct(
        private BannerSetReader $banners,
        private BannerImageStore $writes,
    ) {
    }

    public function __invoke(BannerReorderImages $command): void
    {
        $set = $this->banners->current();
        $set->reorder($command->imageIds);

        $this->writes->reorder($set);
    }
}

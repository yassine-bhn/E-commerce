<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\BannerReorderImages;
use App\Catalog\Application\Service\BannerRules;
use App\Catalog\Domain\Model\BannerSet;
use Doctrine\DBAL\Connection;
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
        private BannerRules $rules,
        private Connection $connection,
    ) {
    }

    public function __invoke(BannerReorderImages $command): void
    {
        $set = $this->rules->currentSet();
        $set->reorder($command->imageIds);

        foreach ($set->images() as $image) {
            $this->connection->executeStatement(
                'UPDATE storefront_banner_image SET sort_order = :sort_order WHERE id = :id AND banner_id = :banner',
                [
                    'sort_order' => $image->sortOrder,
                    'id' => (string) $image->id,
                    'banner' => BannerSet::SITE_BANNER_ID,
                ],
            );
        }

        $this->connection->executeStatement(
            'UPDATE storefront_banner SET updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => BannerSet::SITE_BANNER_ID],
        );
    }
}

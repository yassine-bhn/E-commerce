<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\BannerReplaceImages;
use App\Catalog\Domain\Model\BannerSet;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * R18/R20 — the one command.bus handler that replaces the banner's ordered slide set (CMD-004).
 *
 * The aggregate is built first, so a 0-image or 11-image selection, a duplicate slide or a reference
 * that is not a dashboard upload throws before a single statement runs: a refused write persists
 * nothing. Everything after that runs inside the one transaction the `doctrine_transaction` middleware
 * opened for this command (CMD-005).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class BannerReplaceImagesHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(BannerReplaceImages $command): void
    {
        $set = BannerSet::fromPaths($command->imagePaths);

        $this->connection->executeStatement(
            'DELETE FROM storefront_banner_image WHERE banner_id = :banner',
            ['banner' => BannerSet::SITE_BANNER_ID],
        );

        foreach ($set->images() as $image) {
            $this->connection->executeStatement(
                'INSERT INTO storefront_banner_image (id, banner_id, image_path, sort_order)
                 VALUES (:id, :banner, :image_path, :sort_order)',
                [
                    'id' => Uuid::v7()->toRfc4122(),
                    'banner' => BannerSet::SITE_BANNER_ID,
                    'image_path' => $image->path,
                    'sort_order' => $image->sortOrder,
                ],
            );
        }

        $this->connection->executeStatement(
            'UPDATE storefront_banner SET updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => BannerSet::SITE_BANNER_ID],
        );
    }
}

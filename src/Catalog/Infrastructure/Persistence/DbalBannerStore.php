<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Application\Port\BannerImageStore;
use App\Catalog\Application\Port\BannerSetReader;
use App\Catalog\Domain\Model\BannerImage;
use App\Catalog\Domain\Model\BannerSet;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DIP-003 — the banner adapter: the stored-set read the handlers used to reach through
 * BannerRules, and the three slide writes, moved verbatim.
 */
final class DbalBannerStore implements BannerSetReader, BannerImageStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function current(): BannerSet
    {
        /** @var list<array{id: string, image_path: string, sort_order: string|int}> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, image_path, sort_order
            FROM storefront_banner_image
            WHERE banner_id = :banner
            ORDER BY sort_order ASC, created_at ASC, id ASC
            SQL, ['banner' => BannerSet::SITE_BANNER_ID]);

        $images = [];
        foreach ($rows as $row) {
            $images[] = BannerImage::restore($row['id'], $row['image_path'], (int) $row['sort_order']);
        }

        return BannerSet::reconstitute($images);
    }

    public function replace(BannerSet $set): void
    {
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

        $this->touchBanner();
    }

    public function reorder(BannerSet $set): void
    {
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

        $this->touchBanner();
    }

    public function remove(string $imageId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM storefront_banner_image WHERE id = :id AND banner_id = :banner',
            ['id' => $imageId, 'banner' => BannerSet::SITE_BANNER_ID],
        );

        $this->touchBanner();
    }

    private function touchBanner(): void
    {
        $this->connection->executeStatement(
            'UPDATE storefront_banner SET updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => BannerSet::SITE_BANNER_ID],
        );
    }
}

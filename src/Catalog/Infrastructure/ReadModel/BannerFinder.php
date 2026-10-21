<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\GetBannerImages;
use App\Catalog\Domain\Model\BannerSet;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * QRY-003 — the banner read is DBAL only, and it reads the same two tables the write model owns: ADR-0008
 * records that this model needs no projection and no outbox subscriber, so these rows are the read model.
 *
 * R20 — the ORDER BY is explicit and total: `sort_order`, then `created_at`, then `id`. `sort_order` is
 * deliberately not unique, so the `id` tie-break is what makes two concurrent admin writes that collide
 * still resolve to one stable sequence. No read of this table is ever an unqualified SELECT.
 */
final class BannerFinder
{
    /** Roles allowed to address a slide by its identity (assumption 12: content management is ROLE_ADMIN). */
    private const array MANAGE_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    private const string ORDERED_SLIDES = <<<'SQL'
        SELECT id, image_path, sort_order, created_at
        FROM storefront_banner_image
        WHERE banner_id = :banner
        ORDER BY sort_order ASC, created_at ASC, id ASC
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'query.bus')]
    public function handleGetBannerImages(GetBannerImages $query): BannerView
    {
        $withIdentities = [] !== array_intersect(self::MANAGE_ROLES, $query->requesterRoles);

        /** @var list<array{id: string, image_path: string, sort_order: string|int, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(self::ORDERED_SLIDES, ['banner' => BannerSet::SITE_BANNER_ID]);

        $images = [];
        foreach ($rows as $row) {
            $images[] = new BannerImageView(
                $withIdentities ? (string) $row['id'] : null,
                (string) $row['image_path'],
                (int) $row['sort_order'],
                $withIdentities ? (string) $row['created_at'] : null,
            );
        }

        return new BannerView($images);
    }
}

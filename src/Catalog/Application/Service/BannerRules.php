<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Domain\Model\BannerImage;
use App\Catalog\Domain\Model\BannerRejectedException;
use App\Catalog\Domain\Model\BannerSet;
use Doctrine\DBAL\Connection;

/**
 * ERR-001 — the shape of the dashboard payload is settled here, at the edge, before a command reaches
 * `command.bus`; what the payload *means* (how many slides, which references, which order) is the
 * aggregate's business (AGG-001) and is enforced in `BannerSet`.
 *
 * The write-side load lives here too: a command handler that mutates the set has to read the slides it
 * did not create, and reads of the *stored* set by the read model are a separate DBAL finder.
 */
final class BannerRules
{
    public function __construct(private Connection $connection)
    {
    }

    /** The banner as it is stored right now, in the order every read returns it (R20). */
    public function currentSet(): BannerSet
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
            $images[] = BannerImage::restore((string) $row['id'], (string) $row['image_path'], (int) $row['sort_order']);
        }

        return BannerSet::reconstitute($images);
    }

    /**
     * The ordered image list of a replace-all write.
     *
     * @return list<string>
     */
    public function imagePathsFrom(mixed $body): array
    {
        return $this->stringListFrom($body, 'images', 'dashboard upload paths', 'image path strings');
    }

    /**
     * The ordered id list of a reorder write.
     *
     * @return list<string>
     */
    public function imageIdsFrom(mixed $body): array
    {
        return $this->stringListFrom($body, 'imageIds', 'banner image ids', 'banner image id strings');
    }

    /**
     * @param array<array-key, mixed>|null $body
     *
     * @return list<string>
     */
    private function stringListFrom(mixed $body, string $field, string $ofWhat, string $ofItems): array
    {
        if (!is_array($body)) {
            throw new BannerRejectedException('The request body must be a JSON object.');
        }

        $values = $body[$field] ?? null;
        if (!is_array($values) || !array_is_list($values)) {
            throw BannerRejectedException::fieldMustBeAList($field, $ofWhat);
        }

        $items = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw BannerRejectedException::fieldMustContainStrings($field, $ofItems);
            }

            $items[] = trim($value);
        }

        return $items;
    }
}
